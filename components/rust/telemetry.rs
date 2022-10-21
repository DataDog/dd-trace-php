use std::error::Error;
use std::fs;
use std::path::Path;
use ddcommon_ffi::CharSlice;
use ddcommon_ffi::slice::AsBytes;
use ddtelemetry::worker::TelemetryWorkerHandle;

#[must_use]
#[no_mangle]
pub extern "C" fn ddtrace_detect_composer_installed_json(telemetry: &TelemetryWorkerHandle, path: CharSlice) -> bool {
    let pathstr = unsafe { path.to_utf8_lossy() };
    if let Some(index) = pathstr.rfind("/vendor/autoload.php") {
        if parse_composer_installed_json(telemetry, format!("{}{}", &pathstr[..index], "/vendor/composer/installed.json")).is_ok() {
            return true;
        }
    }
    false
}

fn parse_composer_installed_json(telemetry: &TelemetryWorkerHandle, path: String) -> Result<(), Box<dyn Error>> {
    let json = fs::read_to_string(Path::new(path.as_str()))?;
    let parsed = json::parse(json.as_str())?;

    for dep in parsed.members() {
        if let Some(name) = dep["name"].as_str() {
            telemetry.add_dependency(String::from(name), dep["version"].as_str().map(String::from))?;
        }
    }

    Ok(())
}