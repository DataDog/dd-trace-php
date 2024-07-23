use std::ffi::{c_char, CStr, OsStr};
use std::ops::DerefMut;
#[cfg(unix)]
use std::os::unix::ffi::OsStrExt;
#[cfg(windows)]
use std::os::windows::ffi::OsStrExt;
use std::sync::Mutex;
use datadog_sidecar::config::{self, AppSecConfig, LogMethod};
use datadog_sidecar::service::blocking::SidecarTransport;
use ddcommon_ffi::slice::AsBytes;
use ddcommon_ffi::{CharSlice, self as ffi, MaybeError};
use ddtelemetry_ffi::try_c;
use lazy_static::lazy_static;
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
    if !unsafe { DDTRACE_MOCK_PHP_SIZE }.is_null() {
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

lazy_static! {
    static ref APPSEC_CONFIG: Mutex<Option<AppSecConfig>> = Mutex::new(None);
}

// must be called prior to ddog_sidecar_connect
#[no_mangle]
pub extern "C" fn ddog_sidecar_enable_appsec(
    shared_lib_path: CharSlice,
    socket_file_path: CharSlice,
    lock_file_path: CharSlice,
    log_file_path: CharSlice,
    log_level: CharSlice,
) -> MaybeError {
    let mut appsec_config_guard = APPSEC_CONFIG.lock().unwrap();
    let shared_lib_path_os: std::ffi::OsString;
    let socket_file_path_os: std::ffi::OsString;
    let lock_file_path_os: std::ffi::OsString;
    let log_file_path_os: std::ffi::OsString;

    #[cfg(unix)]
    {
        shared_lib_path_os = OsStr::from_bytes(shared_lib_path.as_bytes()).to_owned();
        socket_file_path_os = OsStr::from_bytes(socket_file_path.as_bytes()).to_owned();
        lock_file_path_os = OsStr::from_bytes(lock_file_path.as_bytes()).to_owned();
        log_file_path_os = OsStr::from_bytes(log_file_path.as_bytes()).to_owned();
    }

    #[cfg(windows)]
    {
        shared_lib_path_os = OsStr::new(&*shared_lib_path.to_utf8_lossy()).to_owned();
        socket_file_path_os = OsStr::new(&*socket_file_path.to_utf8_lossy()).to_owned();
        lock_file_path_os = OsStr::new(&*lock_file_path.to_utf8_lossy()).to_owned();
        log_file_path_os = OsStr::new(&*log_file_path.to_utf8_lossy()).to_owned();
    }

    appsec_config_guard.deref_mut().replace(AppSecConfig {
        shared_lib_path: shared_lib_path_os,
        socket_file_path: socket_file_path_os,
        lock_file_path: lock_file_path_os,
        log_file_path: log_file_path_os,
        log_level: log_level.to_utf8_lossy().to_string(),
    });

    MaybeError::None
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
    let appsec_cfg_guard = APPSEC_CONFIG.lock().unwrap();
    cfg.appsec_config = appsec_cfg_guard.clone();
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
