pub mod dictionary;
mod interrupts;
mod samples;
pub mod stack_walking;
mod thread_utils;
mod uploader;

pub use interrupts::*;
pub use samples::*;
pub use stack_walking::*;
use thread_utils::get_current_thread_name;
use uploader::*;

#[cfg(all(php_has_fibers, not(test)))]
use crate::bindings::ddog_php_prof_get_active_fiber;
#[cfg(all(php_has_fibers, test))]
use crate::bindings::ddog_php_prof_get_active_fiber_test as ddog_php_prof_get_active_fiber;

use crate::allocation::ALLOCATION_PROFILING_INTERVAL;
use crate::bindings::{
    self as zend, datadog_php_profiling_get_profiling_context, zend_execute_data,
};
use crate::config::SystemSettings;
use crate::exception::EXCEPTION_PROFILING_INTERVAL;
use crate::profiling::dictionary::PhpProfilesDictionary;
use crate::{timeline, Clocks, CLOCKS, TAGS};
use arrayvec::ArrayVec;
use chrono::Utc;
use core::mem::forget;
use core::{ptr, str};
use crossbeam_channel::{Receiver, Sender, TrySendError};
use datadog_profiling::exporter::Tag;
use datadog_profiling::profiles::collections::{Arc as DdArc, StringId};
use datadog_profiling::profiles::datatypes::{
    Function, FunctionId, Line, Link, Location, Profile, ProfilesDictionary, SampleBuilder,
    ScratchPad, StackId,
};
use datadog_profiling::profiles::pprof_builder::{PprofBuilder, PprofOptions};
use datadog_profiling::profiles::{PoissonUpscalingRule, ProfileError};
use ddcommon::error::FfiSafeErrorMessage;
use log::{debug, info, trace, warn};
use once_cell::sync::OnceCell;
use rustc_hash::FxHasher;
use std::borrow::Cow;
use std::collections::hash_map::Entry;
use std::collections::HashMap;
use std::ffi::CStr;
use std::hash::{BuildHasherDefault, Hash};
use std::ptr::{null_mut, NonNull};
use std::sync::atomic::{AtomicBool, AtomicPtr, AtomicU32, AtomicU64, Ordering};
use std::sync::{Arc, Barrier};
use std::thread::JoinHandle;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

const UPLOAD_PERIOD: Duration = Duration::from_secs(67);

pub const NO_TIMESTAMP: i64 = 0;

// Guide: upload period / upload timeout should give about the order of
// magnitude for the capacity.
const UPLOAD_CHANNEL_CAPACITY: usize = 8;

/// The global profiler. Profiler gets made during the first rinit after an
/// minit, and is destroyed on mshutdown.
static mut PROFILER: OnceCell<Profiler> = OnceCell::new();

const WALL_TIME_PERIOD: Duration = Duration::from_millis(10);

#[derive(Clone, Copy, Debug)]
struct WallTime {
    // todo: should we use Instant for duration like we used to?
    // instant: Instant,
    systemtime: SystemTime,
}

impl WallTime {
    fn now() -> Self {
        Self {
            // instant: Instant::now(),
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
    pub tags: Arc<Vec<Tag>>,
}

/// Represents a sample that's going to be sent over the queue/channel for
/// processing.
pub struct SampleData {
    pub sample: SampleValue,
    pub call_stack: CallStack,
    pub timestamp: i64,
    pub labels: Vec<Label>,
    pub link: Link,
}

/// Holds a vec of call frames with the leaf at offset 0, and the dictionary
/// that the function ids and their associated strings belong to.
pub struct CallStack {
    pub frames: Vec<ZendFrame>,
    pub dictionary: DdArc<PhpProfilesDictionary>,
}

impl CallStack {
    pub fn try_clone(&self) -> Result<CallStack, ProfileError> {
        let mut frames = Vec::new();
        frames.try_reserve(self.frames.len())?;
        frames.extend_from_slice(self.frames.as_slice());
        let dictionary = self.dictionary.try_clone()?;
        Ok(CallStack { frames, dictionary })
    }
}

/// SAFETY: the function_ids refer to data in the dictionary, which keeps it
/// alive.
unsafe impl Send for CallStack {}

pub struct SampleMessage {
    pub key: ProfileIndex,
    pub value: SampleData,
}

#[derive(Debug)]
pub struct LocalRootSpanResourceMessage {
    pub local_root_span_id: u64,
    pub resource: String,
}

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
    enabled_profiles: EnabledProfiles,
    system_settings: AtomicPtr<SystemSettings>,
}

struct TimeCollector {
    upscaling_intervals: ProfileUpscalingIntervals<'static>,
    fork_barrier: Arc<Barrier>,
    interrupt_manager: Arc<InterruptManager>,
    message_receiver: Receiver<ProfilerMessage>,
    upload_sender: Sender<UploadMessage>,
    upload_period: Duration,
}

pub enum PhpUpscalingRule {
    // PHP doesn't use grouping by labels.
    Proportional { scale: f64 },
    Poisson(PoissonUpscalingRule),
}

struct AggregatedProfile {
    dict: DdArc<PhpProfilesDictionary>,
    scratch: ScratchPad,

    // The length of the array corresponds to the profile types, enabled or
    // not. Each element corresponds to an offset of a profile. Disabled
    // profiles use None. This strategy allows us to use the discriminant of
    // the SampleValue enum as the index.
    profiles: Vec<Option<Profile>>,
}

struct Unreachable;

unsafe impl FfiSafeErrorMessage for Unreachable {
    fn as_ffi_str(&self) -> &'static CStr {
        c"this is supposed to be unreachable"
    }
}

impl TimeCollector {
    fn handle_timeout(
        &self,
        profiles: &mut HashMap<ProfileIndex, AggregatedProfile, BuildHasherDefault<FxHasher>>,
        last_export: &WallTime,
    ) -> WallTime {
        let wall_export = WallTime::now();
        if profiles.is_empty() {
            info!("No profiles to upload.");
            return wall_export;
        }

        // todo: do we need this for the wall-time profile?
        // let duration = wall_export
        //     .instant
        //     .checked_duration_since(last_export.instant);

        let end_time = wall_export.systemtime;

        for (index, mut aggregated) in profiles.drain() {
            // Set the interval timestamps on the scratchpad so all profiles in this
            // interval share the same timing information.
            if let Err(err) = aggregated.scratch.set_end_time(end_time) {
                warn!("Invalid interval for profile: {err}");
            }

            let mut builder = PprofBuilder::new(aggregated.dict.dictionary(), &aggregated.scratch);
            builder
                .with_options(PprofOptions::default())
                .expect("todo fix this expect");

            let upscalings: [Option<PhpUpscalingRule>; PROFILE_TYPES.len()] = [
                SampleDiscriminant::WallTime.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::CpuTime.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::Alloc.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::Timeline.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::Exception.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::FileIoReadTime.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::FileIoWriteTime.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::FileIoReadSize.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::FileIoWriteSize.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::SocketReadTime.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::SocketWriteTime.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::SocketReadSize.upscaling(&self.upscaling_intervals),
                SampleDiscriminant::SocketWriteSize.upscaling(&self.upscaling_intervals),
            ];
            for (profile, upscaling) in aggregated.profiles.iter().zip(upscalings) {
                if let Some(profile) = profile {
                    match upscaling {
                        Some(PhpUpscalingRule::Proportional { scale }) => builder
                            .try_add_profile_with_proportional_upscaling::<_, Unreachable>(
                                profile,
                                std::iter::once(scale)
                                    .map(|scale| Ok(((StringId::EMPTY, Cow::Borrowed("")), scale))),
                            )
                            .unwrap(),
                        Some(PhpUpscalingRule::Poisson(poisson)) => builder
                            .try_add_profile_with_poisson_upscaling(profile, poisson)
                            .unwrap(),
                        None => builder.try_add_profile(profile).unwrap(),
                    }
                }
            }

            let mut buffer = Vec::new();
            if let Err(err) = builder.build(&mut buffer) {
                warn!("Failed to build pprof: {err}");
                continue;
            }
            let encoded = datadog_profiling::exporter::EncodedProfile {
                start: last_export.systemtime,
                end: end_time,
                buffer,
                endpoints_stats: Default::default(),
            };

            let key = ProfileIndex { tags: index.tags };
            let message = UploadMessage::Upload(UploadRequest {
                index: key,
                profile: encoded,
            });
            if let Err(err) = self.upload_sender.try_send(message) {
                warn!("Failed to upload profile: {err}");
            }
        }
        wall_export
    }

    fn create_profile(
        dict: &DdArc<PhpProfilesDictionary>,
    ) -> Result<AggregatedProfile, ProfileError> {
        Ok(AggregatedProfile {
            dict: dict.try_clone()?,
            scratch: ScratchPad::try_new()?,
            profiles: {
                let mut profiles = Vec::new();
                profiles.try_reserve_exact(PROFILE_TYPES.len())?;
                for _ in 0..PROFILE_TYPES.len() {
                    profiles.push(None);
                }
                profiles
            },
        })
    }

    fn insert_stack(scratch_pad: &ScratchPad, frames: &[ZendFrame]) -> Option<StackId> {
        let mut loc_ids = Vec::new();
        if let Err(err) = loc_ids.try_reserve(frames.len()) {
            warn!("failed to reserve memory for a stack: {err}");
            return None;
        }
        for frame in frames {
            let loc = Location {
                address: 0,
                mapping_id: null_mut(),
                line: Line {
                    line_number: frame.line as i64,
                    function_id: frame.function_id.map(NonNull::as_ptr).unwrap_or(null_mut()),
                },
            };
            match scratch_pad.locations().try_insert(loc) {
                Ok(id) => loc_ids.push(id),
                Err(err) => {
                    warn!("Failed to insert location: {err}");
                    return None;
                }
            }
        }
        match scratch_pad.stacks().try_insert(&loc_ids) {
            Ok(id) => Some(id),
            Err(err) => {
                warn!("Failed to insert stack: {err}");
                None
            }
        }
    }

    fn handle_resource_message(
        message: LocalRootSpanResourceMessage,
        profiles: &mut HashMap<ProfileIndex, AggregatedProfile, BuildHasherDefault<FxHasher>>,
    ) {
        trace!(
            "Received Endpoint Profiling message for span id {}.",
            message.local_root_span_id
        );

        let local_root_span_id = message.local_root_span_id as i64;
        let resource = message.resource;
        for (_, profile) in profiles.iter_mut() {
            if let Err(err) = profile
                .scratch
                .endpoint_tracker()
                .add_trace_endpoint_with_count(local_root_span_id, &resource, 1)
            {
                warn!("Failed to add endpoint for LRS {local_root_span_id}: {err}");
            }
        }
    }

    // todo: clean up expects
    fn handle_sample_message(
        message: SampleMessage,
        profiles: &mut HashMap<ProfileIndex, AggregatedProfile, BuildHasherDefault<FxHasher>>,
    ) {
        let SampleMessage {
            key,
            value:
                SampleData {
                    sample,
                    call_stack,
                    timestamp,
                    labels,
                    link,
                },
        } = message;

        // Fetch the aggregated profile associated to the index, creating it
        // as needed.
        let aggregated_profile = match profiles.entry(key) {
            Entry::Occupied(o) => o.into_mut(),
            Entry::Vacant(v) => {
                let agg = match Self::create_profile(&call_stack.dictionary) {
                    Ok(a) => a,
                    Err(err) => {
                        // If this fails, and the process doesn't die, we're
                        // likely to get this over and over, so using debug.
                        debug!("Failed to create a new profile: {err}");
                        return;
                    }
                };
                v.insert_entry(agg).into_mut()
            }
        };

        // Fetch the profile associated to the sample provided, creating an
        // empty profile as needed.
        let profile = if let Some(maybe_profile) = aggregated_profile
            .profiles
            .get_mut(sample.discriminant().index())
        {
            maybe_profile.get_or_insert_with(|| {
                let mut profile = Profile::default();
                let src = sample.sample_types();
                let mut dst = ArrayVec::new();
                for value_type in src {
                    let strings = aggregated_profile.dict.dictionary().strings();
                    let vt = datadog_profiling::profiles::datatypes::ValueType {
                        type_id: strings.try_insert(value_type.r#type).unwrap(),
                        unit_id: strings.try_insert(value_type.unit).unwrap(),
                    };
                    dst.try_push(vt)
                        .expect("too many sample types passed to profile");
                }
                profile.sample_types = dst;
                profile
            })
        } else {
            warn!("tried to insert {sample:?} but it wasn't found in the aggregated profile list");
            return;
        };

        // Insert the sample into the profile. Map from ZendFrame to what
        // libdatadog expects.
        if let Some(stack_id) = Self::insert_stack(&aggregated_profile.scratch, &call_stack.frames)
        {
            let attrs = aggregated_profile
                .scratch
                .attributes()
                .try_clone()
                .expect("attributes set try_clone");
            let links = aggregated_profile
                .scratch
                .links()
                .try_clone()
                .expect("links set try_clone");
            let mut sb = SampleBuilder::new(attrs, links);
            sb.set_stack_id(stack_id);
            sb.set_link(link).expect("set_link failed");

            for val in sample.as_slice() {
                sb.push_value(*val).expect("push_value failed");
            }
            sb.try_reserve_attributes(labels.len())
                .expect("try_reserve_attributes failed");
            for label in labels {
                let key_id = aggregated_profile
                    .dict
                    .dictionary()
                    .strings()
                    .try_insert(label.key)
                    .expect("label key deduplication failed");
                match &label.value {
                    LabelValue::Str(s) => {
                        sb.push_attribute_str(key_id, s.as_ref())
                            .expect("push_attribute_str failed");
                    }
                    LabelValue::Num(n, ..) => {
                        sb.push_attribute_int(key_id, *n)
                            .expect("push_attribute_num failed");
                    }
                }
            }
            if timestamp != NO_TIMESTAMP {
                let nanos = if timestamp < 0 { 0 } else { timestamp as u64 };
                let ts = UNIX_EPOCH + Duration::from_nanos(nanos);
                sb.set_timestamp(ts);
            }
            if let Ok(sample) = sb.build() {
                profile.add_sample(sample).expect("add_sample failed");
            }
        }
    }

    pub fn run(self) {
        let mut last_wall_export = WallTime::now();

        // sip::Hasher::write showed up in profiles, using alternative hasher.
        let mut profiles: HashMap<ProfileIndex, AggregatedProfile, BuildHasherDefault<FxHasher>> =
            HashMap::with_capacity_and_hasher(1, Default::default());

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
                                Self::handle_sample_message(sample, &mut profiles),
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
    // TODO(api-migration): switch to real encoding from profiles + pprof builder
    profile: datadog_profiling::exporter::EncodedProfile,
}

pub enum UploadMessage {
    Pause,
    Upload(UploadRequest),
}

const DDPROF_TIME: &str = "ddprof_time";
const DDPROF_UPLOAD: &str = "ddprof_upload";

static NO_IO_UPSCALING_INTERVAL: AtomicU64 = AtomicU64::new(0);

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
        #[allow(unused_mut)] // because of conditional mutation
        let mut upscaling_intervals = ProfileUpscalingIntervals {
            alloc: &ALLOCATION_PROFILING_INTERVAL,
            exception: &EXCEPTION_PROFILING_INTERVAL,
            file_io_read_time: &NO_IO_UPSCALING_INTERVAL,
            file_io_write_time: &NO_IO_UPSCALING_INTERVAL,
            file_io_read_size: &NO_IO_UPSCALING_INTERVAL,
            file_io_write_size: &NO_IO_UPSCALING_INTERVAL,
            socket_read_time: &NO_IO_UPSCALING_INTERVAL,
            socket_write_time: &NO_IO_UPSCALING_INTERVAL,
            socket_read_size: &NO_IO_UPSCALING_INTERVAL,
            socket_write_size: &NO_IO_UPSCALING_INTERVAL,
        };
        #[cfg(all(feature = "io_profiling", target_os = "linux"))]
        if system_settings.profiling_io_enabled {
            use crate::io;
            upscaling_intervals.file_io_read_time = &io::FILE_READ_TIME_PROFILING_INTERVAL;
            upscaling_intervals.file_io_write_time = &io::FILE_WRITE_TIME_PROFILING_INTERVAL;
            upscaling_intervals.file_io_read_size = &io::FILE_READ_SIZE_PROFILING_INTERVAL;
            upscaling_intervals.file_io_write_size = &io::FILE_WRITE_SIZE_PROFILING_INTERVAL;
            upscaling_intervals.socket_read_time = &io::SOCKET_READ_TIME_PROFILING_INTERVAL;
            upscaling_intervals.socket_write_time = &io::SOCKET_WRITE_TIME_PROFILING_INTERVAL;
            upscaling_intervals.socket_read_size = &io::SOCKET_READ_SIZE_PROFILING_INTERVAL;
            upscaling_intervals.socket_write_size = &io::SOCKET_WRITE_SIZE_PROFILING_INTERVAL;
        }

        let fork_barrier = Arc::new(Barrier::new(3));
        let interrupt_manager = Arc::new(InterruptManager::new());
        let (message_sender, message_receiver) = crossbeam_channel::bounded(100);
        let (upload_sender, upload_receiver) = crossbeam_channel::bounded(UPLOAD_CHANNEL_CAPACITY);
        let time_collector = TimeCollector {
            upscaling_intervals,
            fork_barrier: fork_barrier.clone(),
            interrupt_manager: interrupt_manager.clone(),
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

        let enabled_profiles = EnabledProfiles::new(system_settings);

        Profiler {
            fork_barrier,
            interrupt_manager,
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
            enabled_profiles,
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
    #[inline(never)]
    #[export_name = "ddog_php_prof_collect_time"]
    pub fn collect_time(&self, execute_data: *mut zend_execute_data, interrupt_count: u32) {
        // todo: should probably exclude the wall and CPU time used by collecting the sample.
        let interrupt_count = interrupt_count as i64;
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(call_stack) => {
                let depth = call_stack.frames.len();
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

                if cpu_time != 0 {
                    let call_stack = match call_stack.try_clone() {
                        Ok(call_stack) => call_stack,
                        Err(err) => {
                            warn!("failed to clone call stack: {err}");
                            return;
                        }
                    };
                    let labels = {
                        let mut l = Vec::new();
                        if let Err(err) = l.try_reserve(labels.len()) {
                            warn!("failed to clone labels: {err}");
                            return;
                        }
                        l.extend_from_slice(labels.as_slice());
                        l
                    };
                    let copied_call_stack = match call_stack.try_clone() {
                        Ok(cs) => cs,
                        Err(err) => {
                            warn!("failed to clone copy call stack: {err}");
                            return;
                        }
                    };
                    let sample = SampleValue::CpuTime {
                        nanoseconds: cpu_time,
                    };
                    match self.prepare_and_send_message(
                        copied_call_stack,
                        sample,
                        labels,
                        timestamp,
                    ) {
                        Ok(_) => trace!(
                            "Sent {sample:?} of {depth} frames and {n_labels} labels to profiler."
                        ),
                        Err(err) => warn!(
                            "Failed to send {sample:?} of {depth} frames and {n_labels} labels to profiler: {err}"
                        ),
                    }
                }

                let sample = SampleValue::WallTime {
                    count: interrupt_count,
                    nanoseconds: wall_time,
                };
                match self.prepare_and_send_message(
                    call_stack,
                    sample,
                    labels,
                    timestamp,
                ) {
                    Ok(_) => trace!(
                        "Sent {sample:?} of {depth} frames and {n_labels} labels to profiler."
                    ),
                    Err(err) => warn!(
                        "Failed to send {sample:?} of {depth} frames and {n_labels} labels to profiler: {err}"
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
    #[inline(never)]
    #[export_name = "ddog_php_prof_collect_alloc"]
    pub fn collect_allocations(
        &self,
        execute_data: *mut zend_execute_data,
        alloc_samples: i64,
        alloc_size: i64,
    ) {
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(call_stack) => {
                let depth = call_stack.frames.len();
                let labels = Profiler::common_labels(0);
                let n_labels = labels.len();

                let sample = SampleValue::Alloc {
                    bytes: alloc_size,
                    count: alloc_samples,
                };
                match self.prepare_and_send_message(
                    call_stack,
                    sample,
                    labels,
                    NO_TIMESTAMP,
                ) {
                    Ok(_) => trace!(
                        "Sent {sample:?} of {depth} frames, {n_labels} labels, {alloc_size} bytes allocated, and {alloc_samples} allocations to profiler."
                    ),
                    Err(err) => warn!(
                        "Failed to send {sample:?} of {depth} frames, {n_labels} labels, {alloc_size} bytes allocated, and {alloc_samples} allocations to profiler: {err}"
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
    #[inline(never)]
    #[export_name = "ddog_php_prof_collect_exception"]
    pub fn collect_exception(
        &self,
        execute_data: *mut zend_execute_data,
        exception: String,
        message: Option<String>,
    ) {
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(call_stack) => {
                let depth = call_stack.frames.len();
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

                let sample = SampleValue::Exception { count: 1 };
                match self.prepare_and_send_message(
                    call_stack,
                    sample,
                    labels,
                    timestamp,
                ) {
                    Ok(_) => trace!(
                        "Sent {sample:?} of {depth} frames, {n_labels} labels with Exception {exception} to profiler."
                    ),
                    Err(err) => warn!(
                        "Failed to send {sample:?} of {depth} frames, {n_labels} labels with Exception {exception} to profiler: {err}"
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

        let dict = match dictionary::try_clone_tls_or_global() {
            Ok(d) => d,
            Err(err) => {
                warn!("Failed to clone dictionary: {err}");
                return;
            }
        };
        let eval_fid = match synth_function_id(
            dict.dictionary(),
            dict.known_strs().eval,
            Some(filename.as_str()),
        ) {
            Ok(id) => Some(id),
            Err(err) => {
                warn!("Failed to build [eval] frame: {err}");
                None
            }
        };
        let sample = SampleValue::Timeline {
            nanoseconds: duration,
        };
        match self.prepare_and_send_message(
            CallStack {
                frames: vec![ZendFrame {
                    function_id: eval_fid,
                    line,
                }],
                dictionary: dict,
            },
            sample,
            labels,
            now,
        ) {
            Ok(_) => {
                trace!("Sent {sample:?} event 'compile eval' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!(
                    "Failed to send {sample:?} event 'compile eval' with {n_labels} labels to profiler: {err}"
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
        include_type: i32,
    ) {
        let mut labels = Profiler::common_labels(Self::TIMELINE_COMPILE_FILE_LABELS.len() + 1);
        labels.extend_from_slice(Self::TIMELINE_COMPILE_FILE_LABELS);
        labels.push(Label {
            key: "filename",
            value: LabelValue::Str(Cow::from(filename)),
        });

        let n_labels = labels.len();

        let dictionary = match dictionary::try_clone_tls_or_global() {
            Ok(d) => d,
            Err(err) => {
                warn!("Failed to clone dictionary: {err}");
                return;
            }
        };
        let known_funcs = dictionary.known_funcs();
        let func = match include_type as u32 {
            zend::ZEND_INCLUDE => known_funcs.include,
            zend::ZEND_REQUIRE => known_funcs.require,
            _ => known_funcs.truncated, // not really but this case shouldn't happen
        };
        let include_fid = Some(func);
        let sample = SampleValue::Timeline {
            nanoseconds: duration,
        };
        match self.prepare_and_send_message(
            CallStack {
                frames: vec![ZendFrame {
                    function_id: include_fid,
                    line: 0,
                }],
                dictionary,
            },
            sample,
            labels,
            now,
        ) {
            Ok(_) => {
                trace!("Sent {sample:?} event 'compile file' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!(
                    "Failed to send {sample:?} event 'compile file' with {n_labels} labels to profiler: {err}"
                )
            }
        }
    }

    /// This function will collect a thread start or stop timeline event
    #[cfg(php_zts)]
    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all, level = "debug"))]
    pub fn collect_thread_start_end(&self, now: i64, state: timeline::State) {
        let event = state.as_str();
        let mut labels = Profiler::common_labels(1);

        labels.push(Label {
            key: "event",
            value: LabelValue::Str(Cow::Borrowed(event)),
        });

        let n_labels = labels.len();

        let dict = match dictionary::try_clone_tls_or_global() {
            Ok(d) => d,
            Err(err) => {
                warn!("Failed to clone dictionary: {err}");
                return;
            }
        };
        let known_funcs = dict.known_funcs();
        let thread_fid = Some(match state {
            timeline::State::ThreadStart => known_funcs.thread_start,
            timeline::State::ThreadStop => known_funcs.thread_stop,
            // SAFETY: we only pass in ThreadStart or ThreadEnd to this fn
            _ => unsafe { std::hint::unreachable_unchecked() },
        });
        match self.prepare_and_send_message(
            CallStack {
                frames: vec![ZendFrame {
                    function_id: thread_fid,
                    line: 0,
                }],
                dictionary: dict,
            },
            SampleValue::Timeline { nanoseconds: 1 },
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

        let dict = match dictionary::try_clone_tls_or_global() {
            Ok(d) => d,
            Err(err) => {
                warn!("Failed to clone dictionary: {err}");
                return;
            }
        };
        let fatal_fid = match synth_function_id(
            dict.dictionary(),
            dict.known_strs().fatal,
            Some(file.as_str()),
        ) {
            Ok(id) => Some(id),
            Err(err) => {
                warn!("Failed to build [fatal] frame: {err}");
                None
            }
        };
        let sample = SampleValue::Timeline { nanoseconds: 1 };
        match self.prepare_and_send_message(
            CallStack {
                frames: vec![ZendFrame {
                    function_id: fatal_fid,
                    line,
                }],
                dictionary: dict,
            },
            sample,
            labels,
            now,
        ) {
            Ok(_) => {
                trace!("Sent {sample:?} event 'fatal error' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!(
                    "Failed to send {sample:?} event 'fatal error' with {n_labels} labels to profiler: {err}"
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

        let dict = match dictionary::try_clone_tls_or_global() {
            Ok(d) => d,
            Err(err) => {
                warn!("Failed to clone dictionary: {err}");
                return;
            }
        };
        let opcache_fid = match synth_function_id(
            dict.dictionary(),
            dict.known_strs().opcache_restart,
            Some(file.as_str()),
        ) {
            Ok(id) => Some(id),
            Err(err) => {
                warn!("Failed to build [opcache restart] frame: {err}");
                None
            }
        };
        let sample = SampleValue::Timeline { nanoseconds: 1 };
        match self.prepare_and_send_message(
            CallStack {
                frames: vec![ZendFrame {
                    function_id: opcache_fid,
                    line,
                }],
                dictionary: dict,
            },
            sample,
            labels,
            now,
        ) {
            Ok(_) => {
                trace!(
                    "Sent {sample:?} event 'opcache_restart' with {n_labels} labels to profiler."
                )
            }
            Err(err) => {
                warn!("Failed to send {sample:?} event 'opcache_restart' with {n_labels} labels to profiler: {err}")
            }
        }
    }

    /// This function can be called to collect any kind of inactivity that is happening
    #[cfg_attr(feature = "tracing", tracing::instrument(skip_all, level = "debug"))]
    pub fn collect_idle(&self, now: i64, duration: i64, state: timeline::State) {
        let reason = state.as_str();
        let mut labels = Profiler::common_labels(1);

        labels.push(Label {
            key: "event",
            value: LabelValue::Str(reason.into()),
        });

        let n_labels = labels.len();

        let dict = match dictionary::try_clone_tls_or_global() {
            Ok(d) => d,
            Err(err) => {
                warn!("Failed to clone dictionary: {err}");
                return;
            }
        };
        let idle_fid = Some(dict.known_funcs().idle);
        let sample = SampleValue::Timeline {
            nanoseconds: duration,
        };
        match self.prepare_and_send_message(
            CallStack {
                frames: vec![ZendFrame {
                    function_id: idle_fid,
                    line: 0,
                }],
                dictionary: dict,
            },
            sample,
            labels,
            now,
        ) {
            Ok(_) => {
                trace!("Sent {sample:?} event 'idle' with {n_labels} labels to profiler.")
            }
            Err(err) => {
                warn!("Failed to send {sample:?} event 'idle' with {n_labels} labels to profiler: {err}")
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
        let dict = match dictionary::try_clone_tls_or_global() {
            Ok(d) => d,
            Err(err) => {
                warn!("Failed to clone dictionary: {err}");
                return;
            }
        };
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
        let sample = SampleValue::Timeline {
            nanoseconds: duration,
        };
        match self.prepare_and_send_message(
            CallStack {
                frames: vec![ZendFrame {
                    function_id: Some(dict.known_funcs().gc),
                    line: 0,
                }],
                dictionary: dict,
            },
            sample,
            labels,
            now,
        ) {
            Ok(_) => {
                trace!("Sent {sample:?} event 'gc' with {n_labels} labels and reason {reason} to profiler.")
            }
            Err(err) => {
                warn!("Failed to send {sample:?} event 'gc' with {n_labels} and reason {reason} labels to profiler: {err}")
            }
        }
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_socket_read_time(&self, ed: *mut zend_execute_data, nanoseconds: i64) {
        self.collect_io(
            ed,
            SampleValue::SocketReadTime {
                nanoseconds,
                count: 1,
            },
        )
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_socket_write_time(&self, ed: *mut zend_execute_data, nanoseconds: i64) {
        self.collect_io(
            ed,
            SampleValue::SocketWriteTime {
                nanoseconds,
                count: 1,
            },
        )
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_file_read_time(&self, ed: *mut zend_execute_data, nanoseconds: i64) {
        self.collect_io(
            ed,
            SampleValue::FileIoReadTime {
                nanoseconds,
                count: 1,
            },
        )
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_file_write_time(&self, ed: *mut zend_execute_data, nanoseconds: i64) {
        self.collect_io(
            ed,
            SampleValue::FileIoWriteTime {
                nanoseconds,
                count: 1,
            },
        )
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_socket_read_size(&self, ed: *mut zend_execute_data, bytes: i64) {
        self.collect_io(ed, SampleValue::SocketReadSize { bytes, count: 1 })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_socket_write_size(&self, ed: *mut zend_execute_data, nanoseconds: i64) {
        self.collect_io(
            ed,
            SampleValue::SocketWriteTime {
                nanoseconds,
                count: 1,
            },
        )
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_file_read_size(&self, ed: *mut zend_execute_data, bytes: i64) {
        self.collect_io(ed, SampleValue::FileIoReadSize { bytes, count: 1 })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_file_write_size(&self, ed: *mut zend_execute_data, bytes: i64) {
        self.collect_io(ed, SampleValue::FileIoWriteSize { bytes, count: 1 })
    }

    #[cfg(all(feature = "io_profiling", target_os = "linux"))]
    pub fn collect_io(&self, execute_data: *mut zend_execute_data, value: SampleValue) {
        let result = collect_stack_sample(execute_data);
        match result {
            Ok(call_stack) => {
                let depth = call_stack.frames.len();
                let labels = Profiler::common_labels(0);

                let n_labels = labels.len();

                match self.prepare_and_send_message(
                    call_stack,
                    value,
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

        // No link labels here; link is carried separately.

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
        call_stack: CallStack,
        sample: SampleValue,
        labels: Vec<Label>,
        timestamp: i64,
    ) -> Result<(), Box<TrySendError<ProfilerMessage>>> {
        if let Some(message) = self.prepare_sample_message(call_stack, sample, labels, timestamp) {
            self.message_sender
                .try_send(ProfilerMessage::Sample(message))
                .map_err(Box::new)
        } else {
            debug!("tried to send {sample:?} but that profile type isn't enabled");
            Ok(())
        }
    }

    fn prepare_sample_message(
        &self,
        call_stack: CallStack,
        sample: SampleValue,
        labels: Vec<Label>,
        timestamp: i64,
    ) -> Option<SampleMessage> {
        if self
            .enabled_profiles
            .contains_profile(sample.discriminant())
        {
            let tags = TAGS.with_borrow(Arc::clone);
            Some(SampleMessage {
                key: ProfileIndex { tags },
                value: SampleData {
                    sample,
                    call_stack,
                    labels,
                    timestamp,
                    link: Self::current_link(),
                },
            })
        } else {
            debug!("tried to send {sample:?} but that profile type isn't enabled");
            None
        }
    }

    fn current_link() -> Link {
        // SAFETY: this is set to a noop version if ddtrace wasn't found, and
        // we're getting the profiling context on a PHP thread.
        let context = unsafe { datadog_php_profiling_get_profiling_context.unwrap_unchecked()() };
        Link {
            local_root_span_id: context.local_root_span_id,
            span_id: context.span_id,
        }
    }
}

pub struct JoinError {
    pub num_failures: usize,
}

#[inline]
fn synth_function_id(
    dict: &ProfilesDictionary,
    name: StringId,
    file: Option<&str>,
) -> Result<FunctionId, ProfileError> {
    let strings = dict.strings();
    let function = Function {
        name,
        system_name: StringId::EMPTY,
        file_name: match file {
            Some(f) if !f.is_empty() => strings.try_insert(f)?,
            _ => StringId::EMPTY,
        },
    };
    let id = dict.functions().try_insert(function)?;
    Ok(id.into_raw())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{allocation::DEFAULT_ALLOCATION_SAMPLING_INTERVAL, config::AgentEndpoint};
    use datadog_profiling::exporter::Uri;
    use datadog_profiling::profiles::datatypes::Function;
    use log::LevelFilter;
    use StringId;

    #[allow(dead_code)] // todo: fix tests
    fn get_frames() -> CallStack {
        let dictionary = {
            let dict = PhpProfilesDictionary::try_new().unwrap();
            DdArc::try_new(dict).unwrap()
        };
        let strings = dictionary.dictionary().strings();
        let foobar = strings.try_insert("foobar").unwrap();
        let foobar_php = strings.try_insert("foobar.php").unwrap();

        let functions = dictionary.dictionary().functions();
        let function = Function {
            name: foobar,
            system_name: StringId::EMPTY,
            file_name: foobar_php,
        };
        let function_id = functions.try_insert(function).unwrap();

        CallStack {
            frames: vec![ZendFrame {
                function_id: Some(function_id.into_raw()),
                line: 42,
            }],
            dictionary,
        }
    }

    #[allow(dead_code)] // todo: fix tests
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

    #[allow(dead_code)] // todo: fix tests
    pub fn get_samples() -> Vec<SampleValue> {
        use SampleValue::*;
        // These don't need to come in any specific order.
        vec![
            WallTime {
                nanoseconds: 20,
                count: 10,
            },
            CpuTime { nanoseconds: 30 },
            Alloc {
                bytes: 50,
                count: 40,
            },
            Timeline { nanoseconds: 60 },
            Exception { count: 70 },
            SocketReadTime {
                nanoseconds: 80,
                count: 81,
            },
            SocketWriteTime {
                nanoseconds: 90,
                count: 91,
            },
            FileIoReadTime {
                nanoseconds: 100,
                count: 101,
            },
            FileIoWriteTime {
                nanoseconds: 110,
                count: 111,
            },
            SocketReadSize {
                bytes: 120,
                count: 121,
            },
            SocketWriteSize {
                bytes: 130,
                count: 131,
            },
            FileIoReadSize {
                bytes: 140,
                count: 141,
            },
            FileIoWriteSize {
                bytes: 150,
                count: 151,
            },
        ]
    }

    #[test]
    #[cfg(not(miri))]
    fn profiler_prepare_sample_message_works_cpu_time_and_timeline() {
        let samples = get_samples();
        let frames = get_frames();
        let labels = Profiler::common_labels(0);
        let mut settings = get_system_settings();
        settings.profiling_enabled = true;
        settings.profiling_experimental_cpu_time_enabled = true;
        settings.profiling_timeline_enabled = true;

        let profiler = Profiler::new(&mut settings);

        for sample in samples {
            if let Some(message) = profiler.prepare_sample_message(
                frames.try_clone().unwrap(),
                sample,
                labels.clone(),
                900,
            ) {
                assert_eq!(sample, message.value.sample);
                assert!(profiler
                    .enabled_profiles
                    .contains_profile(sample.discriminant()));
                assert_eq!(message.value.timestamp, 900);
            } else {
                assert!(!profiler
                    .enabled_profiles
                    .contains_profile(sample.discriminant()));
            }
        }
    }
}
