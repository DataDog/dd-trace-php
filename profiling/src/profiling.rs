use crate::bindings::{
    datadog_php_profiling_get_profiling_context, zend_class_entry, zend_execute_data,
    zend_function, ZEND_INTERNAL_FUNCTION, ZEND_USER_FUNCTION,
};
use crate::{AgentEndpoint, RequestLocals, PHP_VERSION};
use crossbeam_channel::{select, Receiver, Sender, TrySendError};
use datadog_profiling::exporter::{Endpoint, File, Tag};
use datadog_profiling::profile::{v2, EncodedProfile};
use indexmap::IndexMap;
use lazy_static::lazy_static;
use log::{debug, info, trace, warn};
use std::borrow::Cow;
use std::collections::HashMap;
use std::ffi::CStr;
use std::hash::Hash;
use std::os::raw::c_char;
use std::str::Utf8Error;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Barrier, Mutex, MutexGuard};
use std::thread::JoinHandle;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

const UPLOAD_PERIOD: Duration = Duration::from_secs(67);

// Guide: upload period / upload timeout should give about the order of
// magnitude for the capacity.
const UPLOAD_CHANNEL_CAPACITY: usize = 8;

const WALL_TIME_PERIOD: Duration = Duration::from_millis(10);

struct KnownStrings {
    pub php_no_func: i64,
    pub local_root_span_id: i64,
    pub span_id: i64,
    pub truncated: i64,
}

struct KnownValueTypes {
    pub sample: v2::ValueType,
    pub wall_time: v2::ValueType,
    pub cpu_time: v2::ValueType,
}

struct KnownThings {
    pub strings: KnownStrings,
    pub value_types: KnownValueTypes,
    pub string_table: Arc<v2::LockedStringTable>,
}

lazy_static! {
    static ref KNOWN: KnownThings = {
        let mut string_table = v2::StringTable::new();

        let value_types = KnownValueTypes {
            sample: v2::ValueType {
                r#type: string_table.intern("sample"),
                unit: string_table.intern("count"),
            },
            wall_time: v2::ValueType {
                r#type: string_table.intern("wall-time"),
                unit: string_table.intern("nanoseconds"),
            },
            cpu_time: v2::ValueType {
                r#type: string_table.intern("cpu-time"),
                unit: string_table.intern("nanoseconds"),
            },
        };

        let strings = KnownStrings {
            php_no_func: string_table.intern("<?php"),
            local_root_span_id: string_table.intern("local root span id"),
            span_id: string_table.intern("span id"),
            truncated: string_table.intern("[truncated]"),
        };

        KnownThings {
            strings,
            value_types,
            string_table: Arc::new(v2::LockedStringTable::from(string_table)),
        }
    };
}

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

/// A ProfileIndex contains the fields that factor into the uniqueness of a
/// profile when we aggregate it. It's mostly based on the upload protocols,
/// because we cannot mix profiles belonging to different services into the
/// same upload.
/// This information is expected to be mostly stable for a process, but it may
/// not be if an Apache reload occurs and it adjusts the service name, or if
/// Apache per-dir settings use different service name, etc.
#[derive(Clone, Debug, Eq, PartialEq, Hash)]
pub struct ProfileIndex {
    pub sample_types: Vec<v2::ValueType>,
    pub tags: Vec<Tag>,
    pub endpoint: Box<AgentEndpoint>,
}

pub struct SampleData {
    pub frames: Vec<ZendFrame>,
    pub labels: Vec<v2::Label>,
    pub sample_values: Vec<i64>,
}

pub struct SampleMessage {
    pub key: ProfileIndex,
    pub value: SampleData,
}

#[derive(Debug)]
pub struct LocalRootSpanResourceMessage {
    pub local_root_span_id: i64, // Index into the string table.
    pub resource: i64,           // Index into the string table.
}

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

#[derive(Debug)]
pub struct ZendFrame {
    // Most tools don't like frames that don't have function names, so use a
    // fake name if you need to like "<php>".
    pub function: i64, // index into the string table
    pub file: i64,     // index into the string table
    pub line: u32,     // index into the string table
}

static NULL: &[u8] = b"\0";

unsafe fn get_func_name(func: &zend_function) -> &[u8] {
    let ptr = if func.common.function_name.is_null() {
        NULL.as_ptr() as *const c_char
    } else {
        let zstr = &*func.common.function_name;
        if zstr.len == 0 {
            NULL.as_ptr() as *const c_char
        } else {
            zstr.val.as_ptr() as *const c_char
        }
    };

    // CStr::to_bytes does not contain the trailing null byte
    CStr::from_ptr(ptr).to_bytes()
}

unsafe fn get_class_name(class: &zend_class_entry) -> &[u8] {
    let ptr = if class.name.is_null() {
        NULL.as_ptr() as *const c_char
    } else {
        let zstr = &*class.name;
        if zstr.len == 0 {
            NULL.as_ptr() as *const c_char
        } else {
            zstr.val.as_ptr() as *const c_char
        }
    };

    // CStr::to_bytes does not contain the trailing null byte
    CStr::from_ptr(ptr).to_bytes()
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
unsafe fn extract_function_name(
    string_table: &mut MutexGuard<v2::StringTable>,
    func: &zend_function,
) -> i64 {
    let method_name: &[u8] = get_func_name(func);

    /* The top of the stack seems to reasonably often not have a function, but
     * still has a scope. I don't know if this intentional, or if it's more of
     * a situation where scope is only valid if the func is present. So, I'm
     * erring on the side of caution and returning early.
     */
    if method_name.is_empty() {
        return 0;
    }

    let mut buffer = Vec::<u8>::new();

    // User functions do not have a "module". Maybe one day use composer info?
    if func.type_ == ZEND_INTERNAL_FUNCTION as u8
        && !func.internal_function.module.is_null()
        && !(*func.internal_function.module).name.is_null()
    {
        let ptr = (*func.internal_function.module).name as *const c_char;
        let bytes = CStr::from_ptr(ptr).to_bytes();
        if !bytes.is_empty() {
            buffer.extend_from_slice(bytes);
            buffer.push(b'|');
        }
    }

    if !func.common.scope.is_null() {
        let class_name = get_class_name(&*func.common.scope);
        if !class_name.is_empty() {
            buffer.extend_from_slice(class_name);
            buffer.extend_from_slice(b"::");
        }
    }

    buffer.extend_from_slice(method_name);

    let s = String::from_utf8_lossy(buffer.as_slice());
    string_table.intern(s)
}

unsafe fn extract_file_name(
    storage: &mut MutexGuard<v2::StringTable>,
    execute_data: &zend_execute_data,
) -> i64 {
    // this is supposed to be verified by the caller
    if execute_data.func.is_null() {
        return 0;
    }

    let func = &*execute_data.func;
    if func.type_ == ZEND_USER_FUNCTION as u8 {
        let op_array = &func.op_array;
        if op_array.filename.is_null() {
            0
        } else {
            let zstr = &*op_array.filename;
            if zstr.len == 0 {
                0
            } else {
                let cstr = CStr::from_ptr(zstr.val.as_ptr() as *const c_char).to_bytes();
                storage.intern(String::from_utf8_lossy(cstr))
            }
        }
    } else {
        0
    }
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

    let php_no_func = KNOWN.strings.php_no_func;

    while !execute_data.is_null() {
        let mut string_table = KNOWN.string_table.lock();
        /* -1 to reserve room for the [truncated] message. In case the backend
         * and/or frontend have the same limit, without the -1 we'd ironically
         * truncate our [truncated] message.
         */
        if samples.len() >= max_depth - 1 {
            samples.push(ZendFrame {
                function: KNOWN.strings.truncated,
                file: 0,
                line: 0,
            });
            break;
        }
        let func = (*execute_data).func;
        if !func.is_null() {
            let mut function = extract_function_name(&mut string_table, &*func);
            let file = extract_file_name(&mut string_table, &*execute_data);

            // Only insert a new frame if there's file or function info.
            if file > 0 || function > 0 {
                // If there's no function name, use a fake name.
                if function <= 0 {
                    function = php_no_func;
                }
                let frame = ZendFrame {
                    function,
                    file,
                    line: extract_line_no(&*execute_data),
                };

                samples.push(frame);
            }
        }
        drop(string_table);

        execute_data = (*execute_data).prev_execute_data;
    }

    // debug!("Samples: {:#?}", samples);
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
    profile_storage: v2::ProfileStorage,
    profiles: HashMap<ProfileIndex, v2::Profile>,
    vm_interrupts: Arc<Mutex<Vec<VmInterrupt>>>,
    message_receiver: Receiver<ProfilerMessage>,
    upload_sender: Sender<UploadMessage>,
    wall_time_period: Duration,
    upload_period: Duration,
}

impl TimeCollector {
    fn handle_timeout(
        &mut self,
        last_export: &WallTime,
    ) -> WallTime {
        let wall_export = WallTime::now();
        if self.profiles.is_empty() {
            info!("No profiles to upload.");
            return wall_export;
        }

        let duration = wall_export
            .instant
            .checked_duration_since(last_export.instant);

        let end_time = wall_export.systemtime;

        for (index, profile) in self.profiles.drain() {
            if let Ok(profile) = profile.serialize(&self.profile_storage, end_time, duration) {
                let message = UploadMessage { index, profile };
                if let Err(err) = self.upload_sender.try_send(message) {
                    warn!("Failed to upload profile: {}", err);
                }
            }
        }
        wall_export
    }

    fn handle_resource_message(
        &mut self,
        message: LocalRootSpanResourceMessage,
    ) {
        let local_root_span_id = message.local_root_span_id;
        let endpoint = message.resource;
        for (_, profile) in self.profiles.iter_mut() {
            profile.add_endpoint(local_root_span_id, endpoint);
        }
    }

    fn handle_sample_message(
        &mut self,
        message: SampleMessage,
        started_at: &WallTime,
    ) {
        let profile = if let Some(value) = self.profiles.get_mut(&message.key) {
            value
        } else {
            let started_at1 = started_at.systemtime;
            let time_nanos: i64 = started_at1
                .duration_since(UNIX_EPOCH)
                .unwrap_or(Duration::ZERO)
                .as_nanos()
                .try_into()
                .unwrap_or(i64::MAX);

            let period = WALL_TIME_PERIOD.as_nanos().try_into().unwrap();
            self.profiles.insert(
                message.key.clone(),
                v2::Profile::new(
                    KNOWN.string_table.clone(),
                    message.key.sample_types.clone(),
                    IndexMap::new(),
                    time_nanos,
                    0,
                    Some((KNOWN.value_types.wall_time, period)),
                )
                .unwrap(),
            );
            self.profiles
                .get_mut(&message.key)
                .expect("entry to exist; just inserted it")
        };

        let mut locations = vec![];

        let values = message.value.sample_values;
        let labels = message.value.labels;

        for frame in &message.value.frames {
            let function = v2::Function {
                id: 0,
                name: frame.function,
                system_name: 0,
                filename: frame.file,
                start_line: 0,
            };

            let function_id = self.profile_storage.add_function(function);
            let line = v2::Line {
                function_id,
                line_number: frame.line.into(),
            };

            let location = v2::Location {
                id: 0,
                mapping_id: 0,
                address: 0,
                lines: vec![line],
                is_folded: false,
            };

            let location_id = self.profile_storage.add_location(location);
            locations.push(location_id);
        }

        let sample = v2::Sample {
            location_ids: locations,
            labels,
        };

        if let Err(err) = profile.add_sample(sample, values) {
            warn!("Failed to add sample to the profile: {}", err)
        }
    }

    pub fn run(mut self) {
        let mut last_wall_export = WallTime::now();

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
                                self.handle_sample_message(sample, &last_wall_export),
                            ProfilerMessage::LocalRootSpanResource(message) =>
                                self.handle_resource_message(message),
                            ProfilerMessage::Cancel => {
                                // flush what we have before exiting
                                last_wall_export = self.handle_timeout(&last_wall_export);
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
                        last_wall_export = self.handle_timeout(&last_wall_export);
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
    profile: EncodedProfile,
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

        let endpoint: Endpoint = (&*index.endpoint).try_into()?;
        let exporter = datadog_profiling::exporter::ProfileExporter::new(
            "datadog-php-profiling",
            env!("CARGO_PKG_VERSION"),
            "php",
            Some(index.tags),
            endpoint,
        )?;

        let start = profile.start.into();
        let end = profile.end.into();
        let files = &[File {
            name: "profile.pprof",
            bytes: profile.buffer.as_slice(),
        }];

        let timeout = Duration::from_secs(10);
        let request = exporter.build(start, end, files, None, timeout)?;
        debug!("Sending profile to: {}", index.endpoint);
        let result = exporter.send(request, None)?;
        Ok(result.status().as_u16())
    }

    pub fn run(self) {
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
            profile_storage: v2::ProfileStorage::default(),
            profiles: Default::default(),
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
        local_root_span_id: u64,
        endpoint: Cow<str>,
    ) -> Result<(), TrySendError<ProfilerMessage>> {
        let mut string_table = KNOWN.string_table.lock();
        let local_root_span_id = string_table.intern(local_root_span_id.to_string());
        let resource = string_table.intern(endpoint);
        drop(string_table); // release the lock as quickly as possible

        let message = ProfilerMessage::LocalRootSpanResource(LocalRootSpanResourceMessage {
            local_root_span_id,
            resource,
        });
        self.message_sender.try_send(message)
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

                let mut sample_types = vec![KNOWN.value_types.sample, KNOWN.value_types.wall_time];

                let now = Instant::now();
                let walltime = now.duration_since(locals.last_wall_time);
                locals.last_wall_time = now;
                let walltime: i64 = walltime.as_nanos().try_into().unwrap_or(i64::MAX);

                let mut sample_values = vec![interrupt_count, walltime];

                /* If CPU time is disabled, or if it's enabled but not available on the platform,
                 * then `locals.last_cpu_time` will be None.
                 */
                if let Some(last_cpu_time) = locals.last_cpu_time {
                    sample_types.push(KNOWN.value_types.cpu_time);

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
                        let mut string_table = KNOWN.string_table.lock();
                        let local_root_span_id =
                            string_table.intern(context.local_root_span_id.to_string());
                        let span_id = string_table.intern(context.span_id.to_string());
                        drop(string_table); // release as quickly as possible

                        let key = KNOWN.strings.local_root_span_id;
                        labels.push(v2::Label::str(key, local_root_span_id));
                        labels.push(v2::Label::str(KNOWN.strings.span_id, span_id));
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
                        labels,
                        sample_values,
                    },
                };

                // Panic: profiler was checked above for is_none().
                match self.send_sample(message) {
                    Ok(_) => trace!("Sent stack sample of depth {} to profiler.", depth),
                    Err(err) => warn!(
                        "Failed to send stack sample of depth {} to profiler: {}",
                        depth, err
                    ),
                }
            }
            Err(err) => {
                warn!("Failed to collect stack sample: {}", err)
            }
        }
    }
}
