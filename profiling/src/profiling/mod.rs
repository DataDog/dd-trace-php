mod interrupts;
mod sample_type_filter;
pub mod stack_walking;
mod thread_utils;
mod uploader;

pub use interrupts::*;
pub use sample_type_filter::*;
pub use stack_walking::*;
use thread_utils::get_current_thread_name;
use uploader::*;

#[cfg(all(php_has_fibers, not(test)))]
use crate::bindings::ddog_php_prof_get_active_fiber;
#[cfg(all(php_has_fibers, test))]
use crate::bindings::ddog_php_prof_get_active_fiber_test as ddog_php_prof_get_active_fiber;

use crate::allocation::ALLOCATION_PROFILING_INTERVAL;
use crate::bindings::{datadog_php_profiling_get_profiling_context, zend_execute_data};
use crate::config::SystemSettings;
use crate::exception::EXCEPTION_PROFILING_INTERVAL;
use crate::{Clocks, CLOCKS, TAGS};
use chrono::Utc;
use core::mem::forget;
use core::{ptr, str};
use crossbeam_channel::{Receiver, Sender, TrySendError};
use crossbeam_queue::ArrayQueue;
use datadog_profiling::api::{
    Function, Label as ApiLabel, Location, Period, Sample, UpscalingInfo, ValueType as ApiValueType,
};
use datadog_profiling::exporter::Tag;
use datadog_profiling::internal::Profile as InternalProfile;
use log::{debug, info, trace, warn};
use once_cell::sync::OnceCell;
use std::borrow::Cow;
use std::cell::Cell;
use std::collections::HashMap;
use std::hash::Hash;
use std::num::NonZeroI64;
use std::sync::atomic::{AtomicBool, AtomicPtr, AtomicU32, Ordering};
use std::sync::{Arc, Barrier};
use std::thread::JoinHandle;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

#[cfg(all(target_os = "linux", feature = "io_profiling"))]
use crate::io::{
    FILE_READ_SIZE_PROFILING_INTERVAL, FILE_READ_TIME_PROFILING_INTERVAL,
    FILE_WRITE_SIZE_PROFILING_INTERVAL, FILE_WRITE_TIME_PROFILING_INTERVAL,
    SOCKET_READ_SIZE_PROFILING_INTERVAL, SOCKET_READ_TIME_PROFILING_INTERVAL,
    SOCKET_WRITE_SIZE_PROFILING_INTERVAL, SOCKET_WRITE_TIME_PROFILING_INTERVAL,
};

const UPLOAD_PERIOD: Duration = Duration::from_secs(67);

pub const NO_TIMESTAMP: i64 = 0;

// Guide: upload period / upload timeout should give about the order of
// magnitude for the capacity.
const UPLOAD_CHANNEL_CAPACITY: usize = 8;

/// The global profiler. Profiler gets made during the first rinit after an
/// minit, and is destroyed on mshutdown.
static mut PROFILER: OnceCell<Profiler> = OnceCell::new();

/// Order this array this way:
///  1. Always enabled types.
///  2. On by default types.
///  3. Off by default types.
#[derive(Default, Debug)]
pub struct SampleValues {
    interrupt_count: i64,
    wall_time: i64,
    cpu_time: i64,
    alloc_samples: i64,
    alloc_size: i64,
    timeline: i64,
    exception: i64,
    socket_read_time: i64,
    socket_read_time_samples: i64,
    socket_write_time: i64,
    socket_write_time_samples: i64,
    file_read_time: i64,
    file_read_time_samples: i64,
    file_write_time: i64,
    file_write_time_samples: i64,
    socket_read_size: i64,
    socket_read_size_samples: i64,
    socket_write_size: i64,
    socket_write_size_samples: i64,
    file_read_size: i64,
    file_read_size_samples: i64,
    file_write_size: i64,
    file_write_size_samples: i64,
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
    Num(i64, &'static str),
}

#[derive(Debug, Clone)]
pub struct Label {
    pub key: &'static str,
    pub value: LabelValue,
}

impl<'a> From<&'a Label> for ApiLabel<'a> {
    fn from(label: &'a Label) -> Self {
        let key = label.key;
        match label.value {
            LabelValue::Str(ref str) => Self {
                key,
                str,
                num: 0,
                num_unit: "",
            },
            LabelValue::Num(num, num_unit) => Self {
                key,
                str: "",
                num,
                num_unit,
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
    ProcessQueue,
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
    sample_queue: Arc<ArrayQueue<SampleMessage>>,
    message_sender: Sender<ProfilerMessage>,
    upload_sender: Sender<UploadMessage>,
    time_collector_handle: JoinHandle<()>,
    uploader_handle: JoinHandle<()>,
    should_join: AtomicBool,
    sample_types_filter: SampleTypeFilter,
    system_settings: AtomicPtr<SystemSettings>,
}

struct TimeCollector {
    fork_barrier: Arc<Barrier>,
    interrupt_manager: Arc<InterruptManager>,
    sample_queue: Arc<ArrayQueue<SampleMessage>>,
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
        // Process pending samples before we upload.
        Self::process_queue(&self.sample_queue, profiles, last_export);

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
            let message = UploadMessage::Upload(Box::new(UploadRequest {
                index,
                profile,
                end_time,
                duration,
            }));
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

        let get_offset = |sample_type| sample_types.iter().position(|&x| x.r#type == sample_type);

        // check if we have the `alloc-size` and `alloc-samples` sample types
        let (alloc_samples_offset, alloc_size_offset) =
            (get_offset("alloc-samples"), get_offset("alloc-size"));

        // check if we have the IO sample types
        #[cfg(all(target_os = "linux", feature = "io_profiling"))]
        let (
            socket_read_time_offset,
            socket_read_time_samples_offset,
            socket_write_time_offset,
            socket_write_time_samples_offset,
            file_read_time_offset,
            file_read_time_samples_offset,
            file_write_time_offset,
            file_write_time_samples_offset,
            socket_read_size_offset,
            socket_read_size_samples_offset,
            socket_write_size_offset,
            socket_write_size_samples_offset,
            file_read_size_offset,
            file_read_size_samples_offset,
            file_write_size_offset,
            file_write_size_samples_offset,
        ) = (
            get_offset("socket-read-time"),
            get_offset("socket-read-time-samples"),
            get_offset("socket-write-time"),
            get_offset("socket-write-time-samples"),
            get_offset("file-read-time"),
            get_offset("file-read-time-samples"),
            get_offset("file-write-time"),
            get_offset("file-write-time-samples"),
            get_offset("socket-read-size"),
            get_offset("socket-read-size-samples"),
            get_offset("socket-write-size"),
            get_offset("socket-write-size-samples"),
            get_offset("file-read-size"),
            get_offset("file-read-size-samples"),
            get_offset("file-write-size"),
            get_offset("file-write-size-samples"),
        );

        // check if we have the `exception-samples` sample types
        let exception_samples_offset = get_offset("exception-samples");

        let period = WALL_TIME_PERIOD.as_nanos();
        let mut profile = InternalProfile::new(
            &sample_types,
            Some(Period {
                r#type: ApiValueType {
                    r#type: WALL_TIME_PERIOD_TYPE.r#type,
                    unit: WALL_TIME_PERIOD_TYPE.unit,
                },
                value: period.min(i64::MAX as u128) as i64,
            }),
        );
        let _ = profile.set_start_time(started_at);

        if let (Some(alloc_size_offset), Some(alloc_samples_offset)) =
            (alloc_size_offset, alloc_samples_offset)
        {
            let upscaling_info = UpscalingInfo::Poisson {
                sum_value_offset: alloc_size_offset,
                count_value_offset: alloc_samples_offset,
                sampling_distance: ALLOCATION_PROFILING_INTERVAL.load(Ordering::SeqCst),
            };
            let values_offset = [alloc_size_offset, alloc_samples_offset];
            match profile.add_upscaling_rule(&values_offset, "", "", upscaling_info) {
                Ok(_id) => {}
                Err(err) => {
                    warn!("Failed to add upscaling rule for allocation samples, allocation samples reported will be wrong: {err}")
                }
            }
        }

        #[cfg(all(target_os = "linux", feature = "io_profiling"))]
        {
            let add_io_upscaling_rule =
                |profile: &mut InternalProfile,
                 sum_value_offset: Option<usize>,
                 count_value_offset: Option<usize>,
                 sampling_distance: u64,
                 metric_name: &str| {
                    if let (Some(sum_value_offset), Some(count_value_offset)) =
                        (sum_value_offset, count_value_offset)
                    {
                        let upscaling_info = UpscalingInfo::Poisson {
                            sum_value_offset,
                            count_value_offset,
                            sampling_distance,
                        };
                        let values_offset = [sum_value_offset, count_value_offset];
                        if let Err(err) =
                            profile.add_upscaling_rule(&values_offset, "", "", upscaling_info)
                        {
                            warn!("Failed to add upscaling rule for {metric_name}, {metric_name} reported will be wrong: {err}")
                        }
                    }
                };

            add_io_upscaling_rule(
                &mut profile,
                socket_read_time_offset,
                socket_read_time_samples_offset,
                SOCKET_READ_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst),
                "socket read time samples",
            );

            add_io_upscaling_rule(
                &mut profile,
                socket_write_time_offset,
                socket_write_time_samples_offset,
                SOCKET_WRITE_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst),
                "socket write time samples",
            );

            add_io_upscaling_rule(
                &mut profile,
                file_read_time_offset,
                file_read_time_samples_offset,
                FILE_READ_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst),
                "file read time samples",
            );

            add_io_upscaling_rule(
                &mut profile,
                file_write_time_offset,
                file_write_time_samples_offset,
                FILE_WRITE_TIME_PROFILING_INTERVAL.load(Ordering::SeqCst),
                "file write time samples",
            );

            add_io_upscaling_rule(
                &mut profile,
                socket_read_size_offset,
                socket_read_size_samples_offset,
                SOCKET_READ_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst),
                "socket read size samples",
            );

            add_io_upscaling_rule(
                &mut profile,
                socket_write_size_offset,
                socket_write_size_samples_offset,
                SOCKET_WRITE_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst),
                "socket write size samples",
            );

            add_io_upscaling_rule(
                &mut profile,
                file_read_size_offset,
                file_read_size_samples_offset,
                FILE_READ_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst),
                "file read size samples",
            );

            add_io_upscaling_rule(
                &mut profile,
                file_write_size_offset,
                file_write_size_samples_offset,
                FILE_WRITE_SIZE_PROFILING_INTERVAL.load(Ordering::SeqCst),
                "file write size samples",
            );
        }
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
            // In libdatadog v9, these endpoint operations won't fail. It may
            // in newer versions, which is why it's fallible now.
            if let Err(err) = profile.add_endpoint(local_root_span_id, endpoint.clone()) {
                warn!("failed to add endpoint info to local root span {local_root_span_id}: {err}");
            }
            if let Err(err) = profile.add_endpoint_count(endpoint, 1) {
                warn!(
                    "failed to add endpoint count to local root span {local_root_span_id}: {err}"
                );
            }
        }
    }

    #[inline(never)]
    fn process_queue(
        sample_queue: &ArrayQueue<SampleMessage>,
        profiles: &mut HashMap<ProfileIndex, InternalProfile>,
        started_at: &WallTime,
    ) {
        while let Some(message) = sample_queue.pop() {
            if message.key.sample_types.is_empty() {
                // profiling disabled, this should not happen!
                warn!("A sample with no sample types was recorded in the profiler. Please report this to Datadog.");
                return;
            }

            let profile: &mut InternalProfile = if let Some(value) = profiles.get_mut(&message.key)
            {
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
                    },
                    line: frame.line as i64,
                    ..Location::default()
                };

                locations.push(location);
            }

            let sample = Sample {
                locations,
                values: &values,
                labels,
            };

            let timestamp = NonZeroI64::new(message.value.timestamp);

            match profile.try_add_sample(sample, timestamp) {
                Ok(_id) => {}
                Err(err) => {
                    warn!("Failed to add sample to the profile: {err}")
                }
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
                            ProfilerMessage::ProcessQueue =>
                                Self::process_queue(&self.sample_queue, &mut profiles, &last_wall_export),
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
                    Ok(_) => {
                        self.interrupt_manager.trigger_interrupts();
                        Self::process_queue(&self.sample_queue, &mut profiles, &last_wall_export)
                    },

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
    Upload(Box<UploadRequest>),
}

const COW_EVAL: Cow<str> = Cow::Borrowed("[eval]");

const DDPROF_TIME: &str = "ddprof_time";
const DDPROF_UPLOAD: &str = "ddprof_upload";

impl Profiler {
    /// Will initialize the `PROFILER` OnceCell and makes sure that only one thread will do so.
    pub fn init(system_settings: &mut SystemSettings) {
        // SAFETY: the `get_or_init` access is a thread-safe API, and the
        // PROFILER is only being mutated in single-threaded phases such as
        //minit/mshutdown.
        unsafe { (*ptr::addr_of!(PROFILER)).get_or_init(|| Profiler::new(system_settings)) };
    }

    pub fn get() -> Option<&'static Profiler> {
        // SAFETY: the `get` access is a thread-safe API, and the PROFILER is
        // only being mutated in single-threaded phases such as minit and
        // mshutdown.
        unsafe { (*ptr::addr_of!(PROFILER)).get() }
    }

    pub fn new(system_settings: &mut SystemSettings) -> Self {
        let fork_barrier = Arc::new(Barrier::new(3));
        let interrupt_manager = Arc::new(InterruptManager::new());
        let sample_queue = Arc::new(ArrayQueue::new(128));
        let (message_sender, message_receiver) = crossbeam_channel::bounded(100);
        let (upload_sender, upload_receiver) = crossbeam_channel::bounded(UPLOAD_CHANNEL_CAPACITY);
        let time_collector = TimeCollector {
            fork_barrier: fork_barrier.clone(),
            interrupt_manager: interrupt_manager.clone(),
            sample_queue: Arc::clone(&sample_queue),
            message_receiver,
            upload_sender: upload_sender.clone(),
            upload_period: UPLOAD_PERIOD,
        };

        let uploader = Uploader::new(
            fork_barrier.clone(),
            upload_receiver,
            system_settings.output_pprof.clone(),
            system_settings.uri.clone(),
            Utc::now(),
        );

        let sample_types_filter = SampleTypeFilter::new(system_settings);
        Profiler {
            fork_barrier,
            interrupt_manager,
            sample_queue,
            message_sender,
            upload_sender,
            time_collector_handle: thread_utils::spawn(DDPROF_TIME, move || {
                time_collector.run();
                trace!("thread {DDPROF_TIME} complete, shutting down");
            }),
            uploader_handle: thread_utils::spawn(DDPROF_UPLOAD, move || {
                uploader.run();
                trace!("thread {DDPROF_UPLOAD} complete, shutting down");
            }),
            should_join: AtomicBool::new(true),
            sample_types_filter,
            system_settings: AtomicPtr::new(system_settings),
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
    pub fn fork_prepare(&self) -> anyhow::Result<()> {
        // Send the message to the uploader first, as it has a longer worst
        // case time to wait.
        let uploader_result = self.upload_sender.send(UploadMessage::Pause);
        let profiler_result = self.message_sender.send(ProfilerMessage::Pause);

        match (uploader_result, profiler_result) {
            (Ok(_), Ok(_)) => {
                self.fork_barrier.wait();
                Ok(())
            }
            (Err(err), Ok(_)) => {
                anyhow::bail!("failed to prepare {DDPROF_UPLOAD} thread for forking: {err}")
            }
            (Ok(_), Err(err)) => {
                anyhow::bail!("failed to prepare {DDPROF_TIME} thread for forking: {err}")
            }
            (Err(_), Err(_)) => anyhow::bail!(
                "failed to prepare both {DDPROF_UPLOAD} and {DDPROF_TIME} threads for forking"
            ),
        }
    }

    /// Call after a fork, but only on the thread of the parent process that forked.
    pub fn post_fork_parent(&self) {
        self.fork_barrier.wait();
    }

    pub fn send_local_root_span_resource(
        &self,
        message: LocalRootSpanResourceMessage,
    ) -> Result<(), Box<TrySendError<ProfilerMessage>>> {
        self.message_sender
            .try_send(ProfilerMessage::LocalRootSpanResource(message))
            .map_err(Box::new)
    }

    /// Begins the shutdown process. To complete it, call [Profiler::shutdown].
    /// Note that you must call [Profiler::shutdown] afterwards; it's two
    /// parts of the same operation. It's split so you (or other extensions)
    /// can do something while the other threads finish up.
    ///
    /// # Safety
    /// Must be called in mshutdown.
    pub unsafe fn stop(timeout: Duration) {
        // SAFETY: only called during mshutdown, where we have ownership of
        // the PROFILER object.
        if let Some(profiler) = unsafe { (*ptr::addr_of_mut!(PROFILER)).get_mut() } {
            profiler.join_and_drop_sender(timeout);
        }
    }

    pub fn join_and_drop_sender(&mut self, timeout: Duration) {
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
    /// Returns Ok(true) if any thread hit a timeout.
    ///
    /// # Safety
    /// Only safe to be called in Zend Extension shutdown.
    pub unsafe fn shutdown(timeout: Duration) -> Result<(), JoinError> {
        // SAFETY: only called during extension shutdown, where we have
        // ownership of the PROFILER object.
        if let Some(profiler) = unsafe { (*ptr::addr_of_mut!(PROFILER)).take() } {
            profiler.join_collector_and_uploader(timeout)
        } else {
            Ok(())
        }
    }

    fn join_collector_and_uploader(self, timeout: Duration) -> Result<(), JoinError> {
        if self.should_join.load(Ordering::SeqCst) {
            let result1 = thread_utils::join_timeout(self.time_collector_handle, timeout);
            if let Err(err) = &result1 {
                warn!("{err}, recent samples may be lost");
            }

            // Wait for the time_collector to join, since that will drop
            // the sender half of the channel that the uploader is
            // holding, allowing it to finish.
            let result2 = thread_utils::join_timeout(self.uploader_handle, timeout);
            if let Err(err) = &result2 {
                warn!("{err}, recent samples are most likely lost");
            }

            let num_failures = result1.is_err() as usize + result2.is_err() as usize;
            result2.and(result1).map_err(|_| JoinError { num_failures })
        } else {
            Ok(())
        }
    }

    /// Throws away the profiler and moves it to uninitialized.
    ///
    /// In a forking situation, the currently active profiler may not be valid
    /// because it has join handles and other state shared by other threads,
    /// and threads are not copied when the process is forked.
    /// Additionally, if we've hit certain other issues like not being able to
    /// determine the return type of the pcntl_fork function, we don't know if
    /// we're the parent or child.
    /// So, we throw away the current profiler and forget it, which avoids
    /// running the destructor. Yes, this will leak some memory.
    ///
    /// # Safety
    /// Must be called when no other thread is using the PROFILER object. That
    /// includes this thread in some kind of recursive manner.
    pub unsafe fn kill() {
        // SAFETY: see this function's safety conditions.
        if let Some(mut profiler) = unsafe { (*ptr::addr_of_mut!(PROFILER)).take() } {
            // Drop some things to reduce memory.
            profiler.interrupt_manager = Arc::new(InterruptManager::new());
            profiler.message_sender = crossbeam_channel::bounded(0).0;
            profiler.upload_sender = crossbeam_channel::bounded(0).0;

            // But we're not 100% sure everything is safe to drop, notably the
            // join handles, so we leak the rest.
            forget(profiler)
        }
    }

    /// Collect a stack sample with elapsed wall time. Collects CPU time if
    /// it's enabled and available.
    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all, level = "debug"))]
    pub fn collect_time(&self, execute_data: *mut zend_execute_data, interrupt_count: u32) {
        // todo: should probably exclude the wall and CPU time used by collecting the sample.
        let interrupt_count = interrupt_count as i64;
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(frames) => {
                let depth = frames.len();
                let (wall_time, cpu_time) = CLOCKS.with_borrow_mut(Clocks::rotate_clocks);

                let labels = Profiler::common_labels(0);
                let n_labels = labels.len();

                let mut timestamp = NO_TIMESTAMP;
                {
                    let system_settings = self.system_settings.load(Ordering::SeqCst);
                    // SAFETY: system settings are stable during a request.
                    if unsafe { *ptr::addr_of!((*system_settings).profiling_timeline_enabled) } {
                        if let Ok(now) = SystemTime::now().duration_since(UNIX_EPOCH) {
                            timestamp = now.as_nanos() as i64;
                        }
                    }
                }

                match self.prepare_and_send_message(
                    frames,
                    SampleValues {
                        interrupt_count,
                        wall_time,
                        cpu_time,
                        ..Default::default()
                    },
                    labels,
                    timestamp,
                ) {
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

    /// Collect a stack sample with memory allocations.
    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all))]
    pub fn collect_allocations(
        &self,
        execute_data: *mut zend_execute_data,
        alloc_samples: i64,
        alloc_size: i64,
    ) {
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(frames) => {
                let depth = frames.len();
                let labels = Profiler::common_labels(0);
                let n_labels = labels.len();

                match self.prepare_and_send_message(
                    frames,
                    SampleValues {
                        alloc_size,
                        alloc_samples,
                        ..Default::default()
                    },
                    labels,
                    NO_TIMESTAMP,
                ) {
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

    /// Collect a stack sample with exception.
    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all))]
    pub fn collect_exception(
        &self,
        execute_data: *mut zend_execute_data,
        exception: String,
        message: Option<String>,
    ) {
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(frames) => {
                let depth = frames.len();
                let mut labels = Profiler::common_labels(2);

                labels.push(Label {
                    key: "exception type",
                    value: LabelValue::Str(exception.clone().into()),
                });

                if let Some(message) = message {
                    labels.push(Label {
                        key: "exception message",
                        value: LabelValue::Str(message.into()),
                    });
                }

                let n_labels = labels.len();

                let mut timestamp = NO_TIMESTAMP;
                {
                    let system_settings = self.system_settings.load(Ordering::SeqCst);
                    // SAFETY: system settings are stable during a request.
                    if unsafe { *ptr::addr_of!((*system_settings).profiling_timeline_enabled) } {
                        if let Ok(now) = SystemTime::now().duration_since(UNIX_EPOCH) {
                            timestamp = now.as_nanos() as i64;
                        }
                    }
                }

                match self.prepare_and_send_message(
                    frames,
                    SampleValues {
                        exception: 1,
                        ..Default::default()
                    },
                    labels,
                    timestamp,
                ) {
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

    const TIMELINE_COMPILE_FILE_LABELS: &'static [Label] = &[Label {
        key: "event",
        value: LabelValue::Str(Cow::Borrowed("compilation")),
    }];

    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all))]
    pub fn collect_compile_string(&self, now: i64, duration: i64, filename: String, line: u32) {
        let mut labels = Profiler::common_labels(Self::TIMELINE_COMPILE_FILE_LABELS.len());
        labels.extend_from_slice(Self::TIMELINE_COMPILE_FILE_LABELS);
        let n_labels = labels.len();

        match self.prepare_and_send_message(
            vec![ZendFrame {
                function: COW_EVAL,
                file: Some(Cow::Owned(filename)),
                line,
            }],
            SampleValues {
                timeline: duration,
                ..Default::default()
            },
            labels,
            now,
        ) {
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

    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all, level = "debug"))]
    pub fn collect_compile_file(
        &self,
        now: i64,
        duration: i64,
        filename: String,
        include_type: &str,
    ) {
        let mut labels = Profiler::common_labels(Self::TIMELINE_COMPILE_FILE_LABELS.len() + 1);
        labels.extend_from_slice(Self::TIMELINE_COMPILE_FILE_LABELS);
        labels.push(Label {
            key: "filename",
            value: LabelValue::Str(Cow::from(filename)),
        });

        let n_labels = labels.len();

        match self.prepare_and_send_message(
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
            now,
        ) {
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

    /// This function will collect a thread start or stop timeline event
    #[cfg(php_zts)]
    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all, level = "debug"))]
    pub fn collect_thread_start_end(&self, now: i64, event: &'static str) {
        let mut labels = Profiler::common_labels(1);

        labels.push(Label {
            key: "event",
            value: LabelValue::Str(std::borrow::Cow::Borrowed(event)),
        });

        let n_labels = labels.len();

        match self.prepare_and_send_message(
            vec![ZendFrame {
                function: format!("[{event}]").into(),
                file: None,
                line: 0,
            }],
            SampleValues {
                timeline: 1,
                ..Default::default()
            },
            labels,
            now,
        ) {
            Ok(_) => {
                trace!("Sent event '{event}' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!("Failed to send event '{event}' with {n_labels} labels to profiler: {err}")
            }
        }
    }

    /// This function can be called to collect any fatal errors
    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all, level = "debug"))]
    pub fn collect_fatal(&self, now: i64, file: String, line: u32, message: String) {
        let mut labels = Profiler::common_labels(2);

        labels.push(Label {
            key: "event",
            value: LabelValue::Str("fatal".into()),
        });
        labels.push(Label {
            key: "message",
            value: LabelValue::Str(message.into()),
        });

        let n_labels = labels.len();

        match self.prepare_and_send_message(
            vec![ZendFrame {
                function: "[fatal]".into(),
                file: Some(Cow::Owned(file)),
                line,
            }],
            SampleValues {
                timeline: 1,
                ..Default::default()
            },
            labels,
            now,
        ) {
            Ok(_) => {
                trace!("Sent event 'fatal error' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!(
                    "Failed to send event 'fatal error' with {n_labels} labels to profiler: {err}"
                )
            }
        }
    }

    /// This function can be called to collect an opcache restart
    #[cfg(php_opcache_restart_hook)]
    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all, level = "debug"))]
    pub(crate) fn collect_opcache_restart(
        &self,
        now: i64,
        file: String,
        line: u32,
        reason: &'static str,
    ) {
        let mut labels = Profiler::common_labels(2);

        labels.push(Label {
            key: "event",
            value: LabelValue::Str("opcache_restart".into()),
        });
        labels.push(Label {
            key: "reason",
            value: LabelValue::Str(reason.into()),
        });

        let n_labels = labels.len();

        match self.prepare_and_send_message(
            vec![ZendFrame {
                function: "[opcache restart]".into(),
                file: Some(Cow::Owned(file)),
                line,
            }],
            SampleValues {
                timeline: 1,
                ..Default::default()
            },
            labels,
            now,
        ) {
            Ok(_) => {
                trace!("Sent event 'opcache_restart' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!("Failed to send event 'opcache_restart' with {n_labels} labels to profiler: {err}")
            }
        }
    }

    /// This function can be called to collect any kind of inactivity that is happening
    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all, level = "debug"))]
    pub fn collect_idle(&self, now: i64, duration: i64, reason: &'static str) {
        let mut labels = Profiler::common_labels(1);

        labels.push(Label {
            key: "event",
            value: LabelValue::Str(reason.into()),
        });

        let n_labels = labels.len();

        match self.prepare_and_send_message(
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
            now,
        ) {
            Ok(_) => {
                trace!("Sent event 'idle' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!("Failed to send event 'idle' with {n_labels} labels to profiler: {err}")
            }
        }
    }

    /// collect a stack frame for garbage collection.
    /// as we do not know about the overhead currently, we only collect a fake frame.
    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all, level = "debug"))]
    pub fn collect_garbage_collection(
        &self,
        now: i64,
        duration: i64,
        reason: &'static str,
        collected: i64,
        #[cfg(php_gc_status)] runs: i64,
    ) {
        let mut labels = Profiler::common_labels(4);

        labels.push(Label {
            key: "event",
            value: LabelValue::Str(Cow::Borrowed("gc")),
        });

        labels.push(Label {
            key: "gc reason",
            value: LabelValue::Str(Cow::from(reason)),
        });

        #[cfg(php_gc_status)]
        labels.push(Label {
            key: "gc runs",
            value: LabelValue::Num(runs, "count"),
        });
        labels.push(Label {
            key: "gc collected",
            value: LabelValue::Num(collected, "count"),
        });
        let n_labels = labels.len();

        match self.prepare_and_send_message(
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
            now,
        ) {
            Ok(_) => {
                trace!("Sent event 'gc' with {n_labels} labels and reason {reason} to profiler.")
            }
            Err(err) => {
                warn!("Failed to send event 'gc' with {n_labels} and reason {reason} labels to profiler: {err}")
            }
        }
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_socket_read_time(&self, ed: *mut zend_execute_data, socket_io_read_time: i64) {
        self.collect_io(ed, |vals| {
            vals.socket_read_time = socket_io_read_time;
            vals.socket_read_time_samples = 1;
        })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_socket_write_time(&self, ed: *mut zend_execute_data, socket_io_write_time: i64) {
        self.collect_io(ed, |vals| {
            vals.socket_write_time = socket_io_write_time;
            vals.socket_write_time_samples = 1;
        })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_file_read_time(&self, ed: *mut zend_execute_data, file_io_read_time: i64) {
        self.collect_io(ed, |vals| {
            vals.file_read_time = file_io_read_time;
            vals.file_read_time_samples = 1;
        })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_file_write_time(&self, ed: *mut zend_execute_data, file_io_write_time: i64) {
        self.collect_io(ed, |vals| {
            vals.file_write_time = file_io_write_time;
            vals.file_write_time_samples = 1;
        })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_socket_read_size(&self, ed: *mut zend_execute_data, socket_io_read_size: i64) {
        self.collect_io(ed, |vals| {
            vals.socket_read_size = socket_io_read_size;
            vals.socket_read_size_samples = 1;
        })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_socket_write_size(&self, ed: *mut zend_execute_data, socket_io_write_size: i64) {
        self.collect_io(ed, |vals| {
            vals.socket_write_size = socket_io_write_size;
            vals.socket_write_size_samples = 1;
        })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_file_read_size(&self, ed: *mut zend_execute_data, file_io_read_size: i64) {
        self.collect_io(ed, |vals| {
            vals.file_read_size = file_io_read_size;
            vals.file_read_size_samples = 1;
        })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_file_write_size(&self, ed: *mut zend_execute_data, file_io_write_size: i64) {
        self.collect_io(ed, |vals| {
            vals.file_write_size = file_io_write_size;
            vals.file_write_size_samples = 1;
        })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_io<F>(&self, execute_data: *mut zend_execute_data, set_value: F)
    where
        F: FnOnce(&mut SampleValues),
    {
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(frames) => {
                let depth = frames.len();
                let labels = Profiler::common_labels(0);

                let n_labels = labels.len();

                let mut values = SampleValues::default();
                set_value(&mut values);

                match self.prepare_and_send_message(
                    frames,
                    values,
                    labels,
                    NO_TIMESTAMP,
                ) {
                    Ok(_) => trace!(
                        "Sent I/O stack sample of {depth} frames, {n_labels} labels with to profiler."
                    ),
                    Err(err) => warn!(
                        "Failed to send I/O stack sample of {depth} frames, {n_labels} labels to profiler: {err}"
                    ),
                }
            }
            Err(err) => {
                warn!("Failed to collect stack sample: {err}")
            }
        }
    }

    /// Creates the common message labels for all samples.
    ///
    /// * `n_extra_labels` - Reserve room for extra labels, such as when the
    ///   caller adds gc or exception labels.
    fn common_labels(n_extra_labels: usize) -> Vec<Label> {
        let mut labels = Vec::with_capacity(5 + n_extra_labels);
        labels.push(Label {
            key: "thread id",
            value: LabelValue::Num(unsafe { libc::pthread_self() as i64 }, "id"),
        });

        labels.push(Label {
            key: "thread name",
            value: LabelValue::Str(get_current_thread_name().into()),
        });

        // SAFETY: this is set to a noop version if ddtrace wasn't found, and
        // we're getting the profiling context on a PHP thread.
        let context = unsafe { datadog_php_profiling_get_profiling_context.unwrap_unchecked()() };
        if context.local_root_span_id != 0 {
            // Casting between two integers of the same size is a no-op, and
            // Rust uses 2's complement for negative numbers.
            let local_root_span_id = context.local_root_span_id as i64;
            let span_id = context.span_id as i64;

            labels.push(Label {
                key: "local root span id",
                value: LabelValue::Num(local_root_span_id, ""),
            });

            labels.push(Label {
                key: "span id",
                value: LabelValue::Num(span_id, ""),
            });
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
                    value: LabelValue::Str(functionname),
                });
            }
        }
        labels
    }

    fn prepare_and_send_message(
        &self,
        frames: Vec<ZendFrame>,
        samples: SampleValues,
        labels: Vec<Label>,
        timestamp: i64,
    ) -> Result<(), &'static str> {
        // We don't want to wake the other thread too frequently, that's the
        // whole reason we don't directly send it messages anymore. Keep in
        // mind that if it wakes up for certain reasons, it will process the
        // queue already, so this isn't the only pressure to handle it.
        // todo: reason about a specific frequency here, under both NTS & ZTS
        thread_local! {
            static SAMPLES_SENT: Cell<usize> = const { Cell::new(0) };
        }
        let samples_sent = SAMPLES_SENT.get();
        if samples_sent % 16 == 15 {
            if let Err(err) = self.message_sender.try_send(ProfilerMessage::ProcessQueue) {
                warn!("Failed to tell the profiler to process the sample queue: {err}");
            }
        }
        let message = self.prepare_sample_message(frames, samples, labels, timestamp);
        if self.sample_queue.push(message).is_err() {
            SAMPLES_SENT.set(samples_sent.wrapping_add(1));
            Err("failed to enqueue sample, queue is full")
        } else {
            Ok(())
        }
    }

    fn prepare_sample_message(
        &self,
        frames: Vec<ZendFrame>,
        samples: SampleValues,
        labels: Vec<Label>,
        timestamp: i64,
    ) -> SampleMessage {
        // If profiling is disabled, these will naturally return empty Vec.
        // There's no short-cutting here because:
        //  1. Nobody should be calling this when it's disabled anyway.
        //  2. It would require tracking more state and/or spending CPU on
        //     something that shouldn't be done anyway (see #1).
        let sample_types = self.sample_types_filter.sample_types();
        let sample_values = self.sample_types_filter.filter(samples);

        let tags = TAGS.with_borrow(Arc::clone);

        SampleMessage {
            key: ProfileIndex { sample_types, tags },
            value: SampleData {
                frames,
                labels,
                sample_values,
                timestamp,
            },
        }
    }
}

pub struct JoinError {
    pub num_failures: usize,
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{allocation::DEFAULT_ALLOCATION_SAMPLING_INTERVAL, config::AgentEndpoint};
    use datadog_profiling::exporter::Uri;
    use log::LevelFilter;

    fn get_frames() -> Vec<ZendFrame> {
        vec![ZendFrame {
            function: "foobar()".into(),
            file: Some("foobar.php".into()),
            line: 42,
        }]
    }

    pub fn get_system_settings() -> SystemSettings {
        SystemSettings {
            profiling_enabled: true,
            profiling_experimental_features_enabled: false,
            profiling_endpoint_collection_enabled: false,
            profiling_experimental_cpu_time_enabled: false,
            profiling_allocation_enabled: false,
            profiling_allocation_sampling_distance: DEFAULT_ALLOCATION_SAMPLING_INTERVAL as u32,
            profiling_timeline_enabled: false,
            profiling_exception_enabled: false,
            profiling_exception_message_enabled: false,
            profiling_wall_time_enabled: true,
            profiling_io_enabled: false,
            output_pprof: None,
            profiling_exception_sampling_distance: 100,
            profiling_log_level: LevelFilter::Off,
            uri: AgentEndpoint::Uri(Uri::default()),
        }
    }

    pub fn get_samples() -> SampleValues {
        SampleValues {
            interrupt_count: 10,
            wall_time: 20,
            cpu_time: 30,
            alloc_samples: 40,
            alloc_size: 50,
            timeline: 60,
            exception: 70,
            socket_read_time: 80,
            socket_read_time_samples: 81,
            socket_write_time: 90,
            socket_write_time_samples: 91,
            file_read_time: 100,
            file_read_time_samples: 101,
            file_write_time: 110,
            file_write_time_samples: 111,
            socket_read_size: 120,
            socket_read_size_samples: 121,
            socket_write_size: 130,
            socket_write_size_samples: 131,
            file_read_size: 140,
            file_read_size_samples: 141,
            file_write_size: 150,
            file_write_size_samples: 151,
        }
    }

    #[test]
    #[cfg(not(miri))]
    fn profiler_prepare_sample_message_works_cpu_time_and_timeline() {
        let frames = get_frames();
        let samples = get_samples();
        let labels = Profiler::common_labels(0);
        let mut settings = get_system_settings();
        settings.profiling_enabled = true;
        settings.profiling_experimental_cpu_time_enabled = true;
        settings.profiling_timeline_enabled = true;

        let profiler = Profiler::new(&mut settings);

        let message: SampleMessage = profiler.prepare_sample_message(frames, samples, labels, 900);

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
}
