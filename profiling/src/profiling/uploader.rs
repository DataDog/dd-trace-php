use crate::config::AgentEndpoint;
use crate::profiling::{UploadMessage, UploadRequest};
use crate::{PROFILER_NAME_STR, PROFILER_VERSION_STR};
use chrono::{DateTime, Utc};
use crossbeam_channel::{select, Receiver};
use ddcommon::Endpoint;
use log::{debug, info, warn};
use reqwest::blocking::{multipart, ClientBuilder};
use reqwest::header::{HeaderMap, HeaderValue};
use serde_json::json;
use std::borrow::Cow;
use std::ops::Sub;
use std::str;
use std::sync::{Arc, Barrier};

#[cfg(feature = "allocation_profiling")]
use crate::allocation::{ALLOCATION_PROFILING_COUNT, ALLOCATION_PROFILING_SIZE};

#[cfg(feature = "exception_profiling")]
use crate::exception::EXCEPTION_PROFILING_EXCEPTION_COUNT;

#[cfg(any(
    feature = "exception_profiling",
    feature = "allocation_profiling",
    feature = "io_profiling"
))]
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
        cfg_if::cfg_if! {
            if #[cfg(all(feature = "exception_profiling", feature = "allocation_profiling"))] {
                Some(json!({
                    "exceptions_count": EXCEPTION_PROFILING_EXCEPTION_COUNT.swap(0, Ordering::SeqCst),
                    "allocations_count": ALLOCATION_PROFILING_COUNT.swap(0, Ordering::SeqCst),
                    "allocations_size": ALLOCATION_PROFILING_SIZE.swap(0, Ordering::SeqCst),
                }))
            } else if #[cfg(feature = "allocation_profiling")] {
                Some(json!({
                    "allocations_count": ALLOCATION_PROFILING_COUNT.swap(0, Ordering::SeqCst),
                    "allocations_size": ALLOCATION_PROFILING_SIZE.swap(0, Ordering::SeqCst),
                }))
            } else if #[cfg(feature = "exception_profiling")] {
                Some(json!({
                    "exceptions_count": EXCEPTION_PROFILING_EXCEPTION_COUNT.swap(0, Ordering::SeqCst),
                }))
            } else {
                None
            }
        }
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

        // Serialize profile as compressed pprof
        let serialized =
            profile.serialize_into_compressed_pprof(Some(message.end_time), message.duration)?;

        // Prepare multipart form with only profile.pprof
        let mut form = multipart::Form::new().part(
            "profile.pprof",
            multipart::Part::bytes(serialized.buffer)
                .file_name("profile.pprof")
                .mime_str("application/octet-stream")?,
        );

        // Build tags string
        let tags = Some(Arc::unwrap_or_clone(index.tags));
        let mut tags_profiler = String::new();
        if let Some(tags_vec) = &tags {
            for tag in tags_vec {
                tags_profiler.push_str(tag.as_ref());
                tags_profiler.push(',');
            }
        }

        // Build internal metadata
        let mut internal: serde_json::value::Value =
            Self::create_internal_metadata().unwrap_or_else(|| json!({}));
        internal["libdatadog_version"] = json!(env!("CARGO_PKG_VERSION"));

        // todo: use start_time
        let start = message.end_time.sub(message.duration.unwrap());

        // Build event JSON
        let event = json!({
            "attachments": vec!["profile.pprof"],
            "tags_profiler": tags_profiler,
            "start": DateTime::<Utc>::from(start).format("%Y-%m-%dT%H:%M:%S%.9fZ").to_string(),
            "end": DateTime::<Utc>::from(message.end_time).format("%Y-%m-%dT%H:%M:%S%.9fZ").to_string(),
            "family": "php",
            "version": "4",
            "internal": internal,
            "info": self.create_profiler_info().unwrap_or_else(|| json!({})),
        })
        .to_string();

        form = form.part(
            "event",
            multipart::Part::text(event)
                .file_name("event.json")
                .mime_str("application/json")?,
        );

        // Build headers
        let mut headers = HeaderMap::new();
        headers.insert("Connection", HeaderValue::from_static("close"));
        headers.insert(
            "DD-EVP-ORIGIN",
            HeaderValue::from_static(&PROFILER_NAME_STR),
        );
        headers.insert(
            "DD-EVP-ORIGIN-VERSION",
            HeaderValue::from_static(&PROFILER_VERSION_STR),
        );

        // Build blocking client with optional unix socket
        let mut client = ClientBuilder::new().timeout(std::time::Duration::from_secs(10));
        let mut endpoint = Endpoint::try_from(&self.endpoint)?.url;
        if let AgentEndpoint::Socket(path) = &self.endpoint {
            let mut parts = endpoint.into_parts();
            // reqwest doesn't support "unix" as a scheme, only "http" or
            // "https", and "unix" urls as documented are currently HTTP only.
            parts.scheme = Some(http::uri::Scheme::HTTP);
            endpoint = parts.try_into()?;
            let socket = path.display();
            debug!("using unix socket `{socket}` for profiling upload to URL `{endpoint}`");
            client = client.unix_socket(path.as_path());
        };
        let client = client.build()?;

        let response = client
            .post(endpoint.to_string())
            .headers(headers)
            .multipart(form)
            .send()?;

        Ok(response.status().as_u16())
    }

    // fn upload(&self, message: Box<UploadRequest>) -> anyhow::Result<u16> {
    //     let index = message.index;
    //     let profile = message.profile;
    //
    //     let profiling_library_name: &str = &PROFILER_NAME_STR;
    //     let profiling_library_version: &str = &PROFILER_VERSION_STR;
    //     let agent_endpoint = &self.endpoint;
    //     let endpoint = Endpoint::try_from(agent_endpoint)?;
    //
    //     let tags = Some(Arc::unwrap_or_clone(index.tags));
    //     let mut exporter = datadog_profiling::exporter::ProfileExporter::new(
    //         profiling_library_name,
    //         profiling_library_version,
    //         "php",
    //         tags,
    //         endpoint,
    //     )?;
    //
    //     let serialized =
    //         profile.serialize_into_compressed_pprof(Some(message.end_time), message.duration)?;
    //     exporter.set_timeout(10000); // 10 seconds in milliseconds
    //     let request = exporter.build(
    //         serialized,
    //         &[],
    //         &[],
    //         None,
    //         Self::create_internal_metadata(),
    //         self.create_profiler_info(),
    //     )?;
    //     debug!("Sending profile to: {agent_endpoint}");
    //     let result = exporter.send(request, None)?;
    //     Ok(result.status().as_u16())
    // }

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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_create_internal_metadata() {
        // Set up all counters with known values
        EXCEPTION_PROFILING_EXCEPTION_COUNT.store(42, Ordering::SeqCst);
        ALLOCATION_PROFILING_COUNT.store(100, Ordering::SeqCst);
        ALLOCATION_PROFILING_SIZE.store(1024, Ordering::SeqCst);

        // Call the function under test
        let metadata = Uploader::create_internal_metadata();

        // Verify the result
        #[cfg(not(any(feature = "exception_profiling", feature = "allocation_profiling")))]
        {
            assert!(metadata.is_none());
            return;
        }
        assert!(metadata.is_some());
        let metadata = metadata.unwrap();

        // The metadata should contain all counts

        #[cfg(feature = "exception_profiling")]
        assert_eq!(
            metadata.get("exceptions_count").and_then(|v| v.as_u64()),
            Some(42)
        );
        #[cfg(feature = "allocation_profiling")]
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
