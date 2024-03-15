use datadog_sidecar::interface::blocking::SidecarTransport;
use datadog_sidecar::interface::{blocking, InstanceId, MetricDefinition, QueueId, SidecarAction};
use ddcommon_ffi::slice::AsBytes;
use ddcommon_ffi::CharSlice;
use ddcommon::tag::Tag;
use ddtelemetry::data;
use ddtelemetry::data::{Dependency, Integration};
use ddtelemetry::worker::TelemetryActions;
use ddtelemetry_ffi::{try_c, MaybeError};
use std::error::Error;
use std::path::PathBuf;
use std::str::FromStr;

// use ddtelemetry::data::metrics::MetricType;
// use ddtelemetry::metrics::MetricContexts;
// use ddtelemetry::data::metrics::MetricNamespace;

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

fn parse_composer_installed_json(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    path: String,
) -> Result<(), Box<dyn Error>> {
    let action = vec![SidecarAction::PhpComposerTelemetryFile(PathBuf::from_str(path.as_str())?)];
    blocking::enqueue_actions(transport, instance_id, queue_id, action)?;

    Ok(())
}

#[derive(Default)]
pub struct SidecarActionsBuffer {
    buffer: Vec<SidecarAction>,
}

#[no_mangle]
pub extern "C" fn ddog_sidecar_telemetry_buffer_alloc() -> Box<SidecarActionsBuffer> {
    SidecarActionsBuffer::default().into()
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_addIntegration_buffer(
    buffer: &mut SidecarActionsBuffer,
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
    buffer.buffer.push(SidecarAction::Telemetry(action));
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_addDependency_buffer(
    buffer: &mut SidecarActionsBuffer,
    dependency_name: CharSlice,
    dependency_version: CharSlice,
) {
    let version = (!dependency_version.is_empty())
        .then(|| dependency_version.to_utf8_lossy().into_owned());

    let action = TelemetryActions::AddDependecy(Dependency {
        name: dependency_name.to_utf8_lossy().into_owned(),
        version,
    });
    buffer.buffer.push(SidecarAction::Telemetry(action));
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_enqueueConfig_buffer(
    buffer: &mut SidecarActionsBuffer,
    config_key: CharSlice,
    config_value: CharSlice,
    origin: data::ConfigurationOrigin,
) {
    let action = TelemetryActions::AddConfig(data::Configuration {
        name: config_key.to_utf8_lossy().into_owned(),
        value: config_value.to_utf8_lossy().into_owned(),
        origin,
    });
    buffer.buffer.push(SidecarAction::Telemetry(action));
}

#[no_mangle]
pub extern "C" fn ddog_sidecar_telemetry_buffer_flush(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    buffer: Box<SidecarActionsBuffer>,
) -> MaybeError {
    try_c!(blocking::enqueue_actions(
        transport,
        instance_id,
        queue_id,
        buffer.buffer,
    ));

    MaybeError::None
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_register_metric_buffer(
    buffer: &mut SidecarActionsBuffer,
    metric_name: CharSlice,
    // FIXME: missing type (count, gauge, ...), namespace, ...
) {

    buffer.buffer.push(SidecarAction::RegisterTelemetryMetric(MetricDefinition {
        name: metric_name.to_utf8_lossy().into_owned()
    }));
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_add_point_buffer(
    buffer: &mut SidecarActionsBuffer,
    metric_name: CharSlice,
    metric_value: u32, // FIXME: should be f64
    integration_name: CharSlice, // FIXME: should be an array of tags
) {
    let mut tags: Vec<Tag> = Vec::default();
    if integration_name.len() > 0 {
        tags.push(Tag::new("integration_name", integration_name.to_utf8_lossy().into_owned()).unwrap())
    }

    buffer.buffer.push(SidecarAction::AddTelemetryMetricPoint((
        metric_name.to_utf8_lossy().into_owned(),
        metric_value as f64,
        tags,
    )));
}