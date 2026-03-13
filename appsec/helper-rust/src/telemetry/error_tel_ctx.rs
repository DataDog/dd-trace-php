use std::future::Future;
use std::sync::{Arc, RwLock};

use tokio::task_local;

use crate::client::log::debug;
use crate::client::protocol::{SidecarSettings, TelemetrySettings};

task_local! {
    static ERROR_TELEMETRY_HANDLE: ErrorTelemetryHandle;
}

/// Run a future with an error telemetry handle set in task-local storage.
/// The handle starts empty; call `update_error_telemetry_context()` to populate it.
pub async fn with_error_telemetry_handle<F, R>(fut: F) -> R
where
    F: Future<Output = R>,
{
    ERROR_TELEMETRY_HANDLE
        .scope(ErrorTelemetryHandle::new(), fut)
        .await
}

/// Update the error telemetry context for the current task.
/// Returns true if the update was successful, false if not in a scoped context.
pub fn update_error_telemetry_context(
    sidecar_settings: SidecarSettings,
    telemetry_settings: TelemetrySettings,
) -> bool {
    let ctx = ErrorTelemetryContext {
        sidecar_settings: Arc::new(sidecar_settings),
        telemetry_settings: Arc::new(telemetry_settings),
    };
    ERROR_TELEMETRY_HANDLE
        .try_with(|handle| handle.set(ctx))
        .is_ok()
}

/// Clear the error telemetry context for the current task.
/// Returns true if the clear was successful, false if not in a scoped context.
pub fn clear_error_telemetry_context() -> bool {
    ERROR_TELEMETRY_HANDLE
        .try_with(|handle| handle.clear())
        .is_ok()
}

pub fn get_context_log_submitter() -> impl super::TelemetryLogSubmitter {
    struct ContextTelemetryLogSubmitter {}
    impl super::TelemetryLogSubmitter for ContextTelemetryLogSubmitter {
        fn submit_log(&mut self, log: super::TelemetryLog) {
            let Some(ctx) = get_error_telemetry_context() else {
                debug!(
                    "Cannot submit telemetry log {:?}: no error telemetry context",
                    log
                );
                return;
            };

            super::TelemetrySidecarLogSubmitter::create(
                &ctx.sidecar_settings,
                &ctx.telemetry_settings,
            )
            .submit_log(log);
        }
    }

    ContextTelemetryLogSubmitter {}
}

/// Context for error telemetry submission.
/// Both settings must be present for telemetry to be submitted.
#[derive(Clone)]
pub struct ErrorTelemetryContext {
    pub sidecar_settings: Arc<SidecarSettings>,
    pub telemetry_settings: Arc<TelemetrySettings>,
}

/// Handle to the task-local error telemetry context.
/// This handle is set once at task start via `.scope()`, but the inner contents
/// can be updated at any time (e.g., when settings change via config_sync).
#[derive(Clone)]
pub struct ErrorTelemetryHandle(Arc<RwLock<Option<ErrorTelemetryContext>>>);

impl ErrorTelemetryHandle {
    pub fn new() -> Self {
        Self(Arc::new(RwLock::new(None)))
    }

    pub fn set(&self, ctx: ErrorTelemetryContext) {
        if let Ok(mut guard) = self.0.write() {
            *guard = Some(ctx);
        }
    }

    pub fn clear(&self) {
        if let Ok(mut guard) = self.0.write() {
            *guard = None;
        }
    }

    pub fn get(&self) -> Option<ErrorTelemetryContext> {
        self.0.read().ok().and_then(|guard| guard.clone())
    }
}

impl Default for ErrorTelemetryHandle {
    fn default() -> Self {
        Self::new()
    }
}

/// Get the current error telemetry context for this task, if available.
/// Returns None if called outside of a scoped context or if no context has been set.
fn get_error_telemetry_context() -> Option<ErrorTelemetryContext> {
    ERROR_TELEMETRY_HANDLE
        .try_with(|handle| handle.get())
        .ok()
        .flatten()
}
