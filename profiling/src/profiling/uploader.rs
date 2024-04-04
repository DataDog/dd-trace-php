use crate::allocation::{ALLOCATION_PROFILING_COUNT, ALLOCATION_PROFILING_SIZE};
use crate::config::AgentEndpoint;
use crate::exception::EXCEPTION_PROFILING_EXCEPTION_COUNT;
use crate::profiling::{UploadMessage, UploadRequest};
use crate::{PROFILER_NAME_STR, PROFILER_VERSION_STR};
use crossbeam_channel::{select, Receiver};
use datadog_profiling::exporter::{Endpoint, File};
use log::{debug, info, warn};
use serde_json::json;
use std::borrow::Cow;
use std::str;
use std::sync::atomic::Ordering;
use std::sync::{Arc, Barrier};
use std::time::Duration;

pub struct Uploader {
    fork_barrier: Arc<Barrier>,
    receiver: Receiver<UploadMessage>,
    output_pprof: Option<Cow<'static, str>>,
    endpoint: AgentEndpoint,
}

impl Uploader {
    pub fn new(
        fork_barrier: Arc<Barrier>,
        receiver: Receiver<UploadMessage>,
        output_pprof: Option<Cow<'static, str>>,
        endpoint: AgentEndpoint,
    ) -> Self {
        Self {
            fork_barrier,
            receiver,
            output_pprof,
            endpoint,
        }
    }

    /// This function will not only create the internal metadata JSON representation, but is also
    /// in charge to reset all those counters back to 0.
    fn create_internal_metadata() -> Option<serde_json::Value> {
        let metadata = json!({
            "exceptions_count": EXCEPTION_PROFILING_EXCEPTION_COUNT.swap(0, Ordering::SeqCst),
            "allocations_count": ALLOCATION_PROFILING_COUNT.swap(0, Ordering::SeqCst),
            "allocations_size": ALLOCATION_PROFILING_SIZE.swap(0, Ordering::SeqCst),
        });
        Some(metadata)
    }

    fn upload(&self, message: UploadRequest) -> anyhow::Result<u16> {
        let index = message.index;
        let profile = message.profile;

        let profiling_library_name: &str = &PROFILER_NAME_STR;
        let profiling_library_version: &str = &PROFILER_VERSION_STR;
        let agent_endpoint = &self.endpoint;
        let endpoint = Endpoint::try_from(agent_endpoint)?;

        // This is the currently unstable Arc::unwrap_or_clone.
        let tags = Some(Arc::try_unwrap(index.tags).unwrap_or_else(|arc| (*arc).clone()));
        let exporter = datadog_profiling::exporter::ProfileExporter::new(
            profiling_library_name,
            profiling_library_version,
            "php",
            tags,
            endpoint,
        )?;

        let serialized =
            profile.serialize_into_compressed_pprof(Some(message.end_time), message.duration)?;
        let endpoint_counts = Some(&serialized.endpoints_stats);
        let start = serialized.start.into();
        let end = serialized.end.into();
        let files = &[File {
            name: "profile.pprof",
            bytes: serialized.buffer.as_slice(),
        }];
        let timeout = Duration::from_secs(10);
        let request = exporter.build(
            start,
            end,
            &[],
            files,
            None,
            endpoint_counts,
            Self::create_internal_metadata(),
            None,
            timeout,
        )?;
        debug!("Sending profile to: {agent_endpoint}");
        let result = exporter.send(request, None)?;
        Ok(result.status().as_u16())
    }

    pub fn run(self) {
        /* Safety: Called from Profiling::new, which is after config is
         * initialized, and before it's destroyed in mshutdown.
         */
        let pprof_filename = &self.output_pprof;
        let mut i = 0;

        loop {
            /* Since profiling uploads are going over the Internet and not just
             * the local network, it would be ideal if they were the lowest
             * priority message, but crossbeam selects at random.
             * todo: fix fork message priority.
             */
            select! {
                recv(self.receiver) -> message => match message {
                    Ok(UploadMessage::Pause) => {
                        // First, wait for every thread to finish what they are currently doing.
                        self.fork_barrier.wait();
                        // Then, wait for the fork to be completed.
                        self.fork_barrier.wait();
                    },

                    Ok(UploadMessage::Upload(request)) => {
                        match pprof_filename {
                            Some(filename) => {
                                let filename_prefix = filename.as_ref();
                                let r = request.profile.serialize_into_compressed_pprof(None, None).unwrap();
                                i += 1;
                                let name = format!("{filename_prefix}.{i}.lz4");
                                std::fs::write(&name, r.buffer).expect("write to succeed");
                                info!("Successfully wrote profile to {name}");
                            },
                            None => match self.upload(request) {
                                Ok(status) => {
                                    if status >= 400 {
                                        warn!("Unexpected HTTP status when sending profile (HTTP {status}).")
                                    } else {
                                        info!("Successfully uploaded profile (HTTP {status}).")
                                    }
                                }
                                Err(err) => {
                                    warn!("Failed to upload profile: {err}")
                                }
                            },
                        }
                    },

                    // This condition is the only way this loop terminates cleanly. It happens when:
                    // > A message could not be received because the channel is empty and
                    // > disconnected.
                    // In other words, when all Senders attached to this Receiver are dropped, then
                    // this RecvError occurs, and the loop will terminate.
                    Err(_) => {
                        break;
                    }

                },
            }
        }
    }
}
