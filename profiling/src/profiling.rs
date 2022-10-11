use crate::bindings::{datadog_php_profiling_get_profiling_context, zend_execute_data};
use crate::profile_builder::{ProfileBuilder, WallTime};
use crate::uploading::{UploadMessage, Uploader};
use crate::{stack_walking, AgentEndpoint, RequestLocals, PHP_VERSION};
use crossbeam_channel::{Receiver, Sender, TrySendError};
use datadog_profiling::exporter::Tag;
use datadog_profiling::profile::{v2, EncodedProfile};
use lazy_static::lazy_static;
use log::{debug, info, trace, warn};
use std::borrow::Cow;
use std::collections::HashMap;
use std::hash::Hash;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Barrier, Mutex};
use std::thread::JoinHandle;
use std::time::{Duration, Instant, SystemTime};

pub const UPLOAD_PERIOD: Duration = Duration::from_secs(67);

// Guide: upload period / upload timeout should give about the order of
// magnitude for the capacity.
pub const UPLOAD_CHANNEL_CAPACITY: usize = 8;

const WALL_TIME_PERIOD: Duration = Duration::from_millis(10);

pub struct KnownStrings {
    pub endpoint_label: i64,
    pub local_root_span_id: i64,
    pub php_no_func: i64,
    pub span_id: i64,
    pub truncated: i64,
}

pub struct KnownValueTypes {
    pub sample: v2::ValueType,
    pub wall_time: v2::ValueType,
    pub cpu_time: v2::ValueType,
}

pub struct KnownFunctions {
    pub truncated: u64,
}

pub struct KnownThings {
    pub strings: KnownStrings,
    pub value_types: KnownValueTypes,
    pub functions: KnownFunctions,
    pub string_table: Arc<v2::LockedStringTable>,
    pub symbol_table: Arc<SymbolTable>,
}

lazy_static! {
    pub static ref KNOWN: KnownThings = {
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
            endpoint_label: string_table.intern("trace endpoint"),
            local_root_span_id: string_table.intern("local root span id"),
            php_no_func: string_table.intern("<?php"),
            span_id: string_table.intern("span id"),
            truncated: string_table.intern("[truncated]"),
        };

        let symbol_table = SymbolTable::default();
        let functions = KnownFunctions {
            truncated: symbol_table.add(v2::Function {
                id: 0,
                name: strings.truncated,
                system_name: 0,
                filename: 0,
                start_line: 0,
            }),
        };

        KnownThings {
            strings,
            value_types,
            functions,
            string_table: Arc::new(v2::LockedStringTable::from(string_table)),
            symbol_table: Arc::new(symbol_table),
        }
    };
}

pub type SymbolTable = v2::LockedProfileSet<v2::Function>;

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
    /// The stack trace represented with v2::Line instead of Locations because:
    ///  1. We don't want to do aggregation of locations on the collecting thread.
    ///  2. We don't have to worry about multiple lines per location in PHP.
    /// So we get away with the cheaper v2::Line.
    pub frames: Vec<v2::Line>,
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
    endpoints: v2::Endpoints,
    fork_barrier: Arc<Barrier>,
    fork_receiver: Receiver<()>,
    profiles: HashMap<ProfileIndex, ProfileBuilder>,
    vm_interrupts: Arc<Mutex<Vec<VmInterrupt>>>,
    message_receiver: Receiver<ProfilerMessage>,
    upload_sender: Sender<UploadMessage>,
    wall_time_period: Duration,
    upload_period: Duration,
}

impl TimeCollector {
    fn handle_timeout(&mut self, _last_export: &WallTime) -> WallTime {
        let wall_export = WallTime::now();
        if self.profiles.is_empty() {
            info!("No profiles to upload.");
            return wall_export;
        }

        for (index, profile) in self.profiles.drain() {
            if let Err(err) = (|| -> anyhow::Result<()> {
                let mut ir = profile.end(wall_export.clone())?;
                if !self.endpoints.is_empty() {
                    for sample in ir.profile.samples.iter_mut() {
                        self.endpoints.add_trace_resource_label(sample)
                    }
                }
                let mut buffer = Vec::new();
                ir.profile.write_to_vec(&mut buffer)?;
                let profile = EncodedProfile {
                    start: ir.start_time,
                    end: ir.end_time,
                    buffer,
                };
                let message = UploadMessage { index, profile };
                Ok(self.upload_sender.try_send(message)?)
            })() {
                warn!("Failed to upload profile: {}", err);
            }
        }
        wall_export
    }

    fn handle_resource_message(&mut self, message: LocalRootSpanResourceMessage) {
        self.endpoints
            .add(message.local_root_span_id, message.resource)
    }

    fn handle_sample_message(&mut self, message: SampleMessage, started_at: &WallTime) {
        let profile = if let Some(value) = self.profiles.get_mut(&message.key) {
            value
        } else {
            self.profiles.insert(
                message.key.clone(),
                ProfileBuilder::new(
                    *started_at,
                    message.key.sample_types.clone(),
                    Some(KNOWN.value_types.wall_time),
                    WALL_TIME_PERIOD.as_nanos().try_into().unwrap(),
                    KNOWN.string_table.clone(),
                    KNOWN.symbol_table.clone(),
                ),
            );
            self.profiles
                .get_mut(&message.key)
                .expect("entry to exist; just inserted it")
        };

        let mut locations = vec![];

        let values = message.value.sample_values;
        let labels = message.value.labels;

        for line in message.value.frames.into_iter() {
            let location = v2::Location {
                id: 0,
                mapping_id: 0,
                address: 0,
                lines: vec![line],
                is_folded: false,
            };

            let location_id = profile.add_location(location);
            locations.push(location_id);
        }

        let sample = v2::Sample {
            location_ids: locations,
            labels,
        };
        profile.add_sample(sample, values);
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

impl Profiler {
    pub fn new() -> Self {
        let fork_barrier = Arc::new(Barrier::new(3));
        let (fork_sender0, fork_receiver0) = crossbeam_channel::bounded(1);
        let vm_interrupts = Arc::new(Mutex::new(Vec::with_capacity(1)));
        let (message_sender, message_receiver) = crossbeam_channel::bounded(100);
        let (upload_sender, upload_receiver) = crossbeam_channel::bounded(UPLOAD_CHANNEL_CAPACITY);
        let (fork_sender1, fork_receiver1) = crossbeam_channel::bounded(1);
        let time_collector = TimeCollector {
            endpoints: v2::Endpoints::new(
                KNOWN.strings.local_root_span_id,
                KNOWN.strings.endpoint_label,
            ),
            fork_barrier: fork_barrier.clone(),
            fork_receiver: fork_receiver0,
            profiles: Default::default(),
            vm_interrupts: vm_interrupts.clone(),
            message_receiver,
            upload_sender,
            wall_time_period: WALL_TIME_PERIOD,
            upload_period: UPLOAD_PERIOD,
        };

        let uploader = Uploader::new(fork_barrier.clone(), fork_receiver1, upload_receiver);
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
        let frames = stack_walking::collect(execute_data);
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
            let tag =
                Tag::new("runtime_version", version).expect("runtime_version to be a valid tag");
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
}
