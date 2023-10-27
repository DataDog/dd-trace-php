mod interrupts;
pub mod stalk_walking;
mod thread_utils;
mod uploader;

pub use interrupts::*;
pub use stalk_walking::*;
use uploader::*;

#[cfg(all(php_has_fibers, not(test)))]
use crate::bindings::ddog_php_prof_get_active_fiber;
#[cfg(all(php_has_fibers, test))]
use crate::bindings::ddog_php_prof_get_active_fiber_test as ddog_php_prof_get_active_fiber;

use crate::bindings::{datadog_php_profiling_get_profiling_context, zend_execute_data};
use crate::{AgentEndpoint, RequestLocals, CLOCKS, TAGS};
use crossbeam_channel::{Receiver, Sender, TrySendError};
use datadog_profiling::api::{
    Function, Label as ApiLabel, Location, Period, Sample, ValueType as ApiValueType,
};
use datadog_profiling::exporter::Tag;
use datadog_profiling::internal::Profile as InternalProfile;
use log::{debug, error, info, trace, warn};
use std::borrow::Cow;
use std::collections::HashMap;
use std::hash::Hash;
use std::intrinsics::transmute;
use std::num::NonZeroI64;
use std::str;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Barrier};
use std::thread::JoinHandle;
use std::time::{Duration, Instant, SystemTime};

#[cfg(feature = "timeline")]
use lazy_static::lazy_static;
#[cfg(feature = "timeline")]
use std::time::UNIX_EPOCH;

#[cfg(feature = "allocation_profiling")]
use crate::allocation::ALLOCATION_PROFILING_INTERVAL;
#[cfg(feature = "allocation_profiling")]
use datadog_profiling::api::UpscalingInfo;

#[cfg(feature = "exception_profiling")]
use crate::exception::EXCEPTION_PROFILING_INTERVAL;

const UPLOAD_PERIOD: Duration = Duration::from_secs(67);

#[cfg(feature = "timeline")]
pub const NO_TIMESTAMP: i64 = 0;

// Guide: upload period / upload timeout should give about the order of
// magnitude for the capacity.
const UPLOAD_CHANNEL_CAPACITY: usize = 8;

/// Order this array this way:
///  1. Always enabled types.
///  2. On by default types.
///  3. Off by default types.
#[derive(Default)]
struct SampleValues {
    interrupt_count: i64,
    wall_time: i64,
    cpu_time: i64,
    alloc_samples: i64,
    alloc_size: i64,
    timeline: i64,
    exception: i64,
}

const WALL_TIME_PERIOD: Duration = Duration::from_millis(10);
const WALL_TIME_PERIOD_TYPE: ValueType = ValueType {
    r#type: "wall-time",
    unit: "nanoseconds",
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

impl<'a> From<&'a Label> for ApiLabel<'a> {
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

#[derive(Debug, Clone, Copy, Eq, PartialEq, Hash)]
pub struct ValueType {
    pub r#type: &'static str,
    pub unit: &'static str,
}

impl ValueType {
    pub const fn new(r#type: &'static str, unit: &'static str) -> Self {
        Self { r#type, unit }
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
    pub sample_types: Vec<ValueType>,
    pub tags: Arc<Vec<Tag>>,
    pub endpoint: Box<AgentEndpoint>,
}

#[derive(Debug)]
pub struct SampleData {
    pub frames: Vec<ZendFrame>,
    pub labels: Vec<Label>,
    pub sample_values: Vec<i64>,
    pub timestamp: i64,
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

    /// Used to put the helper thread into a barrier for caller so it can fork.
    Pause,

    /// Used to wake the helper thread so it can synchronize the fact a
    /// request is being served.
    Wake,
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

pub struct Profiler {
    fork_barrier: Arc<Barrier>,
    interrupt_manager: Arc<InterruptManager>,
    message_sender: Sender<ProfilerMessage>,
    upload_sender: Sender<UploadMessage>,
    time_collector_handle: JoinHandle<()>,
    uploader_handle: JoinHandle<()>,
    should_join: AtomicBool,
}

struct TimeCollector {
    fork_barrier: Arc<Barrier>,
    interrupt_manager: Arc<InterruptManager>,
    message_receiver: Receiver<ProfilerMessage>,
    upload_sender: Sender<UploadMessage>,
    upload_period: Duration,
}

impl TimeCollector {
    fn handle_timeout(
        &self,
        profiles: &mut HashMap<ProfileIndex, InternalProfile>,
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
            let message = UploadMessage::Upload(UploadRequest {
                index,
                profile,
                end_time,
                duration,
            });
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
    fn create_profile(message: &SampleMessage, started_at: SystemTime) -> InternalProfile {
        let sample_types: Vec<ApiValueType> = message
            .key
            .sample_types
            .iter()
            .map(|sample_type| ApiValueType {
                r#type: sample_type.r#type,
                unit: sample_type.unit,
            })
            .collect();

        // check if we have the `alloc-size` and `alloc-samples` sample types
        #[cfg(feature = "allocation_profiling")]
        let alloc_samples_offset = sample_types
            .iter()
            .position(|&x| x.r#type == "alloc-samples");
        #[cfg(feature = "allocation_profiling")]
        let alloc_size_offset = sample_types.iter().position(|&x| x.r#type == "alloc-size");

        // check if we have the `exception-samples` sample types
        #[cfg(feature = "exception_profiling")]
        let exception_samples_offset = sample_types
            .iter()
            .position(|&x| x.r#type == "exception-samples");

        let period = WALL_TIME_PERIOD.as_nanos();
        let mut profile = InternalProfile::new(
            started_at,
            &sample_types,
            Some(Period {
                r#type: ApiValueType {
                    r#type: WALL_TIME_PERIOD_TYPE.r#type,
                    unit: WALL_TIME_PERIOD_TYPE.unit,
                },
                value: period.min(i64::MAX as u128) as i64,
            }),
        );

        #[cfg(feature = "allocation_profiling")]
        if let (Some(alloc_size_offset), Some(alloc_samples_offset)) =
            (alloc_size_offset, alloc_samples_offset)
        {
            let upscaling_info = UpscalingInfo::Poisson {
                sum_value_offset: alloc_size_offset,
                count_value_offset: alloc_samples_offset,
                sampling_distance: ALLOCATION_PROFILING_INTERVAL as u64,
            };
            let values_offset = [alloc_size_offset, alloc_samples_offset];
            match profile.add_upscaling_rule(&values_offset, "", "", upscaling_info) {
                Ok(_id) => {}
                Err(err) => {
                    warn!("Failed to add upscaling rule for allocation samples, allocation samples reported will be wrong: {err}")
                }
            }
        }

        #[cfg(feature = "exception_profiling")]
        if let Some(exception_samples_offset) = exception_samples_offset {
            let upscaling_info = UpscalingInfo::Proportional {
                scale: EXCEPTION_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64,
            };
            let values_offset = [exception_samples_offset];
            match profile.add_upscaling_rule(&values_offset, "", "", upscaling_info) {
                Ok(_id) => {}
                Err(err) => {
                    warn!("Failed to add upscaling rule for exception samples, exception samples reported will be wrong: {err}")
                }
            }
        }

        profile
    }

    fn handle_resource_message(
        message: LocalRootSpanResourceMessage,
        profiles: &mut HashMap<ProfileIndex, InternalProfile>,
    ) {
        trace!(
            "Received Endpoint Profiling message for span id {}.",
            message.local_root_span_id
        );

        let local_root_span_id = message.local_root_span_id;
        for (_, profile) in profiles.iter_mut() {
            let endpoint = Cow::Borrowed(message.resource.as_str());
            profile.add_endpoint(local_root_span_id, endpoint.clone());
            profile.add_endpoint_count(endpoint, 1);
        }
    }

    fn handle_sample_message(
        message: SampleMessage,
        profiles: &mut HashMap<ProfileIndex, InternalProfile>,
        started_at: &WallTime,
    ) {
        let profile: &mut InternalProfile = if let Some(value) = profiles.get_mut(&message.key) {
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

        let mut locations = Vec::with_capacity(message.value.frames.len());

        let values = message.value.sample_values;
        let labels: Vec<ApiLabel> = message.value.labels.iter().map(ApiLabel::from).collect();

        for frame in &message.value.frames {
            let location = Location {
                function: Function {
                    name: frame.function.as_ref(),
                    system_name: "",
                    filename: frame.file.as_deref().unwrap_or(""),
                    start_line: 0,
                },
                line: frame.line as i64,
                ..Location::default()
            };

            locations.push(location);
        }

        let sample = Sample {
            locations,
            values,
            labels,
        };

        let timestamp = NonZeroI64::new(message.value.timestamp);

        match profile.add_sample(sample, timestamp) {
            Ok(_id) => {}
            Err(err) => {
                warn!("Failed to add sample to the profile: {err}")
            }
        }
    }

    pub fn run(self) {
        let mut last_wall_export = WallTime::now();
        let mut profiles: HashMap<ProfileIndex, InternalProfile> = HashMap::with_capacity(1);

        debug!(
            "Started with an upload period of {} seconds and approximate wall-time period of {} milliseconds.",
            UPLOAD_PERIOD.as_secs(),
            WALL_TIME_PERIOD.as_millis());

        let wall_timer = crossbeam_channel::tick(WALL_TIME_PERIOD);
        let upload_tick = crossbeam_channel::tick(self.upload_period);
        let never = crossbeam_channel::never();
        let mut running = true;

        while running {
            // The crossbeam_channel::select! doesn't have the ability to
            // optionally recv something. Instead, if the tick channel
            // shouldn't be selected on, then pass the never channel for that
            // iteration instead, keeping the code structure of the recvs the
            // same. Since the never channel will never be ready, this
            // effectively makes that branch optional for that loop iteration.
            let timer = if self.interrupt_manager.has_interrupts() {
                &wall_timer
            } else {
                &never
            };

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
                            },
                            ProfilerMessage::Pause => {
                                // First, wait for every thread to finish what
                                // they are currently doing.
                                self.fork_barrier.wait();
                                // Then, wait for the fork to be completed.
                                self.fork_barrier.wait();
                            },
                            // The purpose is to wake up and sync the state of
                            // the interrupt manager.
                            ProfilerMessage::Wake => {}
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

                recv(timer) -> message => match message {
                    Ok(_) => self.interrupt_manager.trigger_interrupts(),

                    Err(err) => {
                        warn!("{err}");
                        break;
                    },
                },

                recv(upload_tick) -> message => {
                    if message.is_ok() {
                        last_wall_export = self.handle_timeout(&mut profiles, &last_wall_export);
                    }
                },

            }
        }
    }
}

pub struct UploadRequest {
    index: ProfileIndex,
    profile: InternalProfile,
    end_time: SystemTime,
    duration: Option<Duration>,
}

pub enum UploadMessage {
    Pause,
    Upload(UploadRequest),
}

impl Profiler {
    pub fn new(output_pprof: Option<Cow<'static, str>>) -> Self {
        let fork_barrier = Arc::new(Barrier::new(3));
        let interrupt_manager = Arc::new(InterruptManager::new());
        let (message_sender, message_receiver) = crossbeam_channel::bounded(100);
        let (upload_sender, upload_receiver) = crossbeam_channel::bounded(UPLOAD_CHANNEL_CAPACITY);
        let time_collector = TimeCollector {
            fork_barrier: fork_barrier.clone(),
            interrupt_manager: interrupt_manager.clone(),
            message_receiver,
            upload_sender: upload_sender.clone(),
            upload_period: UPLOAD_PERIOD,
        };

        let uploader = Uploader::new(fork_barrier.clone(), upload_receiver, output_pprof);

        let ddprof_time = "ddprof_time";
        let ddprof_upload = "ddprof_upload";
        Profiler {
            fork_barrier,
            interrupt_manager,
            message_sender,
            upload_sender,
            time_collector_handle: thread_utils::spawn(ddprof_time, move || {
                time_collector.run();
                trace!("thread {ddprof_time} complete, shutting down");
            }),
            uploader_handle: thread_utils::spawn(ddprof_upload, move || {
                uploader.run();
                trace!("thread {ddprof_upload} complete, shutting down");
            }),
            should_join: AtomicBool::new(true),
        }
    }

    pub fn add_interrupt(&self, interrupt: VmInterrupt) {
        // First, add the interrupt to the set.
        self.interrupt_manager.add_interrupt(interrupt);

        // Second, make a best-effort attempt to wake the helper thread so
        // that it is aware another PHP request is in flight.
        _ = self.message_sender.try_send(ProfilerMessage::Wake);
    }

    pub fn remove_interrupt(&self, interrupt: VmInterrupt) {
        // First, remove the interrupt to the set.
        self.interrupt_manager.remove_interrupt(interrupt)

        // Second, do not try to wake the helper thread. In NTS mode, the next
        // request may come before the timer expires anyway, and if not, at
        // worst we had 1 wake-up outside of a request, which is the same as
        // if we wake it now.
        // In ZTS mode, this would just be unnecessary wake-ups, as there are
        // likely to be other threads serving requests.
    }

    /// Call before a fork, on the thread of the parent process that will fork.
    pub fn fork_prepare(&self) {
        // Send the message to the uploader first, as it has a longer worst-
        // case time to wait.
        let uploader_result = self.upload_sender.send(UploadMessage::Pause);
        let profiler_result = self.message_sender.send(ProfilerMessage::Pause);

        // todo: handle fails more gracefully, but it's tricky to sync 3
        //       threads, any of which could have crashed or be delayed. This
        //       could also deadlock.
        match (uploader_result, profiler_result) {
            (Ok(_), Ok(_)) => {
                self.fork_barrier.wait();
            }
            (_, _) => {
                error!("failed to prepare the profiler for forking, a deadlock could occur")
            }
        }
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

    /// Begins the shutdown process. To complete it, call [Profiler::shutdown].
    /// Note that you must call [Profiler::shutdown] afterwards; it's two
    /// parts of the same operation. It's split so you (or other extensions)
    /// can do something while the other threads finish up.
    pub fn stop(&mut self, timeout: Duration) {
        debug!("Stopping profiler.");

        let sent = match self
            .message_sender
            .send_timeout(ProfilerMessage::Cancel, timeout)
        {
            Err(err) => {
                warn!("Recent samples are most likely lost: Failed to notify other threads of cancellation: {err}.");
                false
            }
            Ok(_) => {
                debug!("Notified other threads of cancellation.");
                true
            }
        };
        self.should_join.store(sent, Ordering::SeqCst);

        // Drop the sender to the uploader channel to reduce its refcount. At
        // this state, only the ddprof_time thread will have a sender to the
        // uploader. Once the sender over there is closed, then the uploader
        // can quit.
        // The sender is replaced with one that has a disconnected receiver, so
        // the sender can't send any messages.
        let (mut empty_sender, _) = crossbeam_channel::unbounded();
        std::mem::swap(&mut self.upload_sender, &mut empty_sender);
    }

    /// Completes the shutdown process; to start it, call [Profiler::stop]
    /// before calling [Profiler::shutdown].
    /// Note the timeout is per thread, and there may be multiple threads.
    pub fn shutdown(self, timeout: Duration) {
        if self.should_join.load(Ordering::SeqCst) {
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
    }

    /// Collect a stack sample with elapsed wall time. Collects CPU time if
    /// it's enabled and available.
    pub fn collect_time(
        &self,
        execute_data: *mut zend_execute_data,
        interrupt_count: u32,
        locals: &RequestLocals,
    ) {
        // todo: should probably exclude the wall and CPU time used by collecting the sample.
        let interrupt_count = interrupt_count as i64;
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(frames) => {
                let depth = frames.len();
                let (wall_time, cpu_time) = CLOCKS.with(|cell| cell.borrow_mut().rotate_clocks());

                let labels = Profiler::message_labels();
                let mut timestamp = 0;
                #[cfg(feature = "timeline")]
                if locals.profiling_experimental_timeline_enabled {
                    if let Ok(now) = SystemTime::now().duration_since(UNIX_EPOCH) {
                        timestamp = now.as_nanos() as i64;
                    }
                }

                let n_labels = labels.len();

                match self.send_sample(Profiler::prepare_sample_message(
                    frames,
                    SampleValues {
                        interrupt_count,
                        wall_time,
                        cpu_time,
                        ..Default::default()
                    },
                    labels,
                    locals,
                    timestamp,
                )) {
                    Ok(_) => trace!(
                        "Sent stack sample of {depth} frames and {n_labels} labels to profiler."
                    ),
                    Err(err) => warn!(
                        "Failed to send stack sample of {depth} frames and {n_labels} labels to profiler: {err}"
                    ),
                }
            }
            Err(err) => {
                warn!("Failed to collect stack sample: {err}")
            }
        }
    }

    #[cfg(feature = "allocation_profiling")]
    /// Collect a stack sample with memory allocations
    pub fn collect_allocations(
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
                let n_labels = labels.len();

                match self.send_sample(Profiler::prepare_sample_message(
                    frames,
                    SampleValues {
                        alloc_size,
                        alloc_samples,
                        ..Default::default()
                    },
                    labels,
                    locals,
                    NO_TIMESTAMP,
                )) {
                    Ok(_) => trace!(
                        "Sent stack sample of {depth} frames, {n_labels} labels, {alloc_size} bytes allocated, and {alloc_samples} allocations to profiler."
                    ),
                    Err(err) => warn!(
                        "Failed to send stack sample of {depth} frames, {n_labels} labels, {alloc_size} bytes allocated, and {alloc_samples} allocations to profiler: {err}"
                    ),
                }
            }
            Err(err) => {
                warn!("Failed to collect stack sample: {err}")
            }
        }
    }

    #[cfg(feature = "exception_profiling")]
    /// Collect a stack sample with exception
    pub unsafe fn collect_exception(
        &self,
        execute_data: *mut zend_execute_data,
        exception: String,
        locals: &RequestLocals,
    ) {
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(frames) => {
                let depth = frames.len();
                let mut labels = Profiler::message_labels();

                labels.push(Label {
                    key: "exception type",
                    value: LabelValue::Str(exception.clone().into()),
                });
                let n_labels = labels.len();

                match self.send_sample(Profiler::prepare_sample_message(
                    frames,
                    SampleValues {
                        exception: 1,
                        ..Default::default()
                    },
                    labels,
                    locals,
                    NO_TIMESTAMP,
                )) {
                    Ok(_) => trace!(
                        "Sent stack sample of {depth} frames, {n_labels} labels with Exception {exception} to profiler."
                    ),
                    Err(err) => warn!(
                        "Failed to send stack sample of {depth} frames, {n_labels} labels with Exception {exception} to profiler: {err}"
                    ),
                }
            }
            Err(err) => {
                warn!("Failed to collect stack sample: {err}")
            }
        }
    }

    #[cfg(feature = "timeline")]
    pub fn collect_compile_string(
        &self,
        now: i64,
        duration: i64,
        filename: String,
        line: u32,
        locals: &RequestLocals,
    ) {
        let mut labels = Profiler::message_labels();

        lazy_static! {
            static ref TIMELINE_COMPILE_FILE_LABELS: Vec<Label> = vec![Label {
                key: "event",
                value: LabelValue::Str("compilation".into()),
            },];
        }

        labels.extend_from_slice(&TIMELINE_COMPILE_FILE_LABELS);
        let n_labels = labels.len();

        match self.send_sample(Profiler::prepare_sample_message(
            vec![ZendFrame {
                function: COW_EVAL,
                file: Some(filename),
                line,
            }],
            SampleValues {
                timeline: duration,
                ..Default::default()
            },
            labels,
            locals,
            now,
        )) {
            Ok(_) => {
                trace!("Sent event 'compile eval' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!(
                    "Failed to send event 'compile eval' with {n_labels} labels to profiler: {err}"
                )
            }
        }
    }

    #[cfg(feature = "timeline")]
    pub fn collect_compile_file(
        &self,
        now: i64,
        duration: i64,
        filename: String,
        include_type: &str,
        locals: &RequestLocals,
    ) {
        let mut labels = Profiler::message_labels();

        lazy_static! {
            static ref TIMELINE_COMPILE_FILE_LABELS: Vec<Label> = vec![Label {
                key: "event",
                value: LabelValue::Str("compilation".into()),
            },];
        }

        labels.extend_from_slice(&TIMELINE_COMPILE_FILE_LABELS);
        labels.push(Label {
            key: "filename",
            value: LabelValue::Str(Cow::from(filename)),
        });

        let n_labels = labels.len();

        match self.send_sample(Profiler::prepare_sample_message(
            vec![ZendFrame {
                function: format!("[{include_type}]").into(),
                file: None,
                line: 0,
            }],
            SampleValues {
                timeline: duration,
                ..Default::default()
            },
            labels,
            locals,
            now,
        )) {
            Ok(_) => {
                trace!("Sent event 'compile file' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!(
                    "Failed to send event 'compile file' with {n_labels} labels to profiler: {err}"
                )
            }
        }
    }

    #[cfg(feature = "timeline")]
    /// This function can be called to collect any kind of inactivity that is happening
    pub fn collect_idle(
        &self,
        now: i64,
        duration: i64,
        reason: &'static str,
        locals: &RequestLocals,
    ) {
        let mut labels = Profiler::message_labels();

        labels.push(Label {
            key: "event",
            value: LabelValue::Str(reason.into()),
        });

        let n_labels = labels.len();

        match self.send_sample(Profiler::prepare_sample_message(
            vec![ZendFrame {
                function: "[idle]".into(),
                file: None,
                line: 0,
            }],
            SampleValues {
                timeline: duration,
                ..Default::default()
            },
            labels,
            locals,
            now,
        )) {
            Ok(_) => {
                trace!("Sent event 'idle' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!("Failed to send event 'idle' with {n_labels} labels to profiler: {err}")
            }
        }
    }

    #[cfg(feature = "timeline")]
    /// collect a stack frame for garbage collection.
    /// as we do not know about the overhead currently, we only collect a fake frame.
    pub fn collect_garbage_collection(
        &self,
        now: i64,
        duration: i64,
        reason: &'static str,
        collected: i64,
        #[cfg(php_gc_status)] runs: i64,
        locals: &RequestLocals,
    ) {
        let mut labels = Profiler::message_labels();

        lazy_static! {
            static ref TIMELINE_GC_LABELS: Vec<Label> = vec![Label {
                key: "event",
                value: LabelValue::Str("gc".into()),
            },];
        }

        labels.extend_from_slice(&TIMELINE_GC_LABELS);
        labels.push(Label {
            key: "gc reason",
            value: LabelValue::Str(Cow::from(reason)),
        });

        #[cfg(php_gc_status)]
        labels.push(Label {
            key: "gc runs",
            value: LabelValue::Num(runs, Some("count")),
        });
        labels.push(Label {
            key: "gc collected",
            value: LabelValue::Num(collected, Some("count")),
        });
        let n_labels = labels.len();

        match self.send_sample(Profiler::prepare_sample_message(
            vec![ZendFrame {
                function: "[gc]".into(),
                file: None,
                line: 0,
            }],
            SampleValues {
                timeline: duration,
                ..Default::default()
            },
            labels,
            locals,
            now,
        )) {
            Ok(_) => {
                trace!("Sent event 'gc' with {n_labels} labels and reason {reason} to profiler.")
            }
            Err(err) => {
                warn!("Failed to send event 'gc' with {n_labels} and reason {reason} labels to profiler: {err}")
            }
        }
    }

    fn message_labels() -> Vec<Label> {
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

                return vec![
                    Label {
                        key: "local root span id",
                        value: LabelValue::Num(local_root_span_id, None),
                    },
                    Label {
                        key: "span id",
                        value: LabelValue::Num(span_id, None),
                    },
                ];
            }
        }
        vec![]
    }

    fn prepare_sample_message(
        frames: Vec<ZendFrame>,
        samples: SampleValues,
        #[cfg(php_has_fibers)] mut labels: Vec<Label>,
        #[cfg(not(php_has_fibers))] labels: Vec<Label>,
        locals: &RequestLocals,
        timestamp: i64,
    ) -> SampleMessage {
        // Lay this out in the same order as SampleValues
        static SAMPLE_TYPES: &[ValueType; 7] = &[
            ValueType::new("sample", "count"),
            ValueType::new("wall-time", "nanoseconds"),
            ValueType::new("cpu-time", "nanoseconds"),
            ValueType::new("alloc-samples", "count"),
            ValueType::new("alloc-size", "bytes"),
            ValueType::new("timeline", "nanoseconds"),
            ValueType::new("exception-samples", "count"),
        ];

        // Allows us to slice the SampleValues as if they were an array.
        let values: [i64; 7] = [
            samples.interrupt_count,
            samples.wall_time,
            samples.cpu_time,
            samples.alloc_samples,
            samples.alloc_size,
            samples.timeline,
            samples.exception,
        ];

        let mut sample_types = Vec::with_capacity(SAMPLE_TYPES.len());
        let mut sample_values = Vec::with_capacity(SAMPLE_TYPES.len());
        if locals.profiling_enabled {
            // sample, wall-time, cpu-time
            let len = 2 + locals.profiling_experimental_cpu_time_enabled as usize;
            sample_types.extend_from_slice(&SAMPLE_TYPES[0..len]);
            sample_values.extend_from_slice(&values[0..len]);

            // alloc-samples, alloc-size
            if locals.profiling_allocation_enabled {
                sample_types.extend_from_slice(&SAMPLE_TYPES[3..5]);
                sample_values.extend_from_slice(&values[3..5]);
            }

            #[cfg(feature = "timeline")]
            if locals.profiling_experimental_timeline_enabled {
                sample_types.push(SAMPLE_TYPES[5]);
                sample_values.push(values[5]);
            }

            #[cfg(feature = "exception_profiling")]
            if locals.profiling_experimental_exception_enabled {
                sample_types.push(SAMPLE_TYPES[6]);
                sample_values.push(values[6]);
            }

            #[cfg(php_has_fibers)]
            if let Some(fiber) = unsafe { ddog_php_prof_get_active_fiber().as_mut() } {
                // Safety: the fcc is set by Fiber::__construct as part of zpp,
                // which will always set the function_handler on success, and
                // there's nothing changing that value in all of fibers
                // afterwards, from start to destruction of the fiber itself.
                let func = unsafe { &*fiber.fci_cache.function_handler };
                if let Some(functionname) = extract_function_name(func) {
                    labels.push(Label {
                        key: "fiber",
                        value: LabelValue::Str(functionname.into()),
                    });
                }
            }
        }

        let tags = TAGS.with(|cell| Arc::clone(&cell.borrow()));

        SampleMessage {
            key: ProfileIndex {
                sample_types,
                tags,
                endpoint: locals.uri.clone(),
            },
            value: SampleData {
                frames,
                labels,
                sample_values,
                timestamp,
            },
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use log::LevelFilter;

    fn get_frames() -> Vec<ZendFrame> {
        vec![ZendFrame {
            function: "foobar()".into(),
            file: Some("foobar.php".into()),
            line: 42,
        }]
    }

    fn get_request_locals() -> RequestLocals {
        RequestLocals {
            env: None,
            interrupt_count: AtomicU32::new(0),
            profiling_enabled: true,
            profiling_endpoint_collection_enabled: true,
            profiling_experimental_cpu_time_enabled: false,
            profiling_allocation_enabled: false,
            profiling_experimental_timeline_enabled: false,
            profiling_experimental_exception_enabled: false,
            profiling_experimental_exception_sampling_distance: 1,
            profiling_log_level: LevelFilter::Off,
            service: None,
            uri: Box::<AgentEndpoint>::default(),
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
            timeline: 60,
            exception: 70,
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
        locals.profiling_allocation_enabled = false;
        locals.profiling_experimental_cpu_time_enabled = false;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, labels, &locals, NO_TIMESTAMP);

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
        locals.profiling_allocation_enabled = false;
        locals.profiling_experimental_cpu_time_enabled = false;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, labels, &locals, NO_TIMESTAMP);

        assert_eq!(
            message.key.sample_types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
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
        locals.profiling_allocation_enabled = false;
        locals.profiling_experimental_cpu_time_enabled = true;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, labels, &locals, NO_TIMESTAMP);

        assert_eq!(
            message.key.sample_types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
                ValueType::new("cpu-time", "nanoseconds"),
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
        locals.profiling_allocation_enabled = true;
        locals.profiling_experimental_cpu_time_enabled = false;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, labels, &locals, NO_TIMESTAMP);

        assert_eq!(
            message.key.sample_types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
                ValueType::new("alloc-samples", "count"),
                ValueType::new("alloc-size", "bytes"),
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
        locals.profiling_allocation_enabled = true;
        locals.profiling_experimental_cpu_time_enabled = true;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, labels, &locals, NO_TIMESTAMP);

        assert_eq!(
            message.key.sample_types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
                ValueType::new("cpu-time", "nanoseconds"),
                ValueType::new("alloc-samples", "count"),
                ValueType::new("alloc-size", "bytes"),
            ]
        );
        assert_eq!(message.value.sample_values, vec![10, 20, 30, 40, 50]);
    }

    #[test]
    #[cfg(feature = "timeline")]
    fn profiler_prepare_sample_message_works_cpu_time_and_timeline() {
        let frames = get_frames();
        let samples = get_samples();
        let labels = Profiler::message_labels();
        let mut locals = get_request_locals();
        locals.profiling_enabled = true;
        locals.profiling_experimental_cpu_time_enabled = true;
        locals.profiling_experimental_timeline_enabled = true;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, labels, &locals, 900);

        assert_eq!(
            message.key.sample_types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
                ValueType::new("cpu-time", "nanoseconds"),
                ValueType::new("timeline", "nanoseconds"),
            ]
        );
        assert_eq!(message.value.sample_values, vec![10, 20, 30, 60]);
        assert_eq!(message.value.timestamp, 900);
    }

    #[test]
    #[cfg(feature = "exception_profiling")]
    fn profiler_prepare_sample_message_works_cpu_time_and_expceptions() {
        let frames = get_frames();
        let samples = get_samples();
        let labels = Profiler::message_labels();
        let mut locals = get_request_locals();
        locals.profiling_enabled = true;
        locals.profiling_experimental_cpu_time_enabled = true;
        locals.profiling_experimental_exception_enabled = true;

        let message: SampleMessage =
            Profiler::prepare_sample_message(frames, samples, labels, &locals, NO_TIMESTAMP);

        assert_eq!(
            message.key.sample_types,
            vec![
                ValueType::new("sample", "count"),
                ValueType::new("wall-time", "nanoseconds"),
                ValueType::new("cpu-time", "nanoseconds"),
                ValueType::new("exception-samples", "count"),
            ]
        );
        assert_eq!(message.value.sample_values, vec![10, 20, 30, 70]);
    }
}
