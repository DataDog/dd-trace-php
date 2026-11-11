// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use super::settings::{
    ConfigurationSource, DisableReason, EndpointError, FeatureFlagsSettings, SettingsInput,
    SettingsIssue, SourceInput,
};
use super::{ddog_ffe_has_config, set_delivery_state, DeliveryState};
use crate::log::{self, Log};
use crate::remote_config::{ddog_process_remote_configs, RemoteConfigState};
use datadog_ffe::cdn::CdnConfig;
use datadog_sidecar::shm_remote_config::CdnRegistration;
use libdd_common::Endpoint;
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use libdd_remote_config::Target;
use std::cell::RefCell;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, LazyLock, Mutex, MutexGuard};
use std::time::{Duration, Instant};

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
    pub sidecar_subprocess_enabled: bool,
}

#[derive(Default)]
struct Lifecycle {
    settings: Option<FeatureFlagsSettings>,
    config: FfeRuntimeConfig,
    subscription: Option<CdnConfig>,
    initialization_deadline: Option<Instant>,
    stopped: bool,
    failure: Option<InitializationFailure>,
    diagnostics_reported: bool,
}

#[derive(Clone, Copy)]
enum InitializationFailure {
    InvalidSource,
    Endpoint(EndpointError),
    SidecarMode,
}

impl InitializationFailure {
    fn reason(self) -> &'static str {
        match self {
            Self::InvalidSource => "invalid_source",
            Self::Endpoint(EndpointError::MissingApiKey) => "missing_api_key",
            Self::Endpoint(EndpointError::InvalidSite) => "invalid_site",
            Self::Endpoint(_) => "invalid_endpoint",
            Self::SidecarMode => "unsupported_sidecar_mode",
        }
    }

    fn message(self) -> &'static str {
        match self {
            Self::InvalidSource => "Feature Flags configuration source is invalid; use agentless, remote_config or offline",
            Self::Endpoint(EndpointError::MissingApiKey) => "Feature Flags CDN configuration requires DD_API_KEY for the managed endpoint",
            Self::Endpoint(EndpointError::InvalidSite) => "Feature Flags CDN configuration requires a valid DD_SITE hostname",
            Self::Endpoint(_) => "Feature Flags CDN base URL must be an absolute HTTP or HTTPS URL without whitespace or a fragment",
            Self::SidecarMode => "Feature Flags CDN configuration requires sidecar subprocess mode",
        }
    }
}

impl Lifecycle {
    fn report_diagnostics(&mut self) {
        if self.diagnostics_reported {
            return;
        }
        self.diagnostics_reported = true;
        if let Some(settings) = &self.settings {
            if settings.resolution.legacy_key_decided {
                log::log(Log::Warn, "DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED is deprecated; use DD_FEATURE_FLAGS_ENABLED and DD_FEATURE_FLAGS_CONFIGURATION_SOURCE");
            }
            for issue in &settings.issues {
                let message = match issue {
                    SettingsIssue::PollInterval => "Invalid DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS; using 30 seconds (valid range: 1-3600)",
                    SettingsIssue::RequestTimeout => "Invalid DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS; using 5 seconds (valid range: 1-300)",
                    SettingsIssue::InitializationTimeout => "Invalid DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS; using 10000 milliseconds (valid range: 1-2147483647)",
                };
                log::log(Log::Warn, message);
            }
        }
        if let Some(failure) = self.failure {
            log::log(Log::Warn, failure.message());
        }
    }
}

static LIFECYCLE: LazyLock<Mutex<Lifecycle>> = LazyLock::new(|| Mutex::new(Lifecycle::default()));
static INITIALIZATION_STOPPED: AtomicBool = AtomicBool::new(false);
static CHILD_RESUME_PENDING: AtomicBool = AtomicBool::new(false);

thread_local! {
    // Keep lifecycle operations excluded across PHP's fork boundary. The
    // forking thread releases this guard in both parent and child callbacks.
    static FORK_GUARD: RefCell<Option<MutexGuard<'static, Lifecycle>>> = const { RefCell::new(None) };
}

fn lifecycle() -> MutexGuard<'static, Lifecycle> {
    LIFECYCLE.lock().unwrap_or_else(|error| error.into_inner())
}

/// Configure once during PHP's ordinary process setup, before sidecar startup.
/// This does not start network polling; ordinary sidecar setup registers CDN delivery.
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
    super::use_agentless_config(source == FfeConfigurationSource::Agentless);
    if settings.resolution.disable_reason == Some(DisableReason::InvalidSource) {
        state.failure = Some(InitializationFailure::InvalidSource);
    }
    let mut endpoint_valid = false;
    if settings.resolution.enabled && source == FfeConfigurationSource::Agentless {
        match settings.agentless_endpoint() {
            Ok(endpoint) => {
                endpoint_valid = true;
                if input.sidecar_subprocess_enabled {
                    state.subscription = Some(CdnConfig {
                        endpoint,
                        poll_interval: settings.poll_interval,
                        request_timeout: settings.request_timeout,
                        language: "php".into(),
                        version: include_str!("../../../VERSION").trim().into(),
                    });
                } else {
                    state.failure = Some(InitializationFailure::SidecarMode);
                    set_delivery_state(DeliveryState::PermanentError);
                }
            }
            Err(error) => {
                state.failure = Some(InitializationFailure::Endpoint(error));
                set_delivery_state(DeliveryState::PermanentError);
            }
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

/// Static, redacted configuration failure. Empty when initialization can retry.
#[no_mangle]
pub extern "C" fn ddog_ffe_initialization_error_reason() -> CharSlice<'static> {
    lifecycle()
        .failure
        .map_or("", InitializationFailure::reason)
        .into()
}

#[no_mangle]
pub extern "C" fn ddog_ffe_initialization_error_message() -> CharSlice<'static> {
    lifecycle()
        .failure
        .map_or("", InitializationFailure::message)
        .into()
}

/// CDN scopes the ordinary RC directory while retaining other Agent products.
pub(crate) fn registration(endpoint: &Endpoint) -> Option<CdnRegistration> {
    Some(CdnRegistration {
        config: lifecycle().subscription.clone()?,
        agent_endpoint: endpoint.clone(),
        target: Arc::new(Target::new("".into(), "".into(), "".into(), vec![], vec![])),
    })
}

/// Whether PHP must reset its inherited sidecar runtime before evaluating.
/// Native frameworks may fork without loading the tracing integration hook.
#[no_mangle]
pub extern "C" fn ddog_ffe_needs_child_reset() -> bool {
    CHILD_RESUME_PENDING.load(Ordering::Acquire)
}

/// First-use compatibility path for PHP providers without an initialize API.
/// Concurrent first callers share one deadline; later calls never renew it.
#[no_mangle]
pub extern "C" fn ddog_ffe_ensure_initialized(
    remote_config: Option<&mut RemoteConfigState>,
    subprocess_mode: bool,
) {
    // Native frameworks must finish PHP's normal child hook before using
    // inherited sidecar runtime state. Retained flags stay usable.
    if CHILD_RESUME_PENDING.load(Ordering::Acquire) {
        return;
    }
    let deadline = {
        let mut state = lifecycle();
        state.report_diagnostics();
        if state.stopped || !state.config.enabled {
            return;
        }
        if state.config.source != FfeConfigurationSource::Agentless {
            return;
        }
        if !subprocess_mode {
            if state.failure.is_none() {
                log::log(Log::Warn, InitializationFailure::SidecarMode.message());
            }
            state.failure = Some(InitializationFailure::SidecarMode);
            set_delivery_state(DeliveryState::PermanentError);
            return;
        }
        if state.subscription.is_none() {
            set_delivery_state(DeliveryState::PermanentError);
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
    let Some(remote_config) = remote_config else {
        return;
    };
    loop {
        ddog_process_remote_configs(remote_config);
        let remaining = deadline.saturating_duration_since(Instant::now());
        if ddog_ffe_has_config()
            || INITIALIZATION_STOPPED.load(Ordering::Acquire)
            || remaining.is_zero()
        {
            break;
        }
        remote_config
            .manager
            .wait_for_change(remaining.min(Duration::from_millis(10)));
    }
}

#[no_mangle]
pub extern "C" fn ddog_ffe_shutdown() {
    let mut state = lifecycle();
    state.stopped = true;
    INITIALIZATION_STOPPED.store(true, Ordering::Release);
    state.subscription = None;
    state.settings = None;
    // Static configuration has no automatic destructor at module shutdown.
    // No background publisher remains at module shutdown.
    super::clear_config_inner();
    set_delivery_state(DeliveryState::Stopped);
}

#[no_mangle]
pub extern "C" fn ddog_ffe_prepare_for_fork() {
    FORK_GUARD.with(|slot| {
        let mut slot = slot.borrow_mut();
        if slot.is_none() {
            let state = lifecycle();
            *slot = Some(state);
        }
    });
}

/// The child atfork callback only releases the inherited guard. Normal PHP
/// fork handling resets the sidecar runtime before clearing the pending reset here.
#[no_mangle]
pub extern "C" fn ddog_ffe_atfork_child() {
    let pending = FORK_GUARD.with(|slot| {
        slot.borrow_mut()
            .take()
            .is_some_and(|state| state.subscription.is_some())
    });
    // Apache/FPM can fork before the first request configures FFE. There is
    // then no inherited subscription to reset: ordinary first-use setup must run.
    CHILD_RESUME_PENDING.store(pending, Ordering::Release);
}

#[no_mangle]
pub extern "C" fn ddog_ffe_resume_after_fork(child: bool) {
    FORK_GUARD.with(|slot| {
        let guard = slot.borrow_mut().take();
        // A framework's manager may fork again before entering PHP's normal
        // child hook. Leave the pending reset for the next PHP entry.
        if !child && CHILD_RESUME_PENDING.load(Ordering::Acquire) {
            return;
        }
        let guard = if child && CHILD_RESUME_PENDING.swap(false, Ordering::AcqRel) {
            guard.or_else(|| Some(lifecycle()))
        } else {
            guard
        };
        if let Some(mut state) = guard {
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
    fn initializers_share_the_deadline_after_prefork_startup() {
        let _test_guard = crate::ffe::tests::FFE_TEST_LOCK.lock().unwrap();
        crate::ffe::clear_config();
        // Prefork SAPIs split before first-request setup and have no inherited
        // FFE subscription. First use must still initialize and wait for publication.
        ddog_ffe_prepare_for_fork();
        ddog_ffe_atfork_child();
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
            sidecar_subprocess_enabled: true,
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
                    let mut rc = unsafe {
                        crate::remote_config::ddog_init_remote_config_state(
                            &Endpoint::default(),
                            false,
                        )
                    };
                    ddog_ffe_ensure_initialized(Some(&mut rc), true);
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
        assert!(lifecycle().subscription.is_none());
        assert!(lifecycle().settings.is_none());
        *lifecycle() = Lifecycle::default();
        INITIALIZATION_STOPPED.store(false, Ordering::Release);
        crate::ffe::clear_config();
        crate::ffe::use_agentless_config(false);
    }
}
