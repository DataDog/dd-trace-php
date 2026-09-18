// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

mod poller;
mod transport;

use self::poller::{GlobalConfigurationSink, PollState, Poller};
use self::transport::HyperTransport;
use super::settings::AgentlessEndpoint;
use crate::log::{self, Log};
use std::sync::Arc;
use std::thread::JoinHandle;
use std::time::Duration;
use tokio_util::sync::CancellationToken;

#[derive(Clone)]
pub(crate) struct AgentlessWorkerConfig {
    endpoint: AgentlessEndpoint,
    poll_interval: Duration,
    request_timeout: Duration,
}

impl AgentlessWorkerConfig {
    pub(crate) fn new(
        endpoint: AgentlessEndpoint,
        poll_interval: Duration,
        request_timeout: Duration,
    ) -> Self {
        Self {
            endpoint,
            poll_interval,
            request_timeout,
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

/// Owns the poll thread and makes its lifecycle explicit around `fork`.
/// PR 3 connects these methods to the tracer lifecycle hooks.
pub(crate) struct AgentlessWorker {
    config: AgentlessWorkerConfig,
    state: Arc<PollState>,
    running: Option<RunningWorker>,
    restart_after_fork: bool,
    permanently_stopped: bool,
}

impl AgentlessWorker {
    pub(crate) fn new(config: AgentlessWorkerConfig) -> Self {
        Self {
            config,
            state: Arc::new(PollState::default()),
            running: None,
            restart_after_fork: false,
            permanently_stopped: false,
        }
    }

    pub(crate) fn start(&mut self) -> Result<bool, WorkerStartError> {
        if self.running.is_some() || self.permanently_stopped {
            return Ok(false);
        }

        let cancellation = CancellationToken::new();
        let thread_cancellation = cancellation.clone();
        let config = self.config.clone();
        let state = Arc::clone(&self.state);
        let log_dispatch = tracing::dispatcher::get_default(Clone::clone);
        let thread = std::thread::Builder::new()
            .name("ddtrace-ffe-agentless".to_owned())
            .spawn(move || {
                let _log_guard = tracing::dispatcher::set_default(&log_dispatch);
                let runtime = match tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                {
                    Ok(runtime) => runtime,
                    Err(_) => {
                        log::log(
                            Log::Warn,
                            "Feature Flags agentless runtime could not be created",
                        );
                        return;
                    }
                };

                runtime.block_on(async move {
                    let mut poller = Poller::new(
                        config.endpoint,
                        config.poll_interval,
                        config.request_timeout,
                        HyperTransport::new(),
                        GlobalConfigurationSink,
                        state,
                    );
                    poller.run(thread_cancellation).await;
                });
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
        self.state.reset_warnings();
        self.restart_after_fork()
    }

    pub(crate) fn poll_count(&self) -> u64 {
        self.state.poll_count()
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
            let _ = running.thread.join();
        }
    }
}

impl Drop for AgentlessWorker {
    fn drop(&mut self) {
        self.stop_running();
    }
}
