use std::cell::Cell;
use std::ffi::c_char;
use std::future::Future;
use std::pin::Pin;
use std::sync::atomic::{AtomicBool, AtomicU8, Ordering};
use std::task::Poll;
use std::time::{Duration, Instant};

use futures::future::Shared;
use futures::task::Context;

use crate::client::log::{debug, info, warning};
use crate::client::protocol::{SidecarSettings, TelemetrySettings};
use crate::ffi::sidecar_ffi::{
    ddog_CharSlice, ddog_Error_drop, ddog_Error_message, ddog_LogLevel,
    ddog_LogLevel_DDOG_LOG_LEVEL_DEBUG, ddog_LogLevel_DDOG_LOG_LEVEL_ERROR,
    ddog_LogLevel_DDOG_LOG_LEVEL_WARN, ddog_MaybeError,
    ddog_MetricNamespace_DDOG_METRIC_NAMESPACE_APPSEC,
    ddog_Option_Error_Tag_DDOG_OPTION_ERROR_SOME_ERROR, ddog_SidecarTransport,
    ddog_sidecar_connect, ddog_sidecar_enqueue_telemetry_log,
    ddog_sidecar_enqueue_telemetry_metric, ddog_sidecar_enqueue_telemetry_point, ddog_sidecar_ping,
    ddog_sidecar_transport_drop,
};
use crate::telemetry::{
    KnownMetric, TelemetryLogSubmitter, TelemetryMetricSubmitter, TelemetryTags,
};

use super::{LogLevel, MetricName, TelemetryLog};

static RESOLUTION_STATUS: AtomicBool = AtomicBool::new(false);

#[repr(u8)]
#[derive(Debug, Copy, Clone, PartialEq, Eq)]
pub enum SidecarStatus {
    Unknown = 0,
    Ready = 1,
    Failed = 2,
}

static SIDECAR_STATUS: AtomicU8 = AtomicU8::new(SidecarStatus::Unknown as u8);

pub struct TelemetrySidecarLogSubmitter<'a> {
    session_id: &'a str,
    runtime_id: &'a str,
    service_name: &'a str,
    env_name: &'a str,
}

impl<'a> TelemetrySidecarLogSubmitter<'a> {
    pub fn create(
        sidecar_settings: &'a SidecarSettings,
        telemetry_settings: &'a TelemetrySettings,
    ) -> Box<dyn TelemetryLogSubmitter + 'a> {
        struct NoopTelemetryLogSubmitter;
        impl TelemetryLogSubmitter for NoopTelemetryLogSubmitter {
            fn submit_log(&mut self, log: TelemetryLog) {
                debug!(
                    "Not submitting telemetry log: sidecar symbols not resolved, log={:?}",
                    log
                );
            }
        }

        if !RESOLUTION_STATUS.load(Ordering::Acquire) {
            info!("Sidecar symbols for telemetry not resolved, skipping log submission");
            return Box::new(NoopTelemetryLogSubmitter);
        }
        if SIDECAR_STATUS.load(Ordering::Relaxed) != SidecarStatus::Ready as u8 {
            info!("Sidecar is not ready, skipping log submission");
            return Box::new(NoopTelemetryLogSubmitter);
        }

        Box::new(Self {
            session_id: &sidecar_settings.session_id,
            runtime_id: &sidecar_settings.runtime_id,
            service_name: &telemetry_settings.service_name,
            env_name: &telemetry_settings.env_name,
        })
    }
}

fn to_ddog_log_level(level: LogLevel) -> ddog_LogLevel {
    match level {
        LogLevel::Error => ddog_LogLevel_DDOG_LOG_LEVEL_ERROR,
        LogLevel::Warn => ddog_LogLevel_DDOG_LOG_LEVEL_WARN,
        LogLevel::Debug => ddog_LogLevel_DDOG_LOG_LEVEL_DEBUG,
    }
}

fn char_slice_from_str(s: &str) -> ddog_CharSlice {
    ddog_CharSlice {
        ptr: s.as_ptr() as *const c_char,
        len: s.len(),
    }
}

struct MaybeErrorRAII {
    maybe_error: ddog_MaybeError,
}
impl Drop for MaybeErrorRAII {
    fn drop(&mut self) {
        unsafe {
            if self.maybe_error.tag == ddog_Option_Error_Tag_DDOG_OPTION_ERROR_SOME_ERROR {
                ddog_Error_drop(&mut self.maybe_error.__bindgen_anon_1.__bindgen_anon_1.some);
            }
        }
    }
}
impl From<MaybeErrorRAII> for Option<String> {
    fn from(value: MaybeErrorRAII) -> Self {
        if value.maybe_error.tag == ddog_Option_Error_Tag_DDOG_OPTION_ERROR_SOME_ERROR {
            let msg =
                unsafe { ddog_Error_message(&value.maybe_error.__bindgen_anon_1.__bindgen_anon_1.some) };
            if msg.ptr.is_null() || msg.len == 0 {
                return Some(String::new());
            }

            let msg = unsafe { std::slice::from_raw_parts(msg.ptr as *const u8, msg.len) };
            Some(String::from_utf8_lossy(msg).into_owned())
        } else {
            None
        }
    }
}
impl From<ddog_MaybeError> for MaybeErrorRAII {
    fn from(maybe_error: ddog_MaybeError) -> Self {
        Self { maybe_error }
    }
}

impl TelemetryLogSubmitter for TelemetrySidecarLogSubmitter<'_> {
    fn submit_log(&mut self, mut log: TelemetryLog) {
        let mut tags = log.tags.take().unwrap_or_default();
        tags.add("helper_runtime", "rust");
        log.tags = Some(tags);

        debug!(
            "Submitting telemetry log to sidecar: identifier={}, level={:?} (raw={}), message={}",
            log.identifier, log.level, log.level as u8, log.message
        );

        let session_id = char_slice_from_str(self.session_id);
        let runtime_id = char_slice_from_str(self.runtime_id);
        let service_name = char_slice_from_str(self.service_name);
        let env_name = char_slice_from_str(self.env_name);
        let identifier = char_slice_from_str(&log.identifier);
        let message = char_slice_from_str(&log.message);

        let tags_string = log.tags.map(|t| t.into_string());
        let tags_slice = tags_string.as_ref().map(|t| char_slice_from_str(t));

        let stack_trace_slice = log.stack_trace.as_ref().map(|st| char_slice_from_str(st));

        let result: ddog_MaybeError = unsafe {
            ddog_sidecar_enqueue_telemetry_log(
                session_id,
                runtime_id,
                service_name,
                env_name,
                identifier,
                to_ddog_log_level(log.level),
                message,
                stack_trace_slice
                    .as_ref()
                    .map_or(std::ptr::null_mut(), |s| s as *const _ as *mut _),
                tags_slice
                    .as_ref()
                    .map_or(std::ptr::null_mut(), |t| t as *const _ as *mut _),
                log.is_sensitive,
            )
        };
        let result: MaybeErrorRAII = result.into();

        if let Some(error_msg) = Into::<Option<String>>::into(result) {
            info!("Failed to enqueue telemetry log, error: {}", error_msg);
        }
    }
}

pub fn resolve_symbols() -> anyhow::Result<()> {
    RESOLUTION_STATUS.store(true, Ordering::Release);
    Ok(())
}

pub struct SidecarReadyFuture {
    attempt: u32,
    sleep: Option<Pin<Box<tokio::time::Sleep>>>,
}

impl SidecarReadyFuture {
    const MAX_ATTEMPTS: u32 = 50;
    const SLEEP_DURATION: Duration = Duration::from_millis(100);

    pub fn create() -> Shared<Self> {
        let future = Self {
            attempt: 0,
            sleep: None,
        };
        futures::FutureExt::shared(future)
    }

    fn try_connect_and_ping(&self) -> bool {
        let mut transport: *mut ddog_SidecarTransport = std::ptr::null_mut();

        let mut connect_result = unsafe { ddog_sidecar_connect(&mut transport as *mut _) };

        if connect_result.tag == ddog_Option_Error_Tag_DDOG_OPTION_ERROR_SOME_ERROR {
            unsafe { ddog_Error_drop(&mut connect_result.__bindgen_anon_1.__bindgen_anon_1.some) };
            return false;
        }

        let ping_result = unsafe { ddog_sidecar_ping(&mut transport as *mut _) };
        unsafe { ddog_sidecar_transport_drop(transport) };

        ping_result.tag != ddog_Option_Error_Tag_DDOG_OPTION_ERROR_SOME_ERROR
    }
}

impl Future for SidecarReadyFuture {
    type Output = SidecarStatus;

    fn poll(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<Self::Output> {
        loop {
            if let Some(ref mut sleep) = self.sleep {
                match sleep.as_mut().poll(cx) {
                    Poll::Pending => return Poll::Pending,
                    Poll::Ready(()) => {
                        self.sleep = None;
                    }
                }
            }

            if self.attempt >= Self::MAX_ATTEMPTS {
                warning!(
                    "Sidecar did not become ready after {} attempts, not trying again",
                    Self::MAX_ATTEMPTS
                );
                SIDECAR_STATUS.store(SidecarStatus::Failed as u8, Ordering::Release);
                return Poll::Ready(SidecarStatus::Failed);
            }

            self.attempt += 1;

            if self.try_connect_and_ping() {
                info!("Sidecar is ready after {} attempts", self.attempt);
                SIDECAR_STATUS.store(SidecarStatus::Ready as u8, Ordering::Release);
                return Poll::Ready(SidecarStatus::Ready);
            }

            debug!(
                "Sidecar not ready yet (attempt {}), waiting...",
                self.attempt
            );
            self.sleep = Some(Box::pin(tokio::time::sleep(Self::SLEEP_DURATION)));
        }
    }
}

pub(super) fn register_metric_ffi(
    sidecar_settings: &SidecarSettings,
    telemetry_settings: &TelemetrySettings,
    metric: &KnownMetric,
) -> anyhow::Result<()> {
    if !RESOLUTION_STATUS.load(Ordering::Acquire) {
        anyhow::bail!("Sidecar symbols not resolved, skipping metric registration")
    }

    if SIDECAR_STATUS.load(Ordering::Relaxed) != SidecarStatus::Ready as u8 {
        anyhow::bail!("Sidecar is not ready, skipping metric registration");
    }

    let session_id = char_slice_from_str(&sidecar_settings.session_id);
    let runtime_id = char_slice_from_str(&sidecar_settings.runtime_id);
    let service_name = char_slice_from_str(&telemetry_settings.service_name);
    let env_name = char_slice_from_str(&telemetry_settings.env_name);
    let metric_name_slice = char_slice_from_str(metric.name.0);

    let result: ddog_MaybeError = unsafe {
        ddog_sidecar_enqueue_telemetry_metric(
            session_id,
            runtime_id,
            service_name,
            env_name,
            metric_name_slice,
            metric.metric_type,
            ddog_MetricNamespace_DDOG_METRIC_NAMESPACE_APPSEC,
        )
    };
    let result: MaybeErrorRAII = result.into();

    if let Some(error_msg) = Into::<Option<String>>::into(result) {
        anyhow::bail!(
            "Failed to register metric {}, error: {}",
            metric.name.0,
            error_msg
        );
    }
    Ok(())
}

pub struct TelemetrySidecarMetricSubmitter<'a> {
    session_id: &'a str,
    runtime_id: &'a str,
    service_name: &'a str,
    env_name: &'a str,
}

impl<'a> TelemetrySidecarMetricSubmitter<'a> {
    pub fn create<'b>(
        sidecar_settings: &'a SidecarSettings,
        telemetry_settings: &'a TelemetrySettings,
        last_registration_time: &'b Cell<Option<Instant>>,
    ) -> Box<dyn TelemetryMetricSubmitter + 'a> {
        if !RESOLUTION_STATUS.load(Ordering::Acquire) {
            warning!("Sidecar symbols for telemetry not resolved, skipping metric submission");
            return Self::noop();
        }

        // Telemetry client is deleted after 30 mins with no activity. So we may need
        // to refresh at least every 30 mins
        const METRICS_REGISTRATION_REFRESH: Duration = Duration::from_secs(25 * 60);

        let needs_registration = last_registration_time
            .get()
            .is_none_or(|i| i.elapsed() < METRICS_REGISTRATION_REFRESH);
        if needs_registration {
            if let Err(err) = super::register_known_metrics(sidecar_settings, telemetry_settings) {
                warning!("Failed to register known metrics: {}", err);
                return Self::noop();
            }
            last_registration_time.set(Some(Instant::now()));
        }

        Box::new(Self {
            session_id: &sidecar_settings.session_id,
            runtime_id: &sidecar_settings.runtime_id,
            service_name: &telemetry_settings.service_name,
            env_name: &telemetry_settings.env_name,
        })
    }

    pub fn noop() -> Box<dyn TelemetryMetricSubmitter + 'static> {
        struct NoopTelemetryMetricSubmitter;
        impl TelemetryMetricSubmitter for NoopTelemetryMetricSubmitter {
            fn submit_metric(&mut self, key: MetricName, _value: f64, _tags: TelemetryTags) {
                debug!(
                    "Not submitting telemetry metric: key={} (see earlier warning)",
                    key.0
                );
            }
        }

        Box::new(NoopTelemetryMetricSubmitter)
    }
}

impl TelemetryMetricSubmitter for TelemetrySidecarMetricSubmitter<'_> {
    fn submit_metric(&mut self, key: MetricName, value: f64, mut tags: TelemetryTags) {
        tags.add("helper_runtime", "rust");

        debug!(
            "Submitting telemetry metric to sidecar: metric={}, value={}, tags={}",
            key.0,
            value,
            tags.clone().into_string()
        );

        let session_id = char_slice_from_str(self.session_id);
        let runtime_id = char_slice_from_str(self.runtime_id);
        let service_name = char_slice_from_str(self.service_name);
        let env_name = char_slice_from_str(self.env_name);
        let metric_name = char_slice_from_str(key.0);

        let tags_string = tags.into_string();
        let tags_slice = char_slice_from_str(&tags_string);

        let result: ddog_MaybeError = unsafe {
            ddog_sidecar_enqueue_telemetry_point(
                session_id,
                runtime_id,
                service_name,
                env_name,
                metric_name,
                value,
                &tags_slice as *const _ as *mut _,
            )
        };
        let result: MaybeErrorRAII = result.into();

        if let Some(error_msg) = Into::<Option<String>>::into(result) {
            info!("Failed to enqueue telemetry metric, error: {}", error_msg);
        }
    }
}
