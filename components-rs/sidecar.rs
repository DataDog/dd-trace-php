use std::ffi::{c_char, CStr, OsStr};
use std::ops::DerefMut;
#[cfg(unix)]
use std::os::unix::ffi::OsStrExt;
use lazy_static::{lazy_static, LazyStatic};
use tracing::warn;
use std::sync::Mutex;
use std::time::Duration;
#[cfg(target_os = "macos")]
use std::sync::atomic::{AtomicI32, Ordering};
use datadog_sidecar::config::{self, AppSecConfig, LogMethod};
use datadog_sidecar::service::blocking::{acquire_exception_hash_rate_limiter, SidecarTransport};
use libdd_common::rate_limiter::{Limiter, LocalLimiter};
use datadog_ipc::rate_limiter::{AnyLimiter, ShmLimiterMemory};
use datadog_sidecar::service::exception_hash_rate_limiter::ExceptionHashRateLimiter;
use datadog_sidecar::tracer::shm_limiter_path;
use libdd_common::Endpoint;
use libdd_common_ffi::slice::AsBytes;
use libdd_common_ffi::{CharSlice, self as ffi, MaybeError};
use libdd_telemetry_ffi::try_c;
#[cfg(windows)]
use spawn_worker::{get_trampoline_target_data, LibDependency};

#[cfg(php_shared_build)]
fn run_sidecar(mut cfg: config::Config) -> anyhow::Result<SidecarTransport> {
    #[cfg(target_os = "linux")]
    if std::env::var_os("DD_SIDECAR_DISABLE_DIRECT_EXEC").map(|s| s.is_empty()).unwrap_or(true)
        && std::env::var_os("DD_SPAWN_WORKER_USE_EXEC").map(|s| s.is_empty()).unwrap_or(true) {
        cfg.spawn_without_trampoline = true;
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

#[cfg(target_os = "macos")]
static CRASHTRACKER_SIDECAR_FD: AtomicI32 = AtomicI32::new(-1);

#[cfg(any(target_os = "linux", target_os = "macos"))]
#[no_mangle]
pub extern "C" fn datadog_crasht_init_without_receiver(
    config: libdd_crashtracker_ffi::Config<'_>,
    metadata: libdd_crashtracker_ffi::Metadata<'_>,
    sidecar_master_pid: u32,
) -> libdd_common_ffi::VoidResult {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        init_crashtracker_without_receiver(config, metadata, sidecar_master_pid)
    }))
    .map_or_else(
        |panic| {
            libdd_common_ffi::utils::handle_panic_error(
                panic,
                "datadog_crasht_init_without_receiver",
            )
            .into()
        },
        Into::into,
    )
}

#[cfg(any(target_os = "linux", target_os = "macos"))]
fn init_crashtracker_without_receiver(
    config: libdd_crashtracker_ffi::Config<'_>,
    metadata: libdd_crashtracker_ffi::Metadata<'_>,
    _sidecar_master_pid: u32,
) -> anyhow::Result<()> {
    let mut config: libdd_crashtracker::CrashtrackerConfiguration = config.try_into()?;
    _ = datadog_sidecar::crashtracker::crashtracker_receiver_request_bytes();

    #[cfg(target_os = "linux")]
    {
        let path = datadog_sidecar::crashtracker::crashtracker_ipc_socket_path(
            _sidecar_master_pid,
            config::FromEnv::ipc_mode(),
        );
        config.set_unix_socket_path(path.to_string_lossy().into_owned());
        config
            .set_unix_socket_connector(datadog_sidecar::crashtracker::connect_to_sidecar_receiver);
    }

    #[cfg(target_os = "macos")]
    {
        config.set_unix_socket_path("sidecar".to_owned());
        config.set_unix_socket_connector(connect_to_sidecar_crashtracker_receiver);
    }

    libdd_crashtracker::init(
        config,
        libdd_crashtracker::CrashtrackerReceiverConfig::default(),
        metadata.try_into()?,
    )?;
    Ok(())
}

#[cfg(target_os = "macos")]
fn connect_to_sidecar_crashtracker_receiver(_unix_socket_path: &str) -> std::os::fd::RawFd {
    let fd = CRASHTRACKER_SIDECAR_FD.load(Ordering::Acquire);
    if fd < 0 {
        return -1;
    }

    let request = datadog_sidecar::crashtracker::crashtracker_receiver_request_bytes();
    // SAFETY: fd is the registered sidecar connection and request points to immutable static bytes.
    let written = unsafe { libc::send(fd, request.as_ptr().cast(), request.len(), 0) };
    if written == request.len() as isize {
        fd
    } else {
        -1
    }
}

// must be called prior to ddog_sidecar_connect
#[no_mangle]
pub extern "C" fn ddog_sidecar_enable_appsec(
    shared_lib_path: CharSlice,
    socket_file_path: CharSlice,
    lock_file_path: CharSlice,
    log_file_path: CharSlice,
    log_level: CharSlice,
) -> () {
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
}

fn sidecar_connect(cfg: config::Config) -> anyhow::Result<Box<SidecarTransport>> {
    let mut stream = Box::new(run_sidecar(cfg)?);
    #[cfg(any(target_os = "linux", target_os = "macos"))]
    if let Ok(pid) = stream.peer_pid() {
        libdd_crashtracker::set_expected_receiver_pid(pid as i32);
    }
    #[cfg(target_os = "macos")]
    CRASHTRACKER_SIDECAR_FD.store(stream.as_raw_fd(), Ordering::Release);
    // Generally the Send buffer ought to be big enough for instantaneous transmission
    _ = stream.set_write_timeout(Some(Duration::from_millis(100)));
    _ = stream.set_read_timeout(Some(Duration::from_secs(10)));
    // We do not put reconnect_fn into sidecar_connect, as the reconnect shall not reconnect again on error to prevent recursion
    Ok(stream)
}

#[no_mangle]
pub extern "C" fn ddog_sidecar_connect_php(
    connection: &mut *mut SidecarTransport,
    error_path: *const c_char,
    log_level: CharSlice,
    enable_telemetry: bool,
    on_reconnect: Option<extern "C" fn(*mut SidecarTransport)>,
    crashtracker_endpoint: Option<&Endpoint>,
    backpressure_bytes: u64,
    backpressure_queue: u64,
) -> MaybeError {
    let mut cfg = config::FromEnv::config();
    cfg.self_telemetry = enable_telemetry;
    let appsec_cfg_guard = APPSEC_CONFIG.lock().unwrap();
    cfg.appsec_config = appsec_cfg_guard.clone();
    cfg.crashtracker_endpoint = crashtracker_endpoint.map(Clone::clone);
    unsafe {
        if *error_path != 0 {
            let error_path = CStr::from_ptr(error_path).to_bytes();
            #[cfg(windows)]
            if let Ok(str) = std::str::from_utf8(error_path) {
                cfg.log_method = LogMethod::File(str.into());
            }
            #[cfg(not(windows))]
            {
                // Paths containing a colon generally are some magic - just log to stderr directly
                // E.g. "/var/www/html/host:[3]" on a serverless platform
                // In general, stdio is the only way for having magic paths here.
                if error_path.contains(&b':') {
                    cfg.log_method = LogMethod::Stderr;
                } else {
                    cfg.log_method = LogMethod::File(OsStr::from_bytes(error_path).into());
                }
            }
        }
        #[cfg(windows)]
            let log_level = log_level.to_utf8_lossy().as_ref().into();
        #[cfg(not(windows))]
            let log_level = OsStr::from_bytes(log_level.as_bytes()).into();
        cfg.child_env.insert(OsStr::new("DD_TRACE_LOG_LEVEL").into(), log_level);
    }
    
    cfg.pipe_buffer_size = backpressure_bytes as usize;

    let reconnect_fn = on_reconnect.map(|on_reconnect| {
        let cfg = cfg.clone();
        Box::new(move || {
            let mut transport = sidecar_connect(cfg.clone()).ok()?;
            on_reconnect(transport.as_mut() as *mut _);
            Some(transport)
        }) as Box<dyn Fn() -> _>
    });
    
    let mut stream = try_c!(sidecar_connect(cfg));
    stream.reconnect_fn = reconnect_fn;
    let _ = stream.set_backpressure(backpressure_bytes as usize, backpressure_queue);
    *connection = Box::into_raw(stream);

    MaybeError::None
}

#[no_mangle]
pub extern "C" fn datadog_sidecar_reconnect(
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
    pub static ref SHM_LIMITER: Option<ShmLimiterMemory<()>> = ShmLimiterMemory::open(&shm_limiter_path()).map_or_else(|e| {
        warn!("Attempt to use the SHM_LIMITER failed: {e:?}");
        None
    }, Some);

    pub static ref EXCEPTION_HASH_LIMITER: Option<ExceptionHashRateLimiter> = ExceptionHashRateLimiter::open().map_or_else(|e| {
        warn!("Attempt to use the EXCEPTION_HASH_LIMITER failed: {e:?}");
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

#[no_mangle]
pub extern "C" fn ddog_exception_hash_limiter_inc(connection: &mut SidecarTransport, hash: u64, granularity_seconds: u32) -> bool {
    if let Some(limiter) = &*EXCEPTION_HASH_LIMITER {
        if let Some(limiter) = limiter.find(hash) {
            return limiter.inc();
        }
    }
    let _ = acquire_exception_hash_rate_limiter(connection, hash, Duration::from_secs(granularity_seconds as u64));
    true
}
