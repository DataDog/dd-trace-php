use datadog_sidecar::interface::blocking::SidecarTransport;
use datadog_sidecar::interface::{blocking, InstanceId, QueueId};
use ddcommon_ffi::slice::AsBytes;
use ddcommon_ffi::CharSlice;
use ddtelemetry::data;
use ddtelemetry::data::{Dependency, Integration};
use ddtelemetry::worker::TelemetryActions;
use ddtelemetry_ffi::{try_c, MaybeError};
use std::error::Error;
use std::path::Path;
use std::fs;
use serde::Deserialize;
use serde_with::{serde_as, VecSkipError};

#[cfg(windows)]
macro_rules! windowsify_path {
    ($lit:literal) => (const_str::replace!($lit, "/", "\\"))
}
#[cfg(unix)]
macro_rules! windowsify_path {
    ($lit:literal) => ($lit)
}

#[must_use]
#[no_mangle]
pub extern "C" fn ddtrace_detect_composer_installed_json(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    path: CharSlice,
) -> bool {
    let pathstr = path.to_utf8_lossy();
    if let Some(index) = pathstr.rfind(windowsify_path!("/vendor/autoload.php")) {
        let path = format!("{}{}", &pathstr[..index], windowsify_path!("/vendor/composer/installed.json"));
        if parse_composer_installed_json(transport, instance_id, queue_id, path).is_ok() {
            return true;
        }
    }
    false
}

#[serde_as]
#[derive(Deserialize)]
struct ComposerPackages {
    #[serde_as(as = "VecSkipError<_>")]
    packages: Vec<Dependency>,
}

fn parse_composer_installed_json(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    path: String,
) -> Result<(), Box<dyn Error>> {
    let mut json = fs::read(Path::new(path.as_str()))?;
    let parsed: ComposerPackages = simd_json::from_slice(json.as_mut_slice())?;

    let mut deps = Vec::new();

    for dep in parsed.packages.into_iter() {
        deps.push(TelemetryActions::AddDependecy(dep));
    }

    if !deps.is_empty() {
        blocking::enqueue_actions(transport, instance_id, queue_id, deps)?;
    }

    Ok(())
}

#[derive(Default)]
pub struct TelemetryActionsBuffer {
    buffer: Vec<TelemetryActions>,
}

#[no_mangle]
pub extern "C" fn ddog_sidecar_telemetry_buffer_alloc() -> Box<TelemetryActionsBuffer> {
    TelemetryActionsBuffer::default().into()
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_addIntegration_buffer(
    buffer: &mut TelemetryActionsBuffer,
    integration_name: CharSlice,
    integration_version: CharSlice,
    integration_enabled: bool,
) {
    let version = integration_version
        .is_empty()
        .then(|| integration_version.to_utf8_lossy().into_owned());

    let action = TelemetryActions::AddIntegration(Integration {
        name: integration_name.to_utf8_lossy().into_owned(),
        enabled: integration_enabled,
        version,
        compatible: None,
        auto_enabled: None,
    });
    buffer.buffer.push(action);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_addDependency_buffer(
    buffer: &mut TelemetryActionsBuffer,
    dependency_name: CharSlice,
    dependency_version: CharSlice,
) {
    let version = (!dependency_version.is_empty())
        .then(|| dependency_version.to_utf8_lossy().into_owned());

    let action = TelemetryActions::AddDependecy(Dependency {
        name: dependency_name.to_utf8_lossy().into_owned(),
        version,
    });
    buffer.buffer.push(action);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_enqueueConfig_buffer(
    buffer: &mut TelemetryActionsBuffer,
    config_key: CharSlice,
    config_value: CharSlice,
    origin: data::ConfigurationOrigin,
) {
    let action = TelemetryActions::AddConfig(data::Configuration {
        name: config_key.to_utf8_lossy().into_owned(),
        value: config_value.to_utf8_lossy().into_owned(),
        origin,
    });
    buffer.buffer.push(action);
}

#[no_mangle]
pub extern "C" fn ddog_sidecar_telemetry_buffer_flush(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    buffer: Box<TelemetryActionsBuffer>,
) -> MaybeError {
    try_c!(blocking::enqueue_actions(
        transport,
        instance_id,
        queue_id,
        buffer.buffer,
    ));

    MaybeError::None
}
