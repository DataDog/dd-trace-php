use crate::profiling::ProfileIndex;
use crossbeam_channel::{select, Receiver};
use datadog_profiling::exporter::{Endpoint, File};
use datadog_profiling::profile::EncodedProfile;
use log::{debug, info, trace, warn};
use std::sync::{Arc, Barrier};
use std::time::Duration;

const PROFILING_FAMILY: &'static str = "php";
const PROFILING_LIBRARY_NAME: &'static str = "dd-trace-php";
const PROFILING_LIBRARY_VERSION: &'static str = env!("CARGO_PKG_VERSION");

pub struct UploadMessage {
    pub index: ProfileIndex,
    pub profile: EncodedProfile,
}

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

        let endpoint: Endpoint = (&*index.endpoint).try_into()?;
        let exporter = datadog_profiling::exporter::ProfileExporter::new(
            PROFILING_LIBRARY_NAME,
            PROFILING_LIBRARY_VERSION,
            PROFILING_FAMILY,
            Some(index.tags),
            endpoint,
        )?;

        let start = profile.start.into();
        let end = profile.end.into();
        let files = &[File {
            name: "profile.pprof",
            bytes: profile.buffer.as_slice(),
        }];

        let timeout = Duration::from_secs(10);
        let request = exporter.build(start, end, files, None, timeout)?;
        debug!("Sending profile to: {}", index.endpoint);
        let result = exporter.send(request, None)?;
        Ok(result.status().as_u16())
    }

    pub fn run(self) {
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
                    Ok(upload_message) => match Self::upload(upload_message) {
                        Ok(status) => {
                            if status >= 400 {
                                warn!(
                                    "Unexpected HTTP status when sending profile (HTTP {}).",
                                    status
                                )
                            } else {
                                info!("Successfully uploaded profile (HTTP {}).", status)
                            }
                        }
                        Err(err) => {
                            warn!("Failed to upload profile: {}", err)
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
