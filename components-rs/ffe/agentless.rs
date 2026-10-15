// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use super::settings::AgentlessEndpoint;
use super::{ddog_ffe_has_config, set_delivery_state, store_config, DeliveryState};
use crate::log::{self, Log};
use datadog_ffe::cdn::CdnConfig;
use datadog_ffe::rules_based::{Configuration, UniversalFlagConfig};
use datadog_sidecar::service::blocking::{ping, subscribe_cdn_configuration};
use datadog_sidecar::shm_remote_config::cdn::{CdnDeliveryState, CdnReader};
use std::ffi::{c_void, CString};
use std::thread::JoinHandle;
use std::time::{Duration, Instant};
use tokio_util::sync::CancellationToken;

#[derive(Clone)]
pub(crate) struct AgentlessWorkerConfig {
    subscription: CdnConfig,
    pub(crate) sidecar_self_telemetry: bool,
    pub(crate) thread_cleanup: Option<extern "C" fn(*mut c_void)>,
}

impl AgentlessWorkerConfig {
    pub(crate) fn new(
        endpoint: AgentlessEndpoint,
        poll_interval: Duration,
        request_timeout: Duration,
    ) -> Self {
        Self {
            subscription: CdnConfig {
                endpoint,
                poll_interval,
                request_timeout,
                language: "php".to_owned(),
                version: include_str!("../../VERSION").trim().to_owned(),
            },
            thread_cleanup: None,
            sidecar_self_telemetry: false,
        }
    }
}

// PHP intercepts Rust TLS destructors to permit extension unloading. Threads
// without PHP GSHUTDOWN must explicitly drain that registry after their runtime
// and logging guards are dropped, including on an early return or unwind.
struct ThreadCleanup(Option<extern "C" fn(*mut c_void)>);

impl Drop for ThreadCleanup {
    fn drop(&mut self) {
        if let Some(cleanup) = self.0 {
            cleanup(std::ptr::null_mut());
        }
    }
}

struct RunningWorker {
    cancellation: CancellationToken,
    thread: JoinHandle<()>,
}

#[derive(Debug, Eq, PartialEq)]
pub(crate) enum WorkerStartError {
    ThreadUnavailable,
}

/// Owns a local IPC/shared-memory reader. CDN HTTP runs in the sidecar subprocess.
pub(crate) struct AgentlessWorker {
    config: AgentlessWorkerConfig,
    running: Option<RunningWorker>,
    restart_after_fork: bool,
    permanently_stopped: bool,
}

impl AgentlessWorker {
    pub(crate) fn new(config: AgentlessWorkerConfig) -> Self {
        Self {
            config,
            running: None,
            restart_after_fork: false,
            permanently_stopped: false,
        }
    }

    pub(crate) fn start(&mut self) -> Result<bool, WorkerStartError> {
        // A reader that exits unexpectedly must not suppress later retries.
        if self
            .running
            .as_ref()
            .is_some_and(|worker| worker.thread.is_finished())
        {
            self.stop_running();
        }
        if self.running.is_some() || self.permanently_stopped {
            return Ok(false);
        }

        let cancellation = CancellationToken::new();
        let thread_cancellation = cancellation.clone();
        let config = self.config.clone();
        let log_dispatch = tracing::dispatcher::get_default(Clone::clone);
        let thread = std::thread::Builder::new()
            .name("ddtrace-ffe-agentless".to_owned())
            .spawn(move || {
                let _thread_cleanup = ThreadCleanup(config.thread_cleanup);
                let _log_guard = tracing::dispatcher::set_default(&log_dispatch);
                let mut warned = false;
                while !thread_cancellation.is_cancelled() {
                    if read_configuration(&config, &thread_cancellation).is_err() {
                        set_delivery_state(if ddog_ffe_has_config() {
                            DeliveryState::Stale
                        } else {
                            DeliveryState::Starting
                        });
                        if !warned {
                            log::log(Log::Warn, "Feature Flags CDN sidecar is unavailable; retaining the last configuration");
                            warned = true;
                        }
                    }
                    if !thread_cancellation.is_cancelled() {
                        std::thread::park_timeout(Duration::from_secs(1));
                    }
                }
            })
            .map_err(|_| WorkerStartError::ThreadUnavailable)?;

        self.running = Some(RunningWorker {
            cancellation,
            thread,
        });
        Ok(true)
    }

    pub(crate) fn shutdown(&mut self) {
        self.permanently_stopped = true;
        self.stop_running();
    }

    pub(crate) fn prepare_for_fork(&mut self) {
        self.restart_after_fork = self.running.is_some() && !self.permanently_stopped;
        self.stop_running();
    }

    pub(crate) fn resume_after_fork_parent(&mut self) -> Result<(), WorkerStartError> {
        self.restart_after_fork()
    }

    pub(crate) fn reset_after_fork_child(&mut self) -> Result<(), WorkerStartError> {
        // `prepare_for_fork` joined the only worker before the process split,
        // so no synchronization primitive can be inherited while held.
        self.restart_after_fork()
    }

    fn restart_after_fork(&mut self) -> Result<(), WorkerStartError> {
        if self.restart_after_fork && !self.permanently_stopped {
            self.start()?;
            self.restart_after_fork = false;
        }
        Ok(())
    }

    fn stop_running(&mut self) {
        if let Some(running) = self.running.take() {
            running.cancellation.cancel();
            running.thread.thread().unpark();
            let _ = running.thread.join();
        }
    }
}

/// Establish an independently owned connection so request/thread teardown does
/// not invalidate the process-wide CDN subscription. Always use subprocess
/// startup: a connection failure must never move HTTP into the PHP process.
fn read_configuration(
    config: &AgentlessWorkerConfig,
    cancellation: &CancellationToken,
) -> anyhow::Result<()> {
    if cancellation.is_cancelled() {
        return Ok(());
    }
    let mut sidecar = datadog_sidecar::config::FromEnv::config();
    sidecar.self_telemetry = config.sidecar_self_telemetry;
    let mut transport = crate::sidecar::run_sidecar(sidecar)?;
    transport.set_read_timeout(Some(Duration::from_millis(200)))?;
    transport.set_write_timeout(Some(Duration::from_millis(200)))?;
    let path = subscribe_cdn_configuration(&mut transport, Some(config.subscription.clone()))?
        .ok_or_else(|| anyhow::anyhow!("CDN subscription unavailable"))?;
    let mut reader = CdnReader::new(&CString::new(path)?);
    let mut revision = 0;
    let mut heartbeat = Instant::now();
    while !cancellation.is_cancelled() {
        if let Some(snapshot) = reader.read()? {
            if snapshot.revision != revision && !snapshot.configuration.is_empty() {
                let config = UniversalFlagConfig::from_json(snapshot.configuration)?;
                store_config(Configuration::from_server_response(config));
                revision = snapshot.revision;
            }
            set_delivery_state(match snapshot.state {
                CdnDeliveryState::Ready if ddog_ffe_has_config() => DeliveryState::Ready,
                CdnDeliveryState::Stale | CdnDeliveryState::Stopped if ddog_ffe_has_config() => {
                    DeliveryState::Stale
                }
                _ => DeliveryState::Starting,
            });
        }
        // Detect a dead sidecar even when the last shared-memory snapshot is
        // unchanged. A fresh connection obtains a fresh subscription and name.
        if heartbeat.elapsed() >= Duration::from_secs(1) {
            ping(&mut transport)?;
            heartbeat = Instant::now();
        }
        reader.wait_for_change(Duration::from_millis(50));
    }
    Ok(())
}

impl Drop for AgentlessWorker {
    fn drop(&mut self) {
        self.stop_running();
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicUsize, Ordering};

    #[test]
    fn worker_cleanup_runs_on_its_thread_after_reader_shutdown() {
        static CLEANUPS: AtomicUsize = AtomicUsize::new(0);
        extern "C" fn cleanup(_: *mut c_void) {
            // Runtime and tracing guards must have exited before TLS is drained.
            assert!(tokio::runtime::Handle::try_current().is_err());
            assert_eq!(std::thread::current().name(), Some("ddtrace-ffe-agentless"));
            CLEANUPS.fetch_add(1, Ordering::SeqCst);
        }
        let endpoint = AgentlessEndpoint::build("http://127.0.0.1:1/config", "", "", "").unwrap();
        let mut config =
            AgentlessWorkerConfig::new(endpoint, Duration::from_secs(30), Duration::from_secs(30));
        config.thread_cleanup = Some(cleanup);
        let mut worker = AgentlessWorker::new(config);
        worker.start().unwrap();
        worker.prepare_for_fork();
        assert_eq!(CLEANUPS.load(Ordering::SeqCst), 1);
        worker.resume_after_fork_parent().unwrap();
        worker.shutdown();
        assert_eq!(CLEANUPS.load(Ordering::SeqCst), 2);
        worker.shutdown();
        assert_eq!(CLEANUPS.load(Ordering::SeqCst), 2);
    }

    #[test]
    fn a_finished_worker_can_restart_but_shutdown_is_permanent() {
        let endpoint = AgentlessEndpoint::build("http://127.0.0.1:1/config", "", "", "").unwrap();
        let mut worker = AgentlessWorker::new(AgentlessWorkerConfig::new(
            endpoint,
            Duration::from_secs(30),
            Duration::from_secs(30),
        ));
        // Model a reader returning unexpectedly. Its handle must not suppress retries.
        let thread = std::thread::spawn(|| {});
        while !thread.is_finished() {
            std::thread::yield_now();
        }
        worker.running = Some(RunningWorker {
            cancellation: CancellationToken::new(),
            thread,
        });
        assert_eq!(worker.start(), Ok(true));
        assert_eq!(worker.start(), Ok(false));
        worker.shutdown();
        assert!(worker.running.is_none());
        assert_eq!(worker.start(), Ok(false));
    }
}
