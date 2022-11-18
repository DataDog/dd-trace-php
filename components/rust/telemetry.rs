use std::error::Error;
use std::fs;
use std::path::Path;
use ddcommon_ffi::CharSlice;
use ddcommon_ffi::slice::AsBytes;
use ddtelemetry::data::{Dependency, DependencyType};
use ddtelemetry::ipc::interface::blocking::TelemetryTransport;
use ddtelemetry::ipc::interface::{blocking, InstanceId, QueueId};
use ddtelemetry::worker::{TelemetryActions, TelemetryWorkerHandle};

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

    for dep in parsed.members() {
        if let Some(name) = dep["name"].as_str() {
            deps.push(TelemetryActions::AddDependecy(Dependency {
                name: String::from(name),
                version: dep["version"].as_str().map(String::from),
                hash: None,
                type_: DependencyType::PlatformStandard,
            }));
        }
    }

    if deps.len() > 0 {
        blocking::enqueue_actions(transport, instance_id, queue_id, deps)?;
    }

    Ok(())
}