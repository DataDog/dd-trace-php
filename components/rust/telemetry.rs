use std::error::Error;
use std::fs;
use std::io::Write;
use std::path::Path;
use ddcommon_ffi::CharSlice;
use ddcommon_ffi::slice::AsBytes;
use ddtelemetry::data::Dependency;
use ddtelemetry::ipc::interface::blocking::TelemetryTransport;
use ddtelemetry::ipc::interface::{blocking, InstanceId, QueueId};
use ddtelemetry::ipc::sidecar::config;
use ddtelemetry::worker::TelemetryActions;
use ddtelemetry_ffi::try_c;

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

const MOCK_PHP: &[u8] = include_bytes!(concat!(
    env!("OUT_DIR"),
    "/mock_php.shared_lib"
));

/// # Safety
/// Caller must ensure the process is safe to fork, at the time when this method is called
#[no_mangle]
pub extern "C" fn ddog_sidecar_connect_php(connection: &mut *mut TelemetryTransport) -> ddtelemetry_ffi::MaybeError {
    let mut cfg = config::FromEnv::config();

    let mut file = try_c!(tempfile::NamedTempFile::new());
    try_c!(file.write_all(MOCK_PHP));
    cfg.library_dependencies.push(file.path().to_path_buf());

    let stream = Box::new(try_c!(ddtelemetry::ipc::sidecar::start_or_connect_to_sidecar(cfg)));
    *connection = Box::into_raw(stream);

    ddtelemetry_ffi::MaybeError::None
}