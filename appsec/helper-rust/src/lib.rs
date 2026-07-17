#![warn(clippy::extra_unused_lifetimes, clippy::needless_lifetimes)]
#![deny(clippy::disallowed_macros)]

use std::time::{Duration, Instant};
use tokio_util::{sync::CancellationToken, task::TaskTracker};

mod client;
pub mod config;
mod rc;
mod rc_notify;
pub mod server;
mod service;
mod telemetry;

use config::Config;
use datadog_sidecar::service::telemetry::InProcessTelemetryClientFactory;

pub use client::{on_disconnect, on_message, MessageResponse};

pub struct AppSecHelper {
    cancel_token: CancellationToken,
    client_task_tracker: TaskTracker,
}

pub fn start(
    runtime_handle: tokio::runtime::Handle,
    config: Config,
    telemetry: InProcessTelemetryClientFactory,
) -> anyhow::Result<AppSecHelper> {
    log::info!("AppSec helper starting");
    log::info!("Configuration: {:?}", config);

    init_waf_logging(&config);

    if let Err(e) = rc_notify::resolve_symbols() {
        crate::error!(
            "Failed to resolve RC notify symbols: {}; will not get Remote Config updates",
            e
        );
    }

    let cancel_token = CancellationToken::new();
    let client_task_tracker =
        server::accept_appsec_messages(runtime_handle, cancel_token.clone(), telemetry);

    log::info!("AppSec helper started successfully");
    Ok(AppSecHelper {
        cancel_token,
        client_task_tracker,
    })
}

impl AppSecHelper {
    pub async fn shutdown(self) {
        log::info!("AppSec helper shutdown initiated");
        self.cancel_token.cancel();
        log::info!("Cancellation signal sent to all tasks");
        server::stop_accepting_appsec_messages();
        self.client_task_tracker.close();

        let start = Instant::now();
        let grace_timeout = Duration::from_millis(1000);
        if tokio::time::timeout(grace_timeout, self.client_task_tracker.wait())
            .await
            .is_err()
        {
            log::warn!(
                "Could not determine that all tasks completed within grace period of {:?}",
                grace_timeout
            );
        } else {
            log::info!(
                "All client tasks completed gracefully in {:?}",
                start.elapsed()
            );
        }

        log::info!("AppSec helper shutdown complete");
    }
}

fn init_waf_logging(config: &Config) {
    use libddwaf::log::Level as DdwafLogLevel;
    use std::ffi::CStr;

    let min_level = match config.log_level {
        log::Level::Error => DdwafLogLevel::Error,
        log::Level::Warn => DdwafLogLevel::Warn,
        log::Level::Info => DdwafLogLevel::Warn, // intentional
        log::Level::Debug => DdwafLogLevel::Debug,
        log::Level::Trace => DdwafLogLevel::Trace,
    };

    unsafe {
        libddwaf::log::set_log_cb(
            |level: DdwafLogLevel,
             function: &'static CStr,
             file: &'static CStr,
             line: u32,
             message: &[u8]| {
                let msg_str = std::str::from_utf8(message);
                crate::client::log::client_log_gen!(
                    match level {
                        DdwafLogLevel::Error => log::Level::Error,
                        DdwafLogLevel::Warn => log::Level::Warn,
                        DdwafLogLevel::Info => log::Level::Debug, // intentional
                        DdwafLogLevel::Debug => log::Level::Trace, // intentional
                        DdwafLogLevel::Trace => log::Level::Trace,
                        _ => std::unreachable!("Invalid log level"),
                    },
                    "{} in {:?} at {:?}:{:?}",
                    msg_str.unwrap_or("(invalid utf-8 in message)"),
                    function,
                    file,
                    line
                );
            },
            min_level,
        )
    };
}
