// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

// This crate only exists to register the appsec backend around libdatadog's sidecar daemon
// entrypoint, which is a unix-only concern. On Windows the extension uses
// `datadog_sidecar::start_or_connect_to_sidecar` (i.e. `ddog_daemon_entry_point`) directly, so the
// whole crate is compiled away there.
#![cfg(unix)]

use datadog_sidecar::appsec::{AppSecBackend, AppSecFuture, AppSecMessageResponse};
use datadog_sidecar::config::AppSecConfig;
use datadog_sidecar::config::Config;
use datadog_sidecar::service::blocking::SidecarTransport;
use spawn_worker::{entrypoint, TrampolineData};

pub fn start_or_connect_to_sidecar(config: Config) -> anyhow::Result<SidecarTransport> {
    datadog_sidecar::start_or_connect_to_sidecar_with_entrypoint(
        config,
        entrypoint!(ddtrace_sidecar_entry_point),
    )
}

#[no_mangle]
pub extern "C" fn ddtrace_sidecar_entry_point(trampoline_data: &TrampolineData) {
    datadog_sidecar::appsec::register_backend_factory(create_backend);
    datadog_sidecar::ddog_daemon_entry_point(trampoline_data);
}

fn create_backend(
    config: &AppSecConfig,
    telemetry: datadog_sidecar::service::telemetry::InProcessTelemetryClientFactory,
) -> anyhow::Result<AppSecBackend> {
    let helper_config = ddappsec_helper::config::Config::new(
        Some(config.log_file_path.clone().into()),
        &config.log_level,
    );
    let helper =
        ddappsec_helper::start(tokio::runtime::Handle::current(), helper_config, telemetry)?;
    Ok(AppSecBackend::new(
        send_message,
        disconnect,
        Box::pin(async move { helper.shutdown().await }),
    ))
}

fn send_message<'a>(
    session_id: &'a str,
    client_id: u64,
    data: Vec<u8>,
) -> AppSecFuture<'a, AppSecMessageResponse> {
    Box::pin(async move {
        let response = ddappsec_helper::on_message(session_id.as_bytes(), client_id, data).await;
        AppSecMessageResponse {
            client_id: response.client_id,
            data: response.data,
            disconnect: response.disconnect,
        }
    })
}

fn disconnect(session_id: &str, client_id: u64) {
    ddappsec_helper::on_disconnect(session_id.as_bytes(), client_id);
}
