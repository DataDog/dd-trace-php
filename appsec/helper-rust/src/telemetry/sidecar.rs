use std::ffi::c_char;
use std::sync::atomic::{AtomicBool, Ordering};

use crate::client::log::info;
use crate::client::protocol::{SidecarSettings, TelemetrySettings};
use crate::ffi::sidecar_ffi::{
    ddog_CharSlice, ddog_Error, ddog_Error_drop, ddog_Error_message, ddog_LogLevel,
    ddog_LogLevel_DDOG_LOG_LEVEL_DEBUG, ddog_LogLevel_DDOG_LOG_LEVEL_ERROR,
    ddog_LogLevel_DDOG_LOG_LEVEL_WARN, ddog_MaybeError,
    ddog_Option_Error_Tag_DDOG_OPTION_ERROR_SOME_ERROR, ddog_sidecar_enqueue_telemetry_log,
};
use crate::ffi::SidecarSymbol;
use crate::sidecar_symbol;
use crate::telemetry::TelemetryLogSubmitter;

use super::{LogLevel, TelemetryLog};

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
type DdogErrorDropFn = unsafe extern "C" fn(*mut ddog_Error);
type DdogErrorMessageFn = unsafe extern "C" fn(*const ddog_Error) -> ddog_CharSlice;

static RESOLUTION_STATUS: AtomicBool = AtomicBool::new(false);

sidecar_symbol!(
    static ENQUEUE_TELEMETRY_LOG = DdogSidecarEnqueueTelemetryLogFn : ddog_sidecar_enqueue_telemetry_log
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
    pub fn new(
        sidecar_settings: &'a SidecarSettings,
        telemetry_settings: &'a TelemetrySettings,
    ) -> anyhow::Result<Self> {
        if !RESOLUTION_STATUS.load(Ordering::Acquire) {
            anyhow::bail!("Sidecar symbols for telemetry not resolved");
        }

        Ok(Self {
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
    ERROR_DROP.resolve()?;
    ERROR_MESSAGE.resolve()?;
    RESOLUTION_STATUS.store(true, Ordering::Release);
    Ok(())
}
