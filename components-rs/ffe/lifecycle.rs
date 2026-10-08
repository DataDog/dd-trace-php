// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use super::agentless::{AgentlessWorker, AgentlessWorkerConfig};
use super::settings::{ConfigurationSource, FeatureFlagsSettings, SettingsInput, SourceInput};
use super::{ddog_ffe_has_config, set_delivery_state, DeliveryState};
use crate::log::{self, Log};
use arc_swap::ArcSwap;
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use std::cell::RefCell;
use std::ffi::c_void;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Condvar, LazyLock, Mutex, MutexGuard};
use std::time::Instant;

#[repr(C)]
#[derive(Clone, Copy, Debug, Default, Eq, PartialEq)]
pub enum FfeConfigurationSource {
    #[default]
    Disabled,
    RemoteConfig,
    Agentless,
}

#[repr(C)]
#[derive(Clone, Copy, Default)]
pub struct FfeRuntimeConfig {
    pub source: FfeConfigurationSource,
    pub enabled: bool,
    pub endpoint_valid: bool,
}

/// Borrowed process settings. Strings are copied; credentials are never logged.
/// The optional cleanup callback must remain valid until shutdown joins workers.
#[repr(C)]
pub struct FfeSettingsInput<'a> {
    pub enabled: bool,
    pub enabled_set: bool,
    pub source: CharSlice<'a>,
    pub source_set: bool,
    pub legacy_enabled: bool,
    pub legacy_enabled_set: bool,
    pub agentless_base_url: CharSlice<'a>,
    pub poll_interval_seconds: i64,
    pub request_timeout_seconds: i64,
    pub initialization_timeout_ms: i64,
    pub site: CharSlice<'a>,
    pub api_key: CharSlice<'a>,
    pub environment: CharSlice<'a>,
    pub thread_cleanup: Option<extern "C" fn(*mut c_void)>,
}

#[derive(Default)]
struct Lifecycle {
    settings: Option<FeatureFlagsSettings>,
    config: FfeRuntimeConfig,
    worker: Option<AgentlessWorker>,
    initialization_deadline: Option<Instant>,
    stopped: bool,
}

static LIFECYCLE: LazyLock<Mutex<Lifecycle>> = LazyLock::new(|| Mutex::new(Lifecycle::default()));
type ConfigurationSignal = (Mutex<()>, Condvar);
static CONFIGURATION_CHANGED: LazyLock<ArcSwap<ConfigurationSignal>> =
    LazyLock::new(|| ArcSwap::from_pointee((Mutex::new(()), Condvar::new())));
static INITIALIZATION_STOPPED: AtomicBool = AtomicBool::new(false);

thread_local! {
    // Keep lifecycle operations excluded across PHP's fork boundary. The
    // forking thread releases this guard in both parent and child callbacks.
    static FORK_GUARD: RefCell<Option<MutexGuard<'static, Lifecycle>>> = const { RefCell::new(None) };
}

fn lifecycle() -> MutexGuard<'static, Lifecycle> {
    LIFECYCLE.lock().unwrap_or_else(|error| error.into_inner())
}

pub(super) fn notify_configuration() {
    let signal = CONFIGURATION_CHANGED.load();
    let _guard = signal.0.lock().unwrap_or_else(|error| error.into_inner());
    signal.1.notify_all();
}

/// Configure once during PHP's ordinary process setup, before sidecar startup.
/// This does not start network polling; the first evaluation activates it.
#[no_mangle]
pub extern "C" fn ddog_ffe_configure(input: &FfeSettingsInput<'_>) -> FfeRuntimeConfig {
    let mut state = lifecycle();
    if state.settings.is_some() || state.stopped {
        return state.config;
    }
    let settings = FeatureFlagsSettings::resolve(SettingsInput {
        source: SourceInput {
            enabled: input.enabled,
            enabled_set: input.enabled_set,
            source: input.source.try_to_utf8().unwrap_or("invalid"),
            source_set: input.source_set,
            legacy_enabled: input.legacy_enabled,
            legacy_enabled_set: input.legacy_enabled_set,
        },
        agentless_base_url: input.agentless_base_url.try_to_utf8().unwrap_or("invalid"),
        poll_interval_seconds: input.poll_interval_seconds,
        request_timeout_seconds: input.request_timeout_seconds,
        initialization_timeout_ms: input.initialization_timeout_ms,
        site: input.site.try_to_utf8().unwrap_or("invalid"),
        api_key: input.api_key.try_to_utf8().unwrap_or(""),
        environment: input.environment.try_to_utf8().unwrap_or(""),
    });
    let source = match settings.resolution.source {
        ConfigurationSource::RemoteConfig => FfeConfigurationSource::RemoteConfig,
        ConfigurationSource::Agentless => FfeConfigurationSource::Agentless,
        ConfigurationSource::Offline | ConfigurationSource::Invalid => {
            FfeConfigurationSource::Disabled
        }
    };
    let mut endpoint_valid = false;
    if settings.resolution.enabled && source == FfeConfigurationSource::Agentless {
        if let Ok(endpoint) = settings.agentless_endpoint() {
            endpoint_valid = true;
            let mut worker_config = AgentlessWorkerConfig::new(
                endpoint,
                settings.poll_interval,
                settings.request_timeout,
            );
            worker_config.thread_cleanup = input.thread_cleanup;
            state.worker = Some(AgentlessWorker::new(worker_config));
        }
    }
    state.config = FfeRuntimeConfig {
        source,
        enabled: settings.resolution.enabled,
        endpoint_valid,
    };
    state.settings = Some(settings);
    state.config
}

#[no_mangle]
pub extern "C" fn ddog_ffe_runtime_config() -> FfeRuntimeConfig {
    lifecycle().config
}

/// First-use compatibility path for PHP providers without an initialize API.
/// Concurrent first callers share one deadline; later calls never renew it.
#[no_mangle]
pub extern "C" fn ddog_ffe_ensure_initialized() {
    let deadline = {
        let mut state = lifecycle();
        if state.stopped || !state.config.enabled {
            return;
        }
        if state.config.source != FfeConfigurationSource::Agentless {
            return;
        }
        if state.worker.is_none() {
            set_delivery_state(DeliveryState::PermanentError);
            return;
        }
        if state.worker.as_mut().unwrap().start().is_err() {
            log::log(
                Log::Warn,
                "Feature Flags agentless worker could not be started",
            );
            return;
        }
        if state.initialization_deadline.is_none() {
            if !ddog_ffe_has_config() {
                set_delivery_state(DeliveryState::Starting);
            }
            state.initialization_deadline =
                Some(Instant::now() + state.settings.as_ref().unwrap().initialization_timeout);
        }
        state.initialization_deadline.unwrap()
    };
    let signal = CONFIGURATION_CHANGED.load_full();
    let mut guard = signal.0.lock().unwrap_or_else(|error| error.into_inner());
    while !ddog_ffe_has_config() && !INITIALIZATION_STOPPED.load(Ordering::Acquire) {
        let remaining = deadline.saturating_duration_since(Instant::now());
        if remaining.is_zero() {
            break;
        }
        guard = signal
            .1
            .wait_timeout(guard, remaining)
            .unwrap_or_else(|error| error.into_inner())
            .0;
    }
}

#[no_mangle]
pub extern "C" fn ddog_ffe_shutdown() {
    let mut state = lifecycle();
    state.stopped = true;
    INITIALIZATION_STOPPED.store(true, Ordering::Release);
    if let Some(mut worker) = state.worker.take() {
        worker.shutdown();
    }
    state.settings = None;
    // Static configuration has no automatic destructor at module shutdown.
    // Release its owned evaluator data only after the publisher has joined.
    super::clear_config();
    set_delivery_state(DeliveryState::Stopped);
    notify_configuration();
}

#[no_mangle]
pub extern "C" fn ddog_ffe_prepare_for_fork() {
    FORK_GUARD.with(|slot| {
        let mut slot = slot.borrow_mut();
        if slot.is_none() {
            let mut state = lifecycle();
            if let Some(worker) = &mut state.worker {
                worker.prepare_for_fork();
            }
            *slot = Some(state);
        }
    });
}

#[no_mangle]
pub extern "C" fn ddog_ffe_resume_after_fork(child: bool) {
    FORK_GUARD.with(|slot| {
        if let Some(mut state) = slot.borrow_mut().take() {
            if child {
                // Other parent threads may have been waiting on the old
                // condition variable. Never reuse that synchronization state.
                CONFIGURATION_CHANGED.store(Arc::new((Mutex::new(()), Condvar::new())));
            }
            if let Some(worker) = &mut state.worker {
                let result = if child {
                    worker.reset_after_fork_child()
                } else {
                    worker.resume_after_fork_parent()
                };
                if result.is_err() {
                    log::log(
                        Log::Warn,
                        "Feature Flags agentless worker could not restart after fork",
                    );
                }
            }
            if child && !ddog_ffe_has_config() {
                state.initialization_deadline = None;
            }
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::{mpsc, Barrier};
    use std::time::Duration;

    #[test]
    fn publishing_configuration_wakes_concurrent_initializers() {
        let _test_guard = crate::ffe::tests::FFE_TEST_LOCK.lock().unwrap();
        crate::ffe::clear_config();
        let input = FfeSettingsInput {
            enabled: true,
            enabled_set: true,
            source: CharSlice::from("agentless"),
            source_set: true,
            legacy_enabled: false,
            legacy_enabled_set: false,
            agentless_base_url: CharSlice::from("http://127.0.0.1:1/config"),
            poll_interval_seconds: 30,
            request_timeout_seconds: 30,
            initialization_timeout_ms: 3000,
            site: CharSlice::from(""),
            api_key: CharSlice::from(""),
            environment: CharSlice::from(""),
            thread_cleanup: None,
        };
        assert!(ddog_ffe_configure(&input).endpoint_valid);
        let barrier = Arc::new(Barrier::new(5));
        let (tx, rx) = mpsc::channel();
        let callers: Vec<_> = (0..4)
            .map(|_| {
                let barrier = Arc::clone(&barrier);
                let tx = tx.clone();
                std::thread::spawn(move || {
                    barrier.wait();
                    ddog_ffe_ensure_initialized();
                    tx.send(()).unwrap();
                })
            })
            .collect();
        barrier.wait();
        // No caller may finish without a config while its deadline is open.
        assert!(rx.recv_timeout(Duration::from_millis(100)).is_err());
        assert!(crate::ffe::ddog_ffe_load_config(CharSlice::from(
            r#"{"createdAt":"2026-05-22T00:00:00Z","environment":{"name":"test"},"flags":{}}"#,
        )));
        for _ in 0..4 {
            rx.recv_timeout(Duration::from_secs(1)).unwrap();
        }
        for caller in callers {
            caller.join().unwrap();
        }
        ddog_ffe_shutdown();
        assert_eq!(crate::ffe::delivery_state(), DeliveryState::Stopped);
        assert!(!ddog_ffe_has_config());
        assert!(lifecycle().worker.is_none());
        assert!(lifecycle().settings.is_none());
        *lifecycle() = Lifecycle::default();
        INITIALIZATION_STOPPED.store(false, Ordering::Release);
        crate::ffe::clear_config();
    }
}
