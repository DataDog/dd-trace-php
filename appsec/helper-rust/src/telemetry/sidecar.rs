use std::cell::Cell;
use std::ffi::c_char;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

use crate::client::log::{debug, info, warning};
use crate::client::protocol::{SidecarSettings, TelemetrySettings};
use crate::ffi::sidecar_ffi::{
    ddog_CharSlice, ddog_Error, ddog_Error_drop, ddog_Error_message, ddog_LogLevel,
    ddog_LogLevel_DDOG_LOG_LEVEL_DEBUG, ddog_LogLevel_DDOG_LOG_LEVEL_ERROR,
    ddog_LogLevel_DDOG_LOG_LEVEL_WARN, ddog_MaybeError, ddog_MetricNamespace,
    ddog_MetricNamespace_DDOG_METRIC_NAMESPACE_APPSEC, ddog_MetricType,
    ddog_Option_Error_Tag_DDOG_OPTION_ERROR_SOME_ERROR, ddog_sidecar_enqueue_telemetry_log,
    ddog_sidecar_enqueue_telemetry_metric, ddog_sidecar_enqueue_telemetry_point,
};
use crate::ffi::SidecarSymbol;
use crate::sidecar_symbol;
use crate::telemetry::{
    KnownMetric, TelemetryLogSubmitter, TelemetryMetricSubmitter, TelemetryTags,
};

use super::{LogLevel, MetricName, TelemetryLog};

type DdogSidecarEnqueueTelemetryLogFn = unsafe extern "C" fn(
    session_id_ffi: ddog_CharSlice,
    runtime_id_ffi: ddog_CharSlice,
    service_name_ffi: ddog_CharSlice,
    env_name_ffi: ddog_CharSlice,
    identifier_ffi: ddog_CharSlice,
    level: ddog_LogLevel,
    message_ffi: ddog_CharSlice,
    stack_trace_ffi: *mut ddog_CharSlice,
    tags_ffi: *mut ddog_CharSlice,
    is_sensitive: bool,
) -> ddog_MaybeError;
type DdogSidecarEnqueueTelemetryPointFn = unsafe extern "C" fn(
    session_id_ffi: ddog_CharSlice,
    runtime_id_ffi: ddog_CharSlice,
    service_name_ffi: ddog_CharSlice,
    env_name_ffi: ddog_CharSlice,
    metric_name_ffi: ddog_CharSlice,
    value: f64,
    tags_ffi: *mut ddog_CharSlice,
) -> ddog_MaybeError;
type DdogSidecarEnqueueTelemetryMetricFn = unsafe extern "C" fn(
    session_id_ffi: ddog_CharSlice,
    runtime_id_ffi: ddog_CharSlice,
    service_name_ffi: ddog_CharSlice,
    env_name_ffi: ddog_CharSlice,
    metric_name_ffi: ddog_CharSlice,
    metric_type: ddog_MetricType,
    metric_namespace: ddog_MetricNamespace,
) -> ddog_MaybeError;
type DdogErrorDropFn = unsafe extern "C" fn(*mut ddog_Error);
type DdogErrorMessageFn = unsafe extern "C" fn(*const ddog_Error) -> ddog_CharSlice;

static RESOLUTION_STATUS: AtomicBool = AtomicBool::new(false);

sidecar_symbol!(
    static ENQUEUE_TELEMETRY_LOG = DdogSidecarEnqueueTelemetryLogFn : ddog_sidecar_enqueue_telemetry_log
);
sidecar_symbol!(
    static ENQUEUE_TELEMETRY_POINT = DdogSidecarEnqueueTelemetryPointFn : ddog_sidecar_enqueue_telemetry_point
);
sidecar_symbol!(
    static ENQUEUE_TELEMETRY_METRIC = DdogSidecarEnqueueTelemetryMetricFn : ddog_sidecar_enqueue_telemetry_metric
);
sidecar_symbol!(
    static ERROR_DROP = DdogErrorDropFn : ddog_Error_drop
);
sidecar_symbol!(
    static ERROR_MESSAGE = DdogErrorMessageFn : ddog_Error_message
);

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
            warning!("Sidecar symbols for telemetry not resolved, skipping log submission");
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
                ERROR_DROP(&mut self.maybe_error.__bindgen_anon_1.__bindgen_anon_1.some);
            }
        }
    }
}
impl From<MaybeErrorRAII> for Option<String> {
    fn from(value: MaybeErrorRAII) -> Self {
        if value.maybe_error.tag == ddog_Option_Error_Tag_DDOG_OPTION_ERROR_SOME_ERROR {
            let msg =
                unsafe { ERROR_MESSAGE(&value.maybe_error.__bindgen_anon_1.__bindgen_anon_1.some) };
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
    fn submit_log(&mut self, log: TelemetryLog) {
        info!(
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
            ENQUEUE_TELEMETRY_LOG(
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
    ENQUEUE_TELEMETRY_LOG.resolve()?;
    ENQUEUE_TELEMETRY_POINT.resolve()?;
    ENQUEUE_TELEMETRY_METRIC.resolve()?;
    ERROR_DROP.resolve()?;
    ERROR_MESSAGE.resolve()?;
    RESOLUTION_STATUS.store(true, Ordering::Release);
    Ok(())
}

pub(super) fn register_metric_ffi(
    sidecar_settings: &SidecarSettings,
    telemetry_settings: &TelemetrySettings,
    metric: &KnownMetric,
) -> anyhow::Result<()> {
    if !RESOLUTION_STATUS.load(Ordering::Acquire) {
        anyhow::bail!("Sidecar symbols not resolved, skipping metric registration")
    }

    let session_id = char_slice_from_str(&sidecar_settings.session_id);
    let runtime_id = char_slice_from_str(&sidecar_settings.runtime_id);
    let service_name = char_slice_from_str(&telemetry_settings.service_name);
    let env_name = char_slice_from_str(&telemetry_settings.env_name);
    let metric_name_slice = char_slice_from_str(metric.name.0);

    let result: ddog_MaybeError = unsafe {
        ENQUEUE_TELEMETRY_METRIC(
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
    fn submit_metric(&mut self, key: MetricName, value: f64, tags: TelemetryTags) {
        info!(
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
            ENQUEUE_TELEMETRY_POINT(
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
