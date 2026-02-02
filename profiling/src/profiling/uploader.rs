use crate::config::AgentEndpoint;
use crate::profiling::{
    update_background_cpu_time, UploadMessage, UploadRequest, BACKGROUND_THREAD_CPU_TIME_NS,
    STACK_WALK_COUNT, STACK_WALK_CPU_TIME_NS,
};
use crate::{PROFILER_NAME_STR, PROFILER_VERSION_STR};
use chrono::{DateTime, Utc};
use cpu_time::ThreadTime;
use crossbeam_channel::{select, Receiver};
use libdd_common::Endpoint;
use log::{debug, info, warn};
use serde_json::json;
use std::borrow::Cow;
use std::str;
use std::sync::{Arc, Barrier};

#[cfg(feature = "debug_stats")]
use crate::allocation::{ALLOCATION_PROFILING_COUNT, ALLOCATION_PROFILING_SIZE};
#[cfg(feature = "debug_stats")]
use crate::exception::EXCEPTION_PROFILING_EXCEPTION_COUNT;
use std::sync::atomic::Ordering;

pub struct Uploader {
    fork_barrier: Arc<Barrier>,
    receiver: Receiver<UploadMessage>,
    output_pprof: Option<Cow<'static, str>>,
    endpoint: AgentEndpoint,
    start_time: String,
}

impl Uploader {
    pub fn new(
        fork_barrier: Arc<Barrier>,
        receiver: Receiver<UploadMessage>,
        output_pprof: Option<Cow<'static, str>>,
        endpoint: AgentEndpoint,
        start_time: DateTime<Utc>,
    ) -> Self {
        Self {
            fork_barrier,
            receiver,
            output_pprof,
            endpoint,
            start_time: start_time.to_rfc3339_opts(chrono::SecondsFormat::Secs, true),
        }
    }

    /// This function will not only create the internal metadata JSON representation, but is also
    /// in charge to reset all those counters back to 0.
    fn create_internal_metadata() -> Option<serde_json::Value> {
        let capacity = 3 + cfg!(feature = "debug_stats") as usize * 3;
        let mut metadata = serde_json::Map::with_capacity(capacity);
        metadata.insert(
            "stack_walk_count".to_string(),
            json!(STACK_WALK_COUNT.swap(0, Ordering::Relaxed)),
        );
        metadata.insert(
            "stack_walk_cpu_time_ns".to_string(),
            json!(STACK_WALK_CPU_TIME_NS.swap(0, Ordering::Relaxed)),
        );
        metadata.insert(
            "background_threads_cpu_time_ns".to_string(),
            json!(BACKGROUND_THREAD_CPU_TIME_NS.swap(0, Ordering::Relaxed)),
        );
        #[cfg(feature = "debug_stats")]
        {
            metadata.insert(
                "exceptions_count".to_string(),
                json!(EXCEPTION_PROFILING_EXCEPTION_COUNT.swap(0, Ordering::Relaxed)),
            );
            metadata.insert(
                "allocations_count".to_string(),
                json!(ALLOCATION_PROFILING_COUNT.swap(0, Ordering::Relaxed)),
            );
            metadata.insert(
                "allocations_size".to_string(),
                json!(ALLOCATION_PROFILING_SIZE.swap(0, Ordering::Relaxed)),
            );
        }
        Some(serde_json::Value::Object(metadata))
    }

    fn create_profiler_info(&self) -> Option<serde_json::Value> {
        let metadata = json!({
            "application": {
                "start_time": self.start_time,
            }
        });
        Some(metadata)
    }

    fn upload(&self, message: Box<UploadRequest>) -> anyhow::Result<u16> {
        let index = message.index;
        let profile = message.profile;

        let profiling_library_name: &str = &PROFILER_NAME_STR;
        let profiling_library_version: &str = &PROFILER_VERSION_STR;
        let agent_endpoint = &self.endpoint;
        let endpoint = Endpoint::try_from(agent_endpoint)?;

        let tags = Some(Arc::unwrap_or_clone(index.tags));
        let mut exporter = libdd_profiling::exporter::ProfileExporter::new(
            profiling_library_name,
            profiling_library_version,
            "php",
            tags,
            endpoint,
        )?;

        let serialized =
            profile.serialize_into_compressed_pprof(Some(message.end_time), message.duration)?;
        exporter.set_timeout(10000); // 10 seconds in milliseconds
        let request = exporter.build(
            serialized,
            &[],
            &[],
            None,
            None,
            Self::create_internal_metadata(),
            self.create_profiler_info(),
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
        let mut last_cpu = ThreadTime::try_now().ok();
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
                        update_background_cpu_time(&mut last_cpu);
                        match pprof_filename {
                            Some(filename) => {
                                let filename_prefix = filename.as_ref();
                                let r = request.profile.serialize_into_compressed_pprof(None, None).unwrap();
                                i += 1;
                                let name = format!("{filename_prefix}.{i}.zst");
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

#[cfg(all(test, feature = "debug_stats"))]
mod tests {
    use super::*;

    #[test]
    fn test_create_internal_metadata() {
        // Set up all counters with known values
        STACK_WALK_COUNT.store(7, Ordering::Relaxed);
        STACK_WALK_CPU_TIME_NS.store(9000, Ordering::Relaxed);
        BACKGROUND_THREAD_CPU_TIME_NS.store(1234, Ordering::Relaxed);
        EXCEPTION_PROFILING_EXCEPTION_COUNT.store(42, Ordering::Relaxed);
        ALLOCATION_PROFILING_COUNT.store(100, Ordering::Relaxed);
        ALLOCATION_PROFILING_SIZE.store(1024, Ordering::Relaxed);

        // Call the function under test
        let metadata = Uploader::create_internal_metadata();

        // Verify the result
        assert!(metadata.is_some());
        let metadata = metadata.unwrap();

        // The metadata should contain all counts
        assert_eq!(
            metadata.get("stack_walk_count").and_then(|v| v.as_u64()),
            Some(7)
        );
        assert_eq!(
            metadata
                .get("stack_walk_cpu_time_ns")
                .and_then(|v| v.as_u64()),
            Some(9000)
        );
        assert_eq!(
            metadata
                .get("background_threads_cpu_time_ns")
                .and_then(|v| v.as_u64()),
            Some(1234)
        );

        assert_eq!(
            metadata.get("exceptions_count").and_then(|v| v.as_u64()),
            Some(42)
        );
        {
            assert_eq!(
                metadata.get("allocations_count").and_then(|v| v.as_u64()),
                Some(100)
            );
            assert_eq!(
                metadata.get("allocations_size").and_then(|v| v.as_u64()),
                Some(1024)
            );
        }
    }
}
