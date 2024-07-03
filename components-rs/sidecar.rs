use std::ffi::{c_char, CStr, OsStr};
#[cfg(unix)]
use std::os::unix::ffi::OsStrExt;
use datadog_sidecar::config::{self, LogMethod};
use datadog_sidecar::service::blocking::SidecarTransport;
use ddcommon_ffi::slice::AsBytes;
use ddcommon_ffi::{CharSlice, self as ffi, MaybeError};
use ddtelemetry_ffi::try_c;
#[cfg(any(windows, php_shared_build))]
use spawn_worker::LibDependency;
#[cfg(windows)]
use spawn_worker::get_trampoline_target_data;


#[cfg(php_shared_build)]
extern "C" {
    #[linkage="extern_weak"]
    static DDTRACE_MOCK_PHP: *mut u8;
    #[linkage="extern_weak"]
    static DDTRACE_MOCK_PHP_SIZE: *mut usize;
}

#[cfg(php_shared_build)]
fn run_sidecar(mut cfg: config::Config) -> anyhow::Result<SidecarTransport> {
    if unsafe { *DDTRACE_MOCK_PHP_SIZE } > 0 {
        let mock = unsafe { std::slice::from_raw_parts(DDTRACE_MOCK_PHP, *DDTRACE_MOCK_PHP_SIZE) };
        cfg.library_dependencies
            .push(LibDependency::Binary(mock));
    }
    datadog_sidecar::start_or_connect_to_sidecar(cfg)
}

#[cfg(not(any(windows, php_shared_build)))]
fn run_sidecar(cfg: config::Config) -> anyhow::Result<SidecarTransport> {
    datadog_sidecar::start_or_connect_to_sidecar(cfg)
}

#[no_mangle]
#[cfg(windows)]
pub static mut DDOG_PHP_FUNCTION: *const u8 = std::ptr::null();

#[cfg(windows)]
fn run_sidecar(mut cfg: config::Config) -> anyhow::Result<SidecarTransport> {
    let php_dll = get_trampoline_target_data(unsafe { DDOG_PHP_FUNCTION })?;
    cfg.library_dependencies.push(LibDependency::Path(php_dll.into()));
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
            let error_path = CStr::from_ptr(error_path).to_bytes();
            #[cfg(windows)]
            if let Ok(str) = std::str::from_utf8(error_path) {
                cfg.log_method = LogMethod::File(str.into());
            }
            #[cfg(not(windows))]
            { cfg.log_method = LogMethod::File(OsStr::from_bytes(error_path).into()); }
        }
        #[cfg(windows)]
            let log_level = log_level.to_utf8_lossy().as_ref().into();
        #[cfg(not(windows))]
            let log_level = OsStr::from_bytes(log_level.as_bytes()).into();
        cfg.child_env.insert(OsStr::new("DD_TRACE_LOG_LEVEL").into(), log_level);
    }
    let stream = Box::new(try_c!(run_sidecar(cfg)));
    *connection = Box::into_raw(stream);

    MaybeError::None
}
