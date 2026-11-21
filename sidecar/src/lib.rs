// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

// This crate only exists to register the appsec backend around libdatadog's sidecar daemon
// entrypoint, which is a unix-only concern. On Windows the extension uses
// `datadog_sidecar::start_or_connect_to_sidecar` (i.e. `ddog_daemon_entry_point`) directly, so the
// whole crate is compiled away there.
#![cfg(unix)]

use datadog_sidecar::appsec::protocol::{AppSecRequest, AppSecSessionConfig, AppSecVerdict};
use datadog_sidecar::appsec::{AppSecBackend, AppSecCallbacks, AppSecConnection, AppSecFuture};
use datadog_sidecar::config::Config;
use datadog_sidecar::service::blocking::SidecarTransport;
use spawn_worker::{entrypoint, TrampolineData};
use std::ffi::OsString;
use std::os::unix::ffi::OsStringExt;

pub fn start_or_connect_to_sidecar(config: Config) -> anyhow::Result<SidecarTransport> {
    datadog_sidecar::start_or_connect_to_sidecar_with_entrypoint(
        config,
        entrypoint!(ddtrace_sidecar_entry_point),
    )
}

/// Registers the AppSec backend linked into the PHP extension.
///
/// Registration is process-local and idempotent. Thread-mode listeners need
/// this to run in the master process before their listener thread starts;
/// subprocess-mode sidecars call it again from their entrypoint.
pub fn register_appsec_backend() {
    datadog_sidecar::appsec::register_backend_factory(create_backend);
}

/// Discard the parent's sidecar state before reconnecting.
///
/// # Safety
/// Call in the child after fork, before starting threads. Inherited sidecar tasks
/// and references to their state must not be used afterward.
pub unsafe fn after_fork() -> std::io::Result<()> {
    unsafe { ddappsec_helper::after_fork() };
    unsafe { datadog_sidecar::setup::MasterListener::clear_inherited_state() }
}

#[no_mangle]
pub extern "C" fn ddtrace_sidecar_entry_point(trampoline_data: &TrampolineData) {
    #[cfg(feature = "helper-rust-coverage")]
    ddappsec_helper::initialize_coverage();

    register_appsec_backend();
    datadog_sidecar::ddog_daemon_entry_point(trampoline_data);
}

fn create_backend(
    config: &AppSecSessionConfig,
    telemetry: datadog_sidecar::service::telemetry::InProcessTelemetryClientFactory,
) -> anyhow::Result<AppSecBackend> {
    let log_file = (!config.helper_log_file.is_empty())
        .then(|| OsString::from_vec(config.helper_log_file.clone()).into());
    let helper_config = ddappsec_helper::config::Config::new(log_file, &config.helper_log_level);
    let helper =
        ddappsec_helper::start(tokio::runtime::Handle::current(), helper_config, telemetry)?;
    Ok(AppSecBackend::new(
        AppSecCallbacks {
            on_session_config: ddappsec_helper::on_session_config,
            on_application: ddappsec_helper::on_application,
            on_request,
            on_disconnect: ddappsec_helper::on_disconnect,
        },
        Box::pin(async move { helper.shutdown().await }),
    ))
}

fn on_request(
    connection: &mut AppSecConnection,
    request: AppSecRequest,
) -> AppSecFuture<'_, AppSecVerdict> {
    Box::pin(ddappsec_helper::on_request(connection, request))
}
