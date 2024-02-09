use datadog_sidecar::config;
use datadog_sidecar::interface::blocking::SidecarTransport;
use datadog_sidecar::interface::{blocking, InstanceId, QueueId};
use ddcommon_ffi::slice::AsBytes;
use ddcommon_ffi::CharSlice;
use ddtelemetry::data;
use ddtelemetry::data::{Dependency, Integration};
use ddtelemetry::worker::TelemetryActions;
use ddtelemetry_ffi::{try_c, MaybeError};
#[cfg(php_shared_build)]
use spawn_worker::LibDependency;
use std::error::Error;
use std::path::Path;
use std::{fs, io};
use std::ffi::{c_char, CStr, OsStr};
use std::os::unix::ffi::OsStrExt;
use datadog_sidecar::config::LogMethod;

#[must_use]
#[no_mangle]
pub extern "C" fn ddtrace_detect_composer_installed_json(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    path: CharSlice,
) -> bool {
    let pathstr = unsafe { path.to_utf8_lossy() };
    if let Some(index) = pathstr.rfind("/vendor/autoload.php") {
        let path = format!("{}{}", &pathstr[..index], "/vendor/composer/installed.json");
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
    let json = fs::read_to_string(Path::new(path.as_str()))?;
    let parsed = json::parse(json.as_str())?;

    let mut deps = Vec::new();

    for dep in parsed["packages"].members() {
        if let Some(name) = dep["name"].as_str() {
            deps.push(TelemetryActions::AddDependecy(Dependency {
                name: String::from(name),
                version: dep["version"].as_str().map(String::from),
            }));
        }
    }

    if !deps.is_empty() {
        blocking::enqueue_actions(transport, instance_id, queue_id, deps)?;
    }

    Ok(())
}

#[cfg(php_shared_build)]
extern "C" {
    static DDTRACE_MOCK_PHP: u8;
    static DDTRACE_MOCK_PHP_SIZE: usize;
}

#[cfg(php_shared_build)]
fn run_sidecar(mut cfg: config::Config) -> io::Result<SidecarTransport> {
    let mock = unsafe { std::slice::from_raw_parts(&DDTRACE_MOCK_PHP, DDTRACE_MOCK_PHP_SIZE) };
    cfg.library_dependencies
        .push(LibDependency::Binary(mock));
    datadog_sidecar::start_or_connect_to_sidecar(cfg)
}

#[cfg(not(php_shared_build))]
fn run_sidecar(cfg: config::Config) -> io::Result<SidecarTransport> {
    datadog_sidecar::start_or_connect_to_sidecar(cfg)
}

#[no_mangle]
pub extern "C" fn ddog_sidecar_connect_php(
    connection: &mut *mut SidecarTransport,
    error_path: *const c_char,
    log_level: CharSlice,
    enable_telemetry: bool,
) -> MaybeError {
    let mut cfg = config::FromEnv::config();
    cfg.self_telemetry = enable_telemetry;
    unsafe {
        if *error_path != 0 {
            cfg.log_method = LogMethod::File(OsStr::from_bytes(CStr::from_ptr(error_path).to_bytes()).into())
        }
        cfg.child_env.insert(OsStr::new("DD_TRACE_LOG_LEVEL").into(), OsStr::from_bytes(log_level.as_bytes()).into());
    }
    let stream = Box::new(try_c!(run_sidecar(cfg)));
    *connection = Box::into_raw(stream);

    MaybeError::None
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
