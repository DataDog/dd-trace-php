use std::error::Error;
use std::{fs, io};
use std::path::Path;
use ddcommon_ffi::CharSlice;
use ddcommon_ffi::slice::AsBytes;
use ddtelemetry::data::Dependency;
use datadog_sidecar::interface::blocking::TelemetryTransport;
use datadog_sidecar::interface::{blocking, InstanceId, QueueId};
use datadog_sidecar::config;
use ddtelemetry::worker::TelemetryActions;
use ddtelemetry_ffi::{MaybeError, try_c};
#[cfg(php_shared_build)]
use spawn_worker::LibDependency;

#[must_use]
#[no_mangle]
pub extern "C" fn ddtrace_detect_composer_installed_json(transport: &mut Box<TelemetryTransport>, instance_id: &InstanceId, queue_id: &QueueId, path: CharSlice) -> bool {
    let pathstr = unsafe { path.to_utf8_lossy() };
    if let Some(index) = pathstr.rfind("/vendor/autoload.php") {
        if parse_composer_installed_json(transport, instance_id, queue_id, format!("{}{}", &pathstr[..index], "/vendor/composer/installed.json")).is_ok() {
            return true;
        }
    }
    false
}

fn parse_composer_installed_json(transport: &mut Box<TelemetryTransport>, instance_id: &InstanceId, queue_id: &QueueId, path: String) -> Result<(), Box<dyn Error>> {
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

    if deps.len() > 0 {
        blocking::enqueue_actions(transport, instance_id, queue_id, deps)?;
    }

    Ok(())
}

#[cfg(php_shared_build)]
const MOCK_PHP: &[u8] = include_bytes!(concat!(
    env!("OUT_DIR"),
    "/mock_php.shared_lib"
));

#[cfg(php_shared_build)]
fn run_sidecar(mut cfg: config::Config) -> io::Result<TelemetryTransport> {
    cfg.library_dependencies.push(LibDependency::Binary(MOCK_PHP));
    datadog_sidecar::start_or_connect_to_sidecar(cfg)
}

#[cfg(not(php_shared_build))]
fn run_sidecar(cfg: config::Config) -> io::Result<TelemetryTransport> {
    datadog_sidecar::start_or_connect_to_sidecar(cfg)
}

#[no_mangle]
pub extern "C" fn ddog_sidecar_connect_php(connection: &mut *mut TelemetryTransport) -> MaybeError {
    let cfg = config::FromEnv::config();
    let stream = Box::new(try_c!(run_sidecar(cfg)));
    *connection = Box::into_raw(stream);

    MaybeError::None
}
