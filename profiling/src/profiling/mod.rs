mod interrupt_manager;
mod stalk_walking;
mod thread_utils;
mod uploader;

use interrupt_manager::*;
use stalk_walking::*;
use uploader::*;

use crate::bindings::{datadog_php_profiling_get_profiling_context, zend_execute_data};
use crate::{AgentEndpoint, RequestLocals, PHP_VERSION};
use crossbeam_channel::{Receiver, Sender, TrySendError};
use datadog_profiling::exporter::Tag;
use datadog_profiling::profile;
use datadog_profiling::profile::api::{Function, Line, Location, Period, Sample};
use log::{debug, info, trace, warn};
use std::borrow::{Borrow, Cow};
use std::collections::HashMap;
use std::hash::Hash;
use std::intrinsics::transmute;
use std::str;
use std::sync::atomic::{AtomicBool, AtomicU32};
use std::sync::{Arc, Barrier, Mutex};
use std::thread::JoinHandle;
use std::time::{Duration, Instant, SystemTime};

const UPLOAD_PERIOD: Duration = Duration::from_secs(67);

// Guide: upload period / upload timeout should give about the order of
// magnitude for the capacity.
const UPLOAD_CHANNEL_CAPACITY: usize = 8;

#[derive(Default)]
struct SampleValues {
    interrupt_count: i64,
    wall_time: i64,
    cpu_time: i64,
    alloc_samples: i64,
    alloc_size: i64,
}

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
    Num(i64, Option<&'static str>),
}

#[derive(Debug, Clone)]
pub struct Label {
    pub key: &'static str,
    pub value: LabelValue,
}

impl<'a> From<&'a Label> for profile::api::Label<'a> {
    fn from(label: &'a Label) -> Self {
        let key = label.key;
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
                num_unit: num_unit.as_deref(),
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
    interrupt_manager: Arc<InterruptManager>,
    message_sender: Sender<ProfilerMessage>,
    time_collector_handle: JoinHandle<()>,
    uploader_handle: JoinHandle<()>,
}

struct TimeCollector {
    fork_barrier: Arc<Barrier>,
    fork_receiver: Receiver<()>,
    interrupt_manager: Arc<InterruptManager>,
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
                warn!("Failed to upload profile: {err}");
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

        let local_root_span_id = message.local_root_span_id;
        for (_, profile) in profiles.iter_mut() {
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
                warn!("Failed to add sample to the profile: {err}")
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
                        self.interrupt_manager.trigger_interrupts()
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

pub struct UploadMessage {
    index: ProfileIndex,
    profile: profile::Profile,
    end_time: SystemTime,
    duration: Option<Duration>,
}

impl Profiler {
    pub fn new() -> Self {
        let fork_barrier = Arc::new(Barrier::new(3));
        let (fork_sender0, fork_receiver0) = crossbeam_channel::bounded(1);
        let interrupt_manager = Arc::new(InterruptManager::new(Mutex::new(Vec::with_capacity(1))));
        let (message_sender, message_receiver) = crossbeam_channel::bounded(100);
        let (upload_sender, upload_receiver) = crossbeam_channel::bounded(UPLOAD_CHANNEL_CAPACITY);
        let (fork_sender1, fork_receiver1) = crossbeam_channel::bounded(1);
        let time_collector = TimeCollector {
            fork_barrier: fork_barrier.clone(),
            fork_receiver: fork_receiver0,
            interrupt_manager: interrupt_manager.clone(),
            message_receiver,
            upload_sender,
            wall_time_period: WALL_TIME_PERIOD,
            upload_period: UPLOAD_PERIOD,
        };

        let uploader = Uploader::new(fork_barrier.clone(), fork_receiver1, upload_receiver);
        Profiler {
            fork_barrier,
            fork_senders: [fork_sender0, fork_sender1],
            interrupt_manager,
            message_sender,
            time_collector_handle: thread_utils::spawn("ddprof_time", move || time_collector.run()),
            uploader_handle: thread_utils::spawn("ddprof_upload", move || uploader.run()),
        }
    }

    pub fn add_interrupt(&self, interrupt: VmInterrupt) -> Result<(), (usize, VmInterrupt)> {
        self.interrupt_manager.add_interrupt(interrupt)
    }

    pub fn remove_interrupt(&self, interrupt: VmInterrupt) -> Result<(), VmInterrupt> {
        self.interrupt_manager.remove_interrupt(interrupt)
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
        debug!("Stopping profiler.");
        match self.message_sender.send(ProfilerMessage::Cancel) {
            Err(err) => warn!("Failed to notify other threads of cancellation: {err}."),
            Ok(_) => debug!("Notified other threads of cancellation."),
        }

        let timeout = Duration::from_secs(2);
        thread_utils::join_timeout(
            self.time_collector_handle,
            timeout,
            "Recent samples may be lost.",
        );

        // Wait for the time_collector to join, since that will drop
        // the sender half of the channel that the uploader is
        // holding, allowing it to finish.
        thread_utils::join_timeout(
            self.uploader_handle,
            timeout,
            "Recent samples are most likely lost.",
        );
    }

    fn cpu_sub(now: cpu_time::ThreadTime, prev: cpu_time::ThreadTime) -> i64 {
        let now = now.as_duration();
        let prev = prev.as_duration();

        match now.checked_sub(prev) {
            // If a 128 bit value doesn't fit in 64 bits, use the max.
            Some(duration) => duration.as_nanos().try_into().unwrap_or(i64::MAX),

            // If this happened, then either the programmer screwed up and
            // passed args in backwards, or cpu time has gone backward... ish.
            // Supposedly it can happen if the thread migrates CPUs:
            // https://www.percona.com/blog/what-time-18446744073709550000-means/
            // Regardless of why, a customer hit this:
            // https://github.com/DataDog/dd-trace-php/issues/1880
            // In these cases, zero is much closer to reality than i64::MAX.
            None => 0,
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

                let now = Instant::now();
                let wall_time = now.duration_since(locals.last_wall_time);
                locals.last_wall_time = now;
                let wall_time: i64 = wall_time.as_nanos().try_into().unwrap_or(i64::MAX);

                /* If CPU time is disabled, or if it's enabled but not available on the platform,
                 * then `locals.last_cpu_time` will be None.
                 */
                let cpu_time = if let Some(last_cpu_time) = locals.last_cpu_time {
                    let now = cpu_time::ThreadTime::try_now()
                        .expect("CPU time to work since it's worked before during this process");
                    let cpu_time = Self::cpu_sub(now, last_cpu_time);
                    locals.last_cpu_time = Some(now);
                    cpu_time
                } else {
                    0
                };

                let labels = Profiler::message_labels();

                match self.send_sample(Profiler::prepare_sample_message(
                    frames,
                    SampleValues {
                        interrupt_count,
                        wall_time,
                        cpu_time,
                        ..Default::default()
                    },
                    &labels,
                    locals,
                )) {
                    Ok(_) => trace!(
                        "Sent stack sample of depth {depth} with labels {labels:?} to profiler."
                    ),
                    Err(err) => warn!(
                        "Failed to send stack sample of depth {depth} with labels {labels:?} to profiler: {err}"
                    ),
                }
            }
            Err(err) => {
                warn!("Failed to collect stack sample: {err}")
            }
        }
    }

    /// Collect a stack sample with memory allocations
    #[cfg(feature = "allocation_profiling")]
    pub unsafe fn collect_allocations(
        &self,
        execute_data: *mut zend_execute_data,
        alloc_samples: i64,
        alloc_size: i64,
        locals: &RequestLocals,
    ) {
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(frames) => {
                let depth = frames.len();
                let labels = Profiler::message_labels();

                match self.send_sample(Profiler::prepare_sample_message(
                    frames,
                    SampleValues {
                        alloc_size,
                        alloc_samples,
                        ..Default::default()
                    },
                    &labels,
                    locals
                )) {
                    Ok(_) => trace!(
                        "Sent stack sample of depth {depth} with size {alloc_size}, labels {labels:?} and count {alloc_samples} to profiler."
                    ),
                    Err(err) => warn!(
                        "Failed to send stack sample of depth {depth} with size {alloc_size}, labels {labels:?} and count {alloc_samples} to profiler: {err}"
                    ),
                }
            }
            Err(err) => {
                warn!("Failed to collect stack sample: {err}")
            }
        }
    }

    fn message_labels() -> Vec<Label> {
        let mut labels = vec![];
        let gpc = unsafe { datadog_php_profiling_get_profiling_context };
        if let Some(get_profiling_context) = gpc {
            let context = unsafe { get_profiling_context() };
            if context.local_root_span_id != 0 {
                /* Safety: PProf only has signed integers for label.num.
                 * We bit-cast u64 to i64, and the backend does the
                 * reverse so the conversion is lossless.
                 */
                let local_root_span_id: i64 = unsafe { transmute(context.local_root_span_id) };
                let span_id: i64 = unsafe { transmute(context.span_id) };

                labels.push(Label {
                    key: "local root span id",
                    value: LabelValue::Num(local_root_span_id, None),
                });
                labels.push(Label {
                    key: "span id",
                    value: LabelValue::Num(span_id, None),
                });
            }
        }
        labels
    }

    fn prepare_sample_message(
        frames: Vec<ZendFrame>,
        samples: SampleValues,
        labels: &[Label],
        locals: &RequestLocals,
    ) -> SampleMessage {
        let mut sample_types = vec![];
        let mut sample_values = vec![];

        if locals.profiling_enabled {
            sample_types.push(ValueType {
                r#type: Cow::Borrowed("sample"),
                unit: Cow::Borrowed("count"),
            });
            sample_types.push(ValueType {
                r#type: Cow::Borrowed("wall-time"),
                unit: Cow::Borrowed("nanoseconds"),
            });
            sample_values.push(samples.interrupt_count);
            sample_values.push(samples.wall_time);
        }

        if locals.profiling_experimental_allocation_enabled {
            sample_types.push(ValueType {
                r#type: Cow::Borrowed("alloc-samples"),
                unit: Cow::Borrowed("count"),
            });
            sample_types.push(ValueType {
                r#type: Cow::Borrowed("alloc-size"),
                unit: Cow::Borrowed("bytes"),
            });
            sample_values.push(samples.alloc_samples);
            sample_values.push(samples.alloc_size);
        }

        if locals.profiling_experimental_cpu_time_enabled {
            sample_types.push(ValueType {
                r#type: Cow::Borrowed("cpu-time"),
                unit: Cow::Borrowed("nanoseconds"),
            });
            sample_values.push(samples.cpu_time);
        }

        let mut tags = locals.tags.clone();

        if let Some(version) = PHP_VERSION.get() {
            /* This should probably be "language_version", but this is
             * the tag that was standardized for this purpose. */
            let tag =
                Tag::new("runtime_version", version).expect("runtime_version to be a valid tag");
            tags.push(tag);
        }

        if let Some(sapi) = crate::SAPI.get() {
            match Tag::new("php.sapi", sapi.to_string()) {
                Ok(tag) => tags.push(tag),
                Err(err) => warn!("Tag error: {err}"),
            }
        }

        SampleMessage {
            key: ProfileIndex {
                sample_types,
                tags,
                endpoint: locals.uri.clone(),
            },
            value: SampleData {
                frames,
                labels: labels.to_vec(),
                sample_values,
            },
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::static_tags;
    use log::LevelFilter;

    fn get_frames() -> Vec<ZendFrame> {
        vec![ZendFrame {
            function: "foobar()".to_string(),
            file: Some("foobar.php".to_string()),
            line: 42,
        }]
    }

    fn get_request_locals() -> RequestLocals {
        RequestLocals {
            env: None,
            interrupt_count: AtomicU32::new(0),
            last_cpu_time: None,
            last_wall_time: Instant::now(),
            profiling_enabled: true,
            profiling_endpoint_collection_enabled: true,
            profiling_experimental_cpu_time_enabled: false,
            profiling_experimental_allocation_enabled: false,
            profiling_log_level: LevelFilter::Off,
            service: None,
            tags: static_tags(),
            uri: Box::new(AgentEndpoint::default()),
            version: None,
            vm_interrupt_addr: std::ptr::null_mut(),
        }
    }

    fn get_samples() -> SampleValues {
        SampleValues {
            interrupt_count: 10,
            wall_time: 20,
            cpu_time: 30,
            alloc_samples: 40,
            alloc_size: 50,
        }
    }

    #[test]
    fn profiler_prepare_sample_message_works_with_profiling_disabled() {
        // the `Profiler::prepare_sample_message()` method will never be called with this setup,
        // yet this is how it has to behave in case profiling is disabled
        let frames = get_frames();
        let samples = get_samples();
        let labels = Profiler::message_labels();
        let mut locals = get_request_locals();
        locals.profiling_enabled = false;
        locals.profiling_experimental_allocation_enabled = false;
        locals.profiling_experimental_cpu_time_enabled = false;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, &labels, &locals);

        assert_eq!(message.key.sample_types, vec![]);
        let expected: Vec<i64> = vec![];
        assert_eq!(message.value.sample_values, expected);
    }

    #[test]
    fn profiler_prepare_sample_message_works_with_profiling_enabled() {
        let frames = get_frames();
        let samples = get_samples();
        let labels = Profiler::message_labels();
        let mut locals = get_request_locals();
        locals.profiling_enabled = true;
        locals.profiling_experimental_allocation_enabled = false;
        locals.profiling_experimental_cpu_time_enabled = false;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, &labels, &locals);

        assert_eq!(
            message.key.sample_types,
            vec![
                ValueType {
                    r#type: Cow::Borrowed("sample"),
                    unit: Cow::Borrowed("count"),
                },
                ValueType {
                    r#type: Cow::Borrowed("wall-time"),
                    unit: Cow::Borrowed("nanoseconds"),
                },
            ]
        );
        assert_eq!(message.value.sample_values, vec![10, 20]);
    }

    #[test]
    fn profiler_prepare_sample_message_works_with_cpu_time() {
        let frames = get_frames();
        let samples = get_samples();
        let labels = Profiler::message_labels();
        let mut locals = get_request_locals();
        locals.profiling_enabled = true;
        locals.profiling_experimental_allocation_enabled = false;
        locals.profiling_experimental_cpu_time_enabled = true;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, &labels, &locals);

        assert_eq!(
            message.key.sample_types,
            vec![
                ValueType {
                    r#type: Cow::Borrowed("sample"),
                    unit: Cow::Borrowed("count"),
                },
                ValueType {
                    r#type: Cow::Borrowed("wall-time"),
                    unit: Cow::Borrowed("nanoseconds"),
                },
                ValueType {
                    r#type: Cow::Borrowed("cpu-time"),
                    unit: Cow::Borrowed("nanoseconds"),
                },
            ]
        );
        assert_eq!(message.value.sample_values, vec![10, 20, 30]);
    }

    #[test]
    fn profiler_prepare_sample_message_works_with_allocations() {
        let frames = get_frames();
        let samples = get_samples();
        let labels = Profiler::message_labels();
        let mut locals = get_request_locals();
        locals.profiling_enabled = true;
        locals.profiling_experimental_allocation_enabled = true;
        locals.profiling_experimental_cpu_time_enabled = false;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, &labels, &locals);

        assert_eq!(
            message.key.sample_types,
            vec![
                ValueType {
                    r#type: Cow::Borrowed("sample"),
                    unit: Cow::Borrowed("count"),
                },
                ValueType {
                    r#type: Cow::Borrowed("wall-time"),
                    unit: Cow::Borrowed("nanoseconds"),
                },
                ValueType {
                    r#type: Cow::Borrowed("alloc-samples"),
                    unit: Cow::Borrowed("count"),
                },
                ValueType {
                    r#type: Cow::Borrowed("alloc-size"),
                    unit: Cow::Borrowed("bytes"),
                },
            ]
        );
        assert_eq!(message.value.sample_values, vec![10, 20, 40, 50]);
    }

    #[test]
    fn profiler_prepare_sample_message_works_with_allocations_and_cpu_time() {
        let frames = get_frames();
        let samples = get_samples();
        let labels = Profiler::message_labels();
        let mut locals = get_request_locals();
        locals.profiling_enabled = true;
        locals.profiling_experimental_allocation_enabled = true;
        locals.profiling_experimental_cpu_time_enabled = true;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, &labels, &locals);

        assert_eq!(
            message.key.sample_types,
            vec![
                ValueType {
                    r#type: Cow::Borrowed("sample"),
                    unit: Cow::Borrowed("count"),
                },
                ValueType {
                    r#type: Cow::Borrowed("wall-time"),
                    unit: Cow::Borrowed("nanoseconds"),
                },
                ValueType {
                    r#type: Cow::Borrowed("alloc-samples"),
                    unit: Cow::Borrowed("count"),
                },
                ValueType {
                    r#type: Cow::Borrowed("alloc-size"),
                    unit: Cow::Borrowed("bytes"),
                },
                ValueType {
                    r#type: Cow::Borrowed("cpu-time"),
                    unit: Cow::Borrowed("nanoseconds"),
                },
            ]
        );
        assert_eq!(message.value.sample_values, vec![10, 20, 40, 50, 30]);
    }
}
