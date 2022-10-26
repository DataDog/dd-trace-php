use crate::bindings::{
    datadog_php_profiling_get_profiling_context, zend_execute_data, zend_function, zend_string,
    ZEND_USER_FUNCTION,
};
use crate::{
    bindings, AgentEndpoint, RequestLocals, PHP_VERSION, PROFILER_NAME_STR, PROFILER_VERSION_STR,
};
use crossbeam_channel::{select, Receiver, Sender, TrySendError};
use datadog_profiling::exporter::{Endpoint, File, Tag};
use datadog_profiling::profile;
use datadog_profiling::profile::api::{Function, Line, Location, Period, Sample};
use log::{debug, info, trace, warn};
use std::borrow::{Borrow, Cow};
use std::collections::HashMap;
use std::hash::Hash;
use std::str;
use std::str::Utf8Error;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Barrier, Mutex};
use std::thread::JoinHandle;
use std::time::{Duration, Instant, SystemTime};

const UPLOAD_PERIOD: Duration = Duration::from_secs(67);

// Guide: upload period / upload timeout should give about the order of
// magnitude for the capacity.
const UPLOAD_CHANNEL_CAPACITY: usize = 8;

const WALL_TIME_PERIOD: Duration = Duration::from_millis(10);
const WALL_TIME_PERIOD_TYPE: ValueType = ValueType {
    r#type: Cow::Borrowed("wall-time"),
    unit: Cow::Borrowed("nanoseconds"),
};

#[derive(Debug, Clone)]
struct WallTime {
    instant: Instant,
    systemtime: SystemTime,
}

impl WallTime {
    fn now() -> Self {
        Self {
            instant: Instant::now(),
            systemtime: SystemTime::now(),
        }
    }
}

#[derive(Debug, Clone)]
pub enum LabelValue {
    Str(Cow<'static, str>),

    #[allow(dead_code)]
    Num(i64, Cow<'static, str>),
}

#[derive(Debug, Clone)]
pub struct Label {
    pub key: Cow<'static, str>,
    pub value: LabelValue,
}

impl<'a> From<&'a Label> for profile::api::Label<'a> {
    fn from(label: &'a Label) -> Self {
        let key = label.key.as_ref();
        match &label.value {
            LabelValue::Str(str) => Self {
                key,
                str: Some(str),
                num: 0,
                num_unit: None,
            },
            LabelValue::Num(num, num_unit) => Self {
                key,
                str: None,
                num: *num,
                num_unit: Some(num_unit),
            },
        }
    }
}

#[derive(Debug, Clone, Eq, PartialEq, Hash)]
pub struct ValueType {
    pub r#type: Cow<'static, str>,
    pub unit: Cow<'static, str>,
}

/// A ProfileIndex contains the fields that factor into the uniqueness of a
/// profile when we aggregate it. It's mostly based on the upload protocols,
/// because we cannot mix profiles belonging to different services into the
/// same upload.
/// This information is expected to be mostly stable for a process, but it may
/// not be if an Apache reload occurs and it adjusts the service name, or if
/// Apache per-dir settings use different service name, etc.
#[derive(Clone, Debug, Eq, PartialEq, Hash)]
pub struct ProfileIndex {
    pub sample_types: Vec<ValueType>,
    pub tags: Vec<Tag>,
    pub endpoint: Box<AgentEndpoint>,
}

#[derive(Debug)]
pub struct SampleData {
    pub frames: Vec<ZendFrame>,
    pub labels: Vec<Label>,
    pub sample_values: Vec<i64>,
}

#[derive(Debug)]
pub struct SampleMessage {
    pub key: ProfileIndex,
    pub value: SampleData,
}

#[derive(Debug)]
pub struct LocalRootSpanResourceMessage {
    pub local_root_span_id: u64,
    pub resource: String,
}

#[derive(Debug)]
pub enum ProfilerMessage {
    Cancel,
    Sample(SampleMessage),
    LocalRootSpanResource(LocalRootSpanResourceMessage),
}

pub struct Globals {
    pub interrupt_count: AtomicU32,
    pub last_interrupt: SystemTime,
    // todo: current_profile
}

impl Default for Globals {
    fn default() -> Self {
        Self {
            interrupt_count: AtomicU32::new(0),
            last_interrupt: SystemTime::now(),
        }
    }
}

#[derive(Default, Debug)]
pub struct ZendFrame {
    // Most tools don't like frames that don't have function names, so use a
    // fake name if you need to like "<php>".
    pub function: String,
    pub file: Option<String>,
    pub line: u32, // use 0 for no line info
}

// todo: dedup
unsafe fn zend_string_to_bytes(zstr: Option<&mut zend_string>) -> &[u8] {
    bindings::datadog_php_profiling_zend_string_view(zstr).into_bytes()
}

/// Extract the "function name" component for the frame. This is a string which
/// looks like this for methods:
///     {module}|{class_name}::{method_name}
/// And this for functions:
///     {module}|{function_name}
/// Where the "{module}|" is present only if it's an internal function.
/// Namespaces are part of the class_name or function_name respectively.
/// Closures and anonymous classes get reformatted by the backend (or maybe
/// frontend, either way it's not our concern, at least not right now).
unsafe fn extract_function_name(func: &zend_function) -> String {
    let method_name: &[u8] = func.name().unwrap_or(b"");

    /* The top of the stack seems to reasonably often not have a function, but
     * still has a scope. I don't know if this intentional, or if it's more of
     * a situation where scope is only valid if the func is present. So, I'm
     * erring on the side of caution and returning early.
     */
    if method_name.is_empty() {
        return String::new();
    }

    let mut buffer = Vec::<u8>::new();

    // User functions do not have a "module". Maybe one day use composer info?
    let module_name = func.module_name().unwrap_or(b"");
    if !module_name.is_empty() {
        buffer.extend_from_slice(module_name);
        buffer.push(b'|');
    }

    // todo: probably use EX(This) instead of func.common.scope.
    let class_name = func.scope_name().unwrap_or(b"");
    if !class_name.is_empty() {
        buffer.extend_from_slice(class_name);
        buffer.extend_from_slice(b"::");
    }

    buffer.extend_from_slice(method_name);

    String::from_utf8_lossy(buffer.as_slice()).into_owned()
}

unsafe fn extract_file_name(execute_data: &zend_execute_data) -> String {
    // this is supposed to be verified by the caller
    if execute_data.func.is_null() {
        return String::new();
    }

    let func = &*execute_data.func;
    if func.type_ == ZEND_USER_FUNCTION as u8 {
        let filename = zend_string_to_bytes(func.op_array.filename.as_mut());
        return String::from_utf8_lossy(filename).to_string();
    }
    String::new()
}

unsafe fn extract_line_no(execute_data: &zend_execute_data) -> u32 {
    // this is supposed to be verified by the caller
    assert!(!execute_data.func.is_null());

    let func = &*execute_data.func;
    if func.type_ == ZEND_USER_FUNCTION as u8 && !execute_data.opline.is_null() {
        let opline = &*execute_data.opline;
        return opline.lineno;
    }
    0
}

unsafe fn collect_stack_sample(
    top_execute_data: *mut zend_execute_data,
) -> Result<Vec<ZendFrame>, Utf8Error> {
    let max_depth = 512;
    let mut samples = Vec::with_capacity(max_depth >> 3);
    let mut execute_data = top_execute_data;

    while !execute_data.is_null() {
        /* -1 to reserve room for the [truncated] message. In case the backend
         * and/or frontend have the same limit, without the -1 we'd ironically
         * truncate our [truncated] message.
         */
        if samples.len() >= max_depth - 1 {
            samples.push(ZendFrame {
                function: "[truncated]".to_string(),
                file: None,
                line: 0,
            });
            break;
        }
        let func = (*execute_data).func;
        if !func.is_null() {
            let function = extract_function_name(&*func);
            let file: String = extract_file_name(&*execute_data);

            // Normalize empty strings into None.
            let function = (!function.is_empty()).then(|| function);
            let file = (!file.is_empty()).then(|| file);

            // Only insert a new frame if there's file or function info.
            if file.is_some() || function.is_some() {
                // If there's no function name, use a fake name.
                let function = function.unwrap_or_else(|| "<?php".to_owned());
                let frame = ZendFrame {
                    function,
                    file,
                    line: extract_line_no(&*execute_data),
                };

                samples.push(frame);
            }
        }

        execute_data = (*execute_data).prev_execute_data;
    }
    Ok(samples)
}

#[derive(Debug, Eq, PartialEq, Hash)]
pub struct VmInterrupt {
    pub interrupt_count_ptr: *const AtomicU32,
    pub engine_ptr: *const AtomicBool,
}

impl std::fmt::Display for VmInterrupt {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(
            f,
            "VmInterrupt{{{:?}, {:?}}}",
            self.interrupt_count_ptr, self.engine_ptr
        )
    }
}

// This is a lie, technically, but we're trying to build it safely on top of
// the PHP VM.
unsafe impl Send for VmInterrupt {}

pub struct Profiler {
    fork_barrier: Arc<Barrier>,
    fork_senders: [Sender<()>; 2],
    vm_interrupts: Arc<Mutex<Vec<VmInterrupt>>>,
    message_sender: Sender<ProfilerMessage>,
    time_collector_handle: JoinHandle<()>,
    uploader_handle: JoinHandle<()>,
}

struct TimeCollector {
    fork_barrier: Arc<Barrier>,
    fork_receiver: Receiver<()>,
    vm_interrupts: Arc<Mutex<Vec<VmInterrupt>>>,
    message_receiver: Receiver<ProfilerMessage>,
    upload_sender: Sender<UploadMessage>,
    wall_time_period: Duration,
    upload_period: Duration,
}

impl TimeCollector {
    fn handle_timeout(
        &self,
        profiles: &mut HashMap<ProfileIndex, profile::Profile>,
        last_export: &WallTime,
    ) -> WallTime {
        let wall_export = WallTime::now();
        if profiles.is_empty() {
            info!("No profiles to upload.");
            return wall_export;
        }

        let duration = wall_export
            .instant
            .checked_duration_since(last_export.instant);

        let end_time = wall_export.systemtime;

        for (index, profile) in profiles.drain() {
            let message = UploadMessage {
                index,
                profile,
                end_time,
                duration,
            };
            if let Err(err) = self.upload_sender.try_send(message) {
                warn!("Failed to upload profile: {}", err);
            }
        }
        wall_export
    }

    /// Create a profile based on the message and start time. Note that it
    /// makes sense to use an older time than now because if the profiler was
    /// running 4 seconds ago and we're only creating a profile now, that means
    /// we didn't collect any samples during that 4 seconds.
    fn create_profile(message: &SampleMessage, started_at: SystemTime) -> profile::Profile {
        let sample_types = message
            .key
            .sample_types
            .iter()
            .map(|sample_type| profile::api::ValueType {
                r#type: sample_type.r#type.borrow(),
                unit: sample_type.unit.borrow(),
            })
            .collect();

        let period = WALL_TIME_PERIOD.as_nanos();
        profile::ProfileBuilder::new()
            .period(Some(Period {
                r#type: profile::api::ValueType {
                    r#type: WALL_TIME_PERIOD_TYPE.r#type.borrow(),
                    unit: WALL_TIME_PERIOD_TYPE.unit.borrow(),
                },
                value: period.min(i64::MAX as u128) as i64,
            }))
            .start_time(Some(started_at))
            .sample_types(sample_types)
            .build()
    }

    fn handle_resource_message(
        message: LocalRootSpanResourceMessage,
        profiles: &mut HashMap<ProfileIndex, profile::Profile>,
    ) {
        trace!(
            "Received Endpoint Profiling message for span id {}.",
            message.local_root_span_id
        );
        let local_root_span_id = message.local_root_span_id.to_string();
        for (_, profile) in profiles.iter_mut() {
            let local_root_span_id = Cow::Borrowed(local_root_span_id.as_ref());
            let endpoint = Cow::Borrowed(message.resource.as_str());
            profile.add_endpoint(local_root_span_id, endpoint)
        }
    }

    fn handle_sample_message(
        message: SampleMessage,
        profiles: &mut HashMap<ProfileIndex, profile::Profile>,
        started_at: &WallTime,
    ) {
        let profile: &mut profile::Profile = if let Some(value) = profiles.get_mut(&message.key) {
            value
        } else {
            profiles.insert(
                message.key.clone(),
                Self::create_profile(&message, started_at.systemtime),
            );
            profiles
                .get_mut(&message.key)
                .expect("entry to exist; just inserted it")
        };

        let mut locations = vec![];

        let values = message.value.sample_values;
        let labels = message
            .value
            .labels
            .iter()
            .map(profile::api::Label::from)
            .collect();

        for frame in &message.value.frames {
            let location = Location {
                lines: vec![Line {
                    function: Function {
                        name: frame.function.as_str(),
                        system_name: "",
                        filename: frame.file.as_deref().unwrap_or(""),
                        start_line: 0,
                    },
                    line: frame.line as i64,
                }],
                ..Default::default()
            };

            locations.push(location);
        }

        let sample = Sample {
            locations,
            values,
            labels,
        };

        match profile.add(sample) {
            Ok(_id) => {}
            Err(err) => {
                warn!("Failed to add sample to the profile: {}", err)
            }
        }
    }

    pub fn run(&self) {
        let mut last_wall_export = WallTime::now();
        let mut profiles: HashMap<ProfileIndex, profile::Profile> = HashMap::with_capacity(1);

        debug!(
            "Started with an upload period of {} seconds and approximate wall-time period of {} milliseconds.",
            UPLOAD_PERIOD.as_secs(),
            WALL_TIME_PERIOD.as_millis());

        let wall_time_tick = crossbeam_channel::tick(self.wall_time_period);
        let upload_tick = crossbeam_channel::tick(self.upload_period);
        let mut running = true;

        while running {
            crossbeam_channel::select! {

                recv(self.message_receiver) -> result => {
                    match result {
                        Ok(message) => match message {
                            ProfilerMessage::Sample(sample) =>
                                Self::handle_sample_message(sample, &mut profiles, &last_wall_export),
                            ProfilerMessage::LocalRootSpanResource(message) =>
                                Self::handle_resource_message(message, &mut profiles),
                            ProfilerMessage::Cancel => {
                                // flush what we have before exiting
                                last_wall_export = self.handle_timeout(&mut profiles, &last_wall_export);
                                running = false;
                            }
                        },
                        Err(_) => {
                            /* Docs say:
                             * > A message could not be received because the
                             * > channel is empty and disconnected.
                             * If this happens, let's just break and end.
                             */
                            break;
                        }
                    }
                },

                recv(wall_time_tick) -> message => {
                    if message.is_ok() {
                        let vm_interrupts = self.vm_interrupts.lock().unwrap();

                        vm_interrupts.iter().for_each(|obj| unsafe {
                            (&*obj.engine_ptr).store(true, Ordering::SeqCst);
                            (&*obj.interrupt_count_ptr).fetch_add(1, Ordering::SeqCst);
                        });
                    }
                },

                recv(upload_tick) -> message => {
                    if message.is_ok() {
                        last_wall_export = self.handle_timeout(&mut profiles, &last_wall_export);
                    }
                },

                recv(self.fork_receiver) -> message => {
                    if message.is_ok() {
                        // First, wait for every thread to finish what they are currently doing.
                        self.fork_barrier.wait();
                        // Then, wait for the fork to be completed.
                        self.fork_barrier.wait();
                    }
                }

            }
        }
    }
}

struct UploadMessage {
    index: ProfileIndex,
    profile: profile::Profile,
    end_time: SystemTime,
    duration: Option<Duration>,
}

struct Uploader {
    fork_barrier: Arc<Barrier>,
    fork_receiver: Receiver<()>,
    upload_receiver: Receiver<UploadMessage>,
}

impl Uploader {
    fn upload(message: UploadMessage) -> anyhow::Result<u16> {
        let index = message.index;
        let profile = message.profile;

        let profiling_library_name: &str = &PROFILER_NAME_STR;
        let profiling_library_version: &str = &PROFILER_VERSION_STR;
        let endpoint: Endpoint = (&*index.endpoint).try_into()?;
        let exporter = datadog_profiling::exporter::ProfileExporter::new(
            profiling_library_name,
            profiling_library_version,
            "php",
            Some(index.tags),
            endpoint,
        )?;

        let serialized = profile.serialize(Some(message.end_time), message.duration)?;
        let start = serialized.start.into();
        let end = serialized.end.into();
        let files = &[File {
            name: "profile.pprof",
            bytes: serialized.buffer.as_slice(),
        }];
        let timeout = Duration::from_secs(10);
        let request = exporter.build(start, end, files, None, timeout)?;
        debug!("Sending profile to: {}", index.endpoint);
        let result = exporter.send(request, None)?;
        Ok(result.status().as_u16())
    }

    pub fn run(&self) {
        loop {
            /* Since profiling uploads are going over the Internet and not just
             * the local network, it would be ideal if they were the lowest
             * priority message, but crossbeam selects at random.
             * todo: fix fork message priority.
             */
            select! {
                recv(self.fork_receiver) -> message => match message {
                    Ok(_) => {
                        // First, wait for every thread to finish what they are currently doing.
                        self.fork_barrier.wait();
                        // Then, wait for the fork to be completed.
                        self.fork_barrier.wait();
                    }
                    _ => {
                        trace!("Fork channel closed; joining upload thread.");
                        break;
                    }
                },

                recv(self.upload_receiver) -> message => match message {
                    Ok(upload_message) => match Self::upload(upload_message) {
                        Ok(status) => {
                            if status >= 400 {
                                warn!(
                                    "Unexpected HTTP status when sending profile (HTTP {}).",
                                    status
                                )
                            } else {
                                info!("Successfully uploaded profile (HTTP {}).", status)
                            }
                        }
                        Err(err) => {
                            warn!("Failed to upload profile: {}", err)
                        }
                    },
                    _ => {
                        trace!("No more upload messages to handle; joining thread.");
                        break;
                    }

                },
            }
        }
    }
}

impl Profiler {
    pub fn new() -> Self {
        let fork_barrier = Arc::new(Barrier::new(3));
        let (fork_sender0, fork_receiver0) = crossbeam_channel::bounded(1);
        let vm_interrupts = Arc::new(Mutex::new(Vec::with_capacity(1)));
        let (message_sender, message_receiver) = crossbeam_channel::bounded(100);
        let (upload_sender, upload_receiver) = crossbeam_channel::bounded(UPLOAD_CHANNEL_CAPACITY);
        let (fork_sender1, fork_receiver1) = crossbeam_channel::bounded(1);
        let time_collector = TimeCollector {
            fork_barrier: fork_barrier.clone(),
            fork_receiver: fork_receiver0,
            vm_interrupts: vm_interrupts.clone(),
            message_receiver,
            upload_sender,
            wall_time_period: WALL_TIME_PERIOD,
            upload_period: UPLOAD_PERIOD,
        };

        let uploader = Uploader {
            fork_barrier: fork_barrier.clone(),
            fork_receiver: fork_receiver1,
            upload_receiver,
        };
        Profiler {
            fork_barrier,
            fork_senders: [fork_sender0, fork_sender1],
            vm_interrupts,
            message_sender,
            time_collector_handle: std::thread::spawn(move || time_collector.run()),
            uploader_handle: std::thread::spawn(move || uploader.run()),
        }
    }

    pub fn add_interrupt(&self, interrupt: VmInterrupt) -> Result<(), (usize, VmInterrupt)> {
        let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
        for (index, value) in vm_interrupts.iter().enumerate() {
            if *value == interrupt {
                return Err((index, interrupt));
            }
        }
        vm_interrupts.push(interrupt);
        Ok(())
    }

    pub fn remove_interrupt(&self, interrupt: VmInterrupt) -> Result<(), VmInterrupt> {
        let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
        let mut offset = None;
        for (index, value) in vm_interrupts.iter().enumerate() {
            if *value == interrupt {
                offset = Some(index);
                break;
            }
        }

        if let Some(index) = offset {
            vm_interrupts.swap_remove(index);
            Ok(())
        } else {
            Err(interrupt)
        }
    }

    /// Call before a fork, on the thread of the parent process that will fork.
    pub fn fork_prepare(&self) {
        for sender in self.fork_senders.iter() {
            // Hmm, what to do with errors?
            let _ = sender.send(());
        }
        self.fork_barrier.wait();
    }

    /// Call after a fork, but only on the thread of the parent process that forked.
    pub fn post_fork_parent(&self) {
        self.fork_barrier.wait();
    }

    pub fn send_sample(&self, message: SampleMessage) -> Result<(), TrySendError<ProfilerMessage>> {
        self.message_sender
            .try_send(ProfilerMessage::Sample(message))
    }

    pub fn send_local_root_span_resource(
        &self,
        message: LocalRootSpanResourceMessage,
    ) -> Result<(), TrySendError<ProfilerMessage>> {
        self.message_sender
            .try_send(ProfilerMessage::LocalRootSpanResource(message))
    }

    pub fn stop(self) {
        // todo: what should be done when a thread panics?
        debug!("Stopping profiler.");
        let _ = self.message_sender.send(ProfilerMessage::Cancel);
        if let Err(err) = self.time_collector_handle.join() {
            std::panic::resume_unwind(err)
        }

        // Wait for the time_collector to join, since that will drop the sender
        // half of the channel that the uploader is holding, allowing it to
        // finish.
        if let Err(err) = self.uploader_handle.join() {
            std::panic::resume_unwind(err)
        }
    }

    /// Collect a stack sample with elapsed wall time. Collects CPU time if
    /// it's enabled and available.
    pub unsafe fn collect_time(
        &self,
        execute_data: *mut zend_execute_data,
        interrupt_count: u32,
        mut locals: &mut RequestLocals,
    ) {
        // todo: should probably exclude the wall and CPU time used by collecting the sample.
        let interrupt_count = interrupt_count as i64;
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(frames) => {
                let depth = frames.len();

                // todo: add {cpu-time, nanoseconds}
                let mut sample_types = vec![
                    ValueType {
                        r#type: Cow::Borrowed("sample"),
                        unit: Cow::Borrowed("count"),
                    },
                    ValueType {
                        r#type: Cow::Borrowed("wall-time"),
                        unit: Cow::Borrowed("nanoseconds"),
                    },
                ];

                let now = Instant::now();
                let walltime = now.duration_since(locals.last_wall_time);
                locals.last_wall_time = now;
                let walltime: i64 = walltime.as_nanos().try_into().unwrap_or(i64::MAX);

                let mut sample_values = vec![interrupt_count, walltime];

                /* If CPU time is disabled, or if it's enabled but not available on the platform,
                 * then `locals.last_cpu_time` will be None.
                 */
                if let Some(last_cpu_time) = locals.last_cpu_time {
                    sample_types.push(ValueType {
                        r#type: Cow::Borrowed("cpu-time"),
                        unit: Cow::Borrowed("nanoseconds"),
                    });

                    let now = cpu_time::ThreadTime::try_now()
                        .expect("CPU time to work since it's worked before during this process");
                    let cputime: i64 = now
                        .duration_since(last_cpu_time)
                        .as_nanos()
                        .try_into()
                        .unwrap_or(i64::MAX);
                    sample_values.push(cputime);
                    locals.last_cpu_time = Some(now);
                }

                let mut labels = vec![];
                let gpc = datadog_php_profiling_get_profiling_context;
                if let Some(get_profiling_context) = gpc {
                    let context = get_profiling_context();
                    if context.local_root_span_id != 0 {
                        labels.push(Label {
                            key: "local root span id".into(),
                            value: LabelValue::Str(
                                format!("{}", context.local_root_span_id).into(),
                            ),
                        });
                        labels.push(Label {
                            key: "span id".into(),
                            value: LabelValue::Str(format!("{}", context.span_id).into()),
                        });
                    }
                }

                let mut tags = locals.tags.clone();

                if let Some(version) = PHP_VERSION.get() {
                    /* This should probably be "language_version", but this is
                     * the tag that was standardized for this purpose. */
                    let tag = Tag::new("runtime_version", version)
                        .expect("runtime_version to be a valid tag");
                    tags.push(tag);
                }

                if let Some(sapi) = crate::SAPI.get() {
                    match Tag::new("php.sapi", sapi.to_string()) {
                        Ok(tag) => tags.push(tag),
                        Err(err) => warn!("Tag error: {}", err),
                    }
                }

                let message = SampleMessage {
                    key: ProfileIndex {
                        sample_types,
                        tags,
                        endpoint: locals.uri.clone(),
                    },
                    value: SampleData {
                        frames,
                        labels: labels.clone(),
                        sample_values,
                    },
                };

                // Panic: profiler was checked above for is_none().
                match self.send_sample(message) {
                    Ok(_) => trace!(
                        "Sent stack sample of depth {} with labels {:?} to profiler.",
                        depth,
                        labels
                    ),
                    Err(err) => warn!(
                        "Failed to send stack sample of depth {} with labels {:?} to profiler: {}",
                        depth, labels, err
                    ),
                }
            }
            Err(err) => {
                warn!("Failed to collect stack sample: {}", err)
            }
        }
    }
}
