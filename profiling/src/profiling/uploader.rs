use crate::profiling::UploadMessage;
use crate::{PROFILER_NAME_STR, PROFILER_VERSION_STR};
use crossbeam_channel::{select, Receiver};
use datadog_profiling::exporter::{Endpoint, File};
use log::{debug, info, trace, warn};
use std::str;
use std::sync::{Arc, Barrier};
use std::time::Duration;

pub struct Uploader {
    fork_barrier: Arc<Barrier>,
    fork_receiver: Receiver<()>,
    upload_receiver: Receiver<UploadMessage>,
}

impl Uploader {
    pub fn new(
        fork_barrier: Arc<Barrier>,
        fork_receiver: Receiver<()>,
        upload_receiver: Receiver<UploadMessage>,
    ) -> Self {
        Self {
            fork_barrier,
            fork_receiver,
            upload_receiver,
        }
    }

    fn upload(message: UploadMessage) -> anyhow::Result<u16> {
        let index = message.index;
        let profile = message.profile;

        let profiling_library_name: &str = &PROFILER_NAME_STR;
        let profiling_library_version: &str = &PROFILER_VERSION_STR;
        let endpoint: Endpoint = (&*index.endpoint).try_into()?;

        // This is the currently unstable Arc::unwrap_or_clone.
        let tags = Some(Arc::try_unwrap(index.tags).unwrap_or_else(|arc| (*arc).clone()));
        let exporter = datadog_profiling::exporter::ProfileExporter::new(
            profiling_library_name,
            profiling_library_version,
            "php",
            tags,
            endpoint,
        )?;

        let serialized = profile.serialize(Some(message.end_time), message.duration)?;
        let start = serialized.start.into();
        let end = serialized.end.into();
        let files = &[File {
            name: "profile.pprof",
            bytes: serialized.buffer.as_slice(),
        }];
        let timeout = Duration::from_secs(10);
        let request = exporter.build(start, end, files, None, None, timeout)?;
        debug!("Sending profile to: {}", index.endpoint);
        let result = exporter.send(request, None)?;
        Ok(result.status().as_u16())
    }

    pub fn run(&self) {
        /* Safety: Called from Profiling::new, which is after config is
         * initialized, and before it's destroyed in mshutdown.
         */
        let pprof_filename = unsafe { crate::config::profiling_output_pprof() };
        let mut i = 0;

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
                    Ok(upload_message) => {
                        match pprof_filename.as_ref() {
                            Some(filename) => {
                                let r = upload_message.profile.serialize(None, None).unwrap();
                                i += 1;
                                std::fs::write(format!("{filename}.{i}"), r.buffer).expect("write to succeed")
                            },
                            None => match Self::upload(upload_message) {
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
                    _ => {
                        trace!("No more upload messages to handle; joining thread.");
                        break;
                    }

                },
            }
        }
    }
}
