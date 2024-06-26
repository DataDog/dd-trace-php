use std::ffi::{c_char, CStr, OsStr};
#[cfg(unix)]
use std::os::unix::ffi::OsStrExt;
use lazy_static::{lazy_static, LazyStatic};
use tracing::warn;
use datadog_sidecar::config::{self, LogMethod};
use datadog_sidecar::service::blocking::SidecarTransport;
use datadog_sidecar::shm_limiters::{AnyLimiter, Limiter, LocalLimiter, ShmLimiterMemory};
use ddcommon_ffi::slice::AsBytes;
use ddcommon_ffi::{CharSlice, self as ffi, MaybeError};
use ddtelemetry_ffi::try_c;
#[cfg(any(windows, php_shared_build))]
use spawn_worker::LibDependency;
#[cfg(windows)]
use spawn_worker::get_trampoline_target_data;


#[cfg(php_shared_build)]
extern "C" {
    static DDTRACE_MOCK_PHP: u8;
    static DDTRACE_MOCK_PHP_SIZE: usize;
}

#[cfg(php_shared_build)]
fn run_sidecar(mut cfg: config::Config) -> anyhow::Result<SidecarTransport> {
    let mock = unsafe { std::slice::from_raw_parts(&DDTRACE_MOCK_PHP, DDTRACE_MOCK_PHP_SIZE) };
    cfg.library_dependencies
        .push(LibDependency::Binary(mock));
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

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut ddtrace_sidecar: *mut SidecarTransport = std::ptr::null_mut();

#[no_mangle]
pub extern "C" fn ddtrace_sidecar_reconnect(
    transport: &mut Box<SidecarTransport>,
    factory: unsafe extern "C" fn() -> Option<Box<SidecarTransport>>,
) {
    transport.reconnect(|| unsafe {
        let sidecar = factory();
        if sidecar.is_some() {
            LazyStatic::initialize(&SHM_LIMITER);
        }
        sidecar
    });
}


lazy_static! {
    pub static ref SHM_LIMITER: Option<ShmLimiterMemory> = ShmLimiterMemory::open().map_or_else(|e| {
        warn!("Attempt to use the SHM_LIMITER failed: {e:?}");
        None
    }, Some);
}

pub struct MaybeShmLimiter(Option<AnyLimiter>);

impl MaybeShmLimiter {
    pub fn open(index: u32) -> Self {
        MaybeShmLimiter(if index == 0 {
            None
        } else {
            match &*SHM_LIMITER {
                Some(limiter) => limiter.get(index).map(AnyLimiter::Shm),
                None => Some(AnyLimiter::Local(LocalLimiter::default())),
            }
        })
    }
    
    pub fn inc(&self, limit: u32) -> bool {
        if let Some(ref limiter) = self.0 {
            limiter.inc(limit)
        } else {
            true
        }
    }
}

#[no_mangle]
pub extern "C" fn ddog_shm_limiter_inc(limiter: &MaybeShmLimiter, limit: u32) -> bool {
    limiter.inc(limit)
}
