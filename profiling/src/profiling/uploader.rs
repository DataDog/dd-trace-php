#[cfg(feature = "allocation_profiling")]
use crate::allocation::{ALLOCATION_PROFILING_COUNT, ALLOCATION_PROFILING_SIZE};
use crate::config::AgentEndpoint;
#[cfg(feature = "exception_profiling")]
use crate::exception::EXCEPTION_PROFILING_EXCEPTION_COUNT;
use crate::profiling::{UploadMessage, UploadRequest};
use crate::{PROFILER_NAME_STR, PROFILER_VERSION_STR};
use chrono::{DateTime, Utc};
use crossbeam_channel::{select, Receiver};
use datadog_profiling::exporter::File;
use ddcommon::Endpoint;
use log::{debug, info, warn};
use serde_json::json;
use std::borrow::Cow;
use std::str;
use std::sync::{Arc, Barrier};

#[cfg(any(
    feature = "exception_profiling",
    feature = "allocation_profiling",
    feature = "io_profiling"
))]
use std::sync::atomic::Ordering;

#[cfg(all(feature = "io_profiling", target_os = "linux"))]
use datadog_profiling::api::UpscalingInfo;

#[cfg(all(feature = "io_profiling", target_os = "linux"))]
use crate::io::{
    FILE_READ_SIZE_PROFILING_INTERVAL, FILE_READ_SIZE_SAMPLE_COUNT,
    FILE_READ_TIME_PROFILING_INTERVAL, FILE_READ_TIME_SAMPLE_COUNT,
    FILE_WRITE_SIZE_PROFILING_INTERVAL, FILE_WRITE_SIZE_SAMPLE_COUNT,
    FILE_WRITE_TIME_PROFILING_INTERVAL, FILE_WRITE_TIME_SAMPLE_COUNT,
    SOCKET_READ_SIZE_PROFILING_INTERVAL, SOCKET_READ_SIZE_SAMPLE_COUNT,
    SOCKET_READ_TIME_PROFILING_INTERVAL, SOCKET_READ_TIME_SAMPLE_COUNT,
    SOCKET_WRITE_SIZE_PROFILING_INTERVAL, SOCKET_WRITE_SIZE_SAMPLE_COUNT,
    SOCKET_WRITE_TIME_PROFILING_INTERVAL, SOCKET_WRITE_TIME_SAMPLE_COUNT,
};

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
        #[cfg(all(feature = "exception_profiling", feature = "allocation_profiling"))]
        Some(json!({
            "exceptions_count": EXCEPTION_PROFILING_EXCEPTION_COUNT.swap(0, Ordering::SeqCst),
            "allocations_count": ALLOCATION_PROFILING_COUNT.swap(0, Ordering::SeqCst),
            "allocations_size": ALLOCATION_PROFILING_SIZE.swap(0, Ordering::SeqCst),
        }));
        #[cfg(feature = "allocation_profiling")]
        Some(json!({
            "allocations_count": ALLOCATION_PROFILING_COUNT.swap(0, Ordering::SeqCst),
            "allocations_size": ALLOCATION_PROFILING_SIZE.swap(0, Ordering::SeqCst),
        }));
        #[cfg(feature = "exception_profiling")]
        Some(json!({
            "exceptions_count": EXCEPTION_PROFILING_EXCEPTION_COUNT.swap(0, Ordering::SeqCst),
        }));
        None
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
        #[cfg(all(feature = "io_profiling", target_os = "linux"))]
        let mut profile = message.profile;
        #[cfg(not(all(feature = "io_profiling", target_os = "linux")))]
        let profile = message.profile;

        #[cfg(all(feature = "io_profiling", target_os = "linux"))]
        macro_rules! add_io_profiling_rules {
            ( $profile:expr, $sample_types:expr,
              $( ($type_str:expr, $interval:expr, $count:expr) ),+ $(,)? ) => {
                $(
                    if let Some(offset) = $sample_types.iter().position(|&x| x.r#type == $type_str) {
                        let count_value = $count.swap(0, Ordering::SeqCst);
                        if count_value > 0 {
                            let upscaling_info = UpscalingInfo::PoissonNonSampleTypeCount {
                                sum_value_offset: offset,
                                count_value,
                                sampling_distance: $interval.load(Ordering::SeqCst),
                            };
                            if let Err(err) = $profile.add_upscaling_rule(&[offset], "", "", upscaling_info) {
                                warn!(
                                    "Failed to add upscaling rule for {}: samples reported may be incorrect: {err}",
                                    $type_str
                                );
                            }
                        }
                    }
                )+
            }
        }

        #[cfg(all(feature = "io_profiling", target_os = "linux"))]
        add_io_profiling_rules!(
            profile,
            index.sample_types,
            (
                "socket-read-time",
                SOCKET_READ_TIME_PROFILING_INTERVAL,
                SOCKET_READ_TIME_SAMPLE_COUNT
            ),
            (
                "socket-write-time",
                SOCKET_WRITE_TIME_PROFILING_INTERVAL,
                SOCKET_WRITE_TIME_SAMPLE_COUNT
            ),
            (
                "file-io-read-time",
                FILE_READ_TIME_PROFILING_INTERVAL,
                FILE_READ_TIME_SAMPLE_COUNT
            ),
            (
                "file-io-write-time",
                FILE_WRITE_TIME_PROFILING_INTERVAL,
                FILE_WRITE_TIME_SAMPLE_COUNT
            ),
            (
                "socket-read-size",
                SOCKET_READ_SIZE_PROFILING_INTERVAL,
                SOCKET_READ_SIZE_SAMPLE_COUNT
            ),
            (
                "socket-write-size",
                SOCKET_WRITE_SIZE_PROFILING_INTERVAL,
                SOCKET_WRITE_SIZE_SAMPLE_COUNT
            ),
            (
                "file-io-read-size",
                FILE_READ_SIZE_PROFILING_INTERVAL,
                FILE_READ_SIZE_SAMPLE_COUNT
            ),
            (
                "file-io-write-size",
                FILE_WRITE_SIZE_PROFILING_INTERVAL,
                FILE_WRITE_SIZE_SAMPLE_COUNT
            )
        );

        let profiling_library_name: &str = &PROFILER_NAME_STR;
        let profiling_library_version: &str = &PROFILER_VERSION_STR;
        let agent_endpoint = &self.endpoint;
        let endpoint = Endpoint::try_from(agent_endpoint)?;

        let tags = Some(Arc::unwrap_or_clone(index.tags));
        let mut exporter = datadog_profiling::exporter::ProfileExporter::new(
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
        exporter.set_timeout(10000); // 10 seconds in milliseconds
        let request = exporter.build(
            start,
            end,
            &[],
            files,
            None,
            endpoint_counts,
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
