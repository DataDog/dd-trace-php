#![warn(clippy::extra_unused_lifetimes, clippy::needless_lifetimes)]

use anyhow::Context;
use log::Log;
use std::path::PathBuf;
use std::sync::atomic::{AtomicPtr, Ordering};
use std::time::{Duration, Instant};
use tokio::runtime::Runtime;
use tokio::task::JoinHandle;
use tokio_util::sync::CancellationToken;

mod client;
pub mod config;
mod ffi;
mod lock;
mod rc;
mod rc_notify;
pub mod server;
mod service;
mod telemetry;

use config::Config;
#[cfg(target_os = "linux")]
use lock::ensure_abstract_socket_unique;
use lock::LockFile;

static RUNTIME: AtomicPtr<Runtime> = const { AtomicPtr::new(std::ptr::null_mut()) };
static CANCEL_TOKEN: AtomicPtr<CancellationToken> = const { AtomicPtr::new(std::ptr::null_mut()) };
static SERVER_HANDLE: AtomicPtr<JoinHandle<anyhow::Result<()>>> =
    const { AtomicPtr::new(std::ptr::null_mut()) };

/// C API entry point: Initialize and start the AppSec helper
///
/// This function:
/// - Initializes logging from environment variables
/// - Loads configuration from environment variables
/// - Acquires a lock file to ensure process uniqueness
/// - Creates a tokio runtime
/// - Spawns the server task
/// - Returns immediately while the server runs in the background
///
/// Returns 0 on success, non-zero on error
#[no_mangle]
pub extern "C" fn appsec_helper_main() -> i32 {
    let config = match Config::from_env() {
        Ok(cfg) => cfg,
        Err(e) => {
            log::error!("Failed to read configuration: {}", e);
            return 1;
        }
    };

    if let Err(e) = init_logging(&config.log_level, &config.log_file_path) {
        eprintln!("Failed to initialize logging: {}", e);
        return 1;
    }

    log::info!("AppSec helper starting");

    log::info!("Configuration: {:?}", config);

    let lock = ensure_uniqueness(&config);
    if let Err(e) = lock {
        log::error!("Failed to ensure uniqueness: {}", e);
        return 1;
    }
    let lock = lock.unwrap();

    init_waf_logging(&config);

    if let Err(e) = rc_notify::resolve_symbols() {
        log::error!(
            "Failed to resolve RC notify symbols: {}; will not get Remote Config updates",
            e
        );
    }

    if let Err(e) = telemetry::resolve_symbols() {
        log::error!(
            "Failed to resolve sidecar telemetry symbols: {}; telemetry logs will not be submitted",
            e
        );
    }

    let runtime = match Runtime::new() {
        Ok(rt) => rt,
        Err(e) => {
            log::error!("Failed to create tokio runtime: {}", e);
            return 1;
        }
    };

    let cancel_token = CancellationToken::new();

    // Spawn the server task and store its handle
    let server_handle = runtime.spawn(server::run_server(config, cancel_token.clone()));

    // This should never fail, because this method is supposed to be called only once
    // So the value of the atomic pointer is supposed to be null.
    RUNTIME
        .compare_exchange(
            std::ptr::null_mut(),
            Box::into_raw(Box::new(runtime)),
            Ordering::Release,
            Ordering::Relaxed,
        )
        .unwrap();
    CANCEL_TOKEN
        .compare_exchange(
            std::ptr::null_mut(),
            Box::into_raw(Box::new(cancel_token)),
            Ordering::Release,
            Ordering::Relaxed,
        )
        .unwrap();
    SERVER_HANDLE
        .compare_exchange(
            std::ptr::null_mut(),
            Box::into_raw(Box::new(server_handle)),
            Ordering::Release,
            Ordering::Relaxed,
        )
        .unwrap();
    if let Some(lock) = lock {
        std::mem::forget(lock); // don't run the Drop impl
    }

    log::info!("AppSec helper started successfully");
    0 // return immediately - runtime keeps running in background
}

/// C API entry point: Shutdown the AppSec helper gracefully
///
/// This function implements a three-phase shutdown:
/// 1. Signal cooperative cancellation to all tasks
/// 2. Wait a grace period for tasks to finish cleanly
/// 3. Force shutdown remaining tasks with a timeout
///
/// Total shutdown time: 2 seconds
///
/// Returns 0 on success
#[no_mangle]
pub extern "C" fn appsec_helper_shutdown() -> i32 {
    log::info!("AppSec helper shutdown initiated");

    let maybe_cancel_token = consume_atomic_ptr(&CANCEL_TOKEN);
    match maybe_cancel_token {
        Some(cancel_token) => {
            cancel_token.cancel();
            log::info!("Cancellation signal sent to all tasks");
        }
        None => {
            log::warn!("No cancellation token in shutdown; initialization failed?");
            return 0;
        }
    }

    // Wait for server task to complete (with timeout)
    // Poll the server handle to see if it's finished, up to 1 second
    let server_handle_ptr = SERVER_HANDLE.load(Ordering::Acquire);
    if server_handle_ptr.is_null() {
        log::warn!("No server handle in shutdown; initialization failed?");
        return 0;
    }
    let server_handle = unsafe { &*server_handle_ptr };
    let start = Instant::now();
    let grace_timeout = Duration::from_millis(1000);

    while start.elapsed() < grace_timeout {
        if server_handle.is_finished() {
            log::info!("Server task completed gracefully in {:?}", start.elapsed());
            break;
        }
        std::thread::sleep(Duration::from_millis(10));
    }

    if !server_handle.is_finished() {
        log::warn!("Server task did not complete within grace period");
    }

    let runtime = consume_atomic_ptr(&RUNTIME).expect("Runtime should be present");
    runtime.shutdown_timeout(Duration::from_millis(1000));
    log::info!("Runtime shutdown complete");

    log::info!("AppSec helper shutdown complete");
    0
}

fn ensure_uniqueness(config: &Config) -> anyhow::Result<Option<LockFile>> {
    if config.is_abstract_socket() {
        #[cfg(target_os = "linux")]
        if let Err(e) = ensure_abstract_socket_unique(&config.socket_path) {
            anyhow::bail!("Failed to ensure uniqueness: {}", e);
        } else {
            Ok(None)
        }
        #[cfg(not(target_os = "linux"))]
        {
            anyhow::bail!("Abstract namespace sockets are only supported on Linux");
        }
    } else {
        match LockFile::acquire(config.lock_path.clone()) {
            Ok(lock) => Ok(Some(lock)),
            Err(e) => {
                anyhow::bail!("Failed to acquire lock: {}", e);
            }
        }
    }
}

fn init_logging(log_level: &log::Level, log_file_path: &Option<PathBuf>) -> anyhow::Result<()> {
    use simplelog::*;
    use telemetry::TelemetryAwareLogger;

    let log_level_filter = match log_level {
        log::Level::Error => LevelFilter::Error,
        log::Level::Warn => LevelFilter::Warn,
        log::Level::Info => LevelFilter::Info,
        log::Level::Debug => LevelFilter::Debug,
        log::Level::Trace => LevelFilter::Trace,
    };

    let config = ConfigBuilder::new()
        .set_time_format_rfc3339()
        .set_thread_level(LevelFilter::Debug)
        .set_target_level(LevelFilter::Debug)
        .build();

    // Create the primary logger (file or terminal)
    let primary_logger: Box<dyn Log> = if let Some(log_path) = log_file_path {
        let log_file = std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(log_path)
            .with_context(|| format!("Failed to open log file: {:?}", log_path))?;

        eprintln!("AppSec helper logging to file: {:?}", log_path);
        WriteLogger::new(log_level_filter, config, log_file)
    } else {
        // TermLogger outputs to stderr, which works in most environments.
        TermLogger::new(
            log_level_filter,
            config,
            TerminalMode::Stderr,
            ColorChoice::Auto,
        )
    };

    // Wrap with telemetry-aware logger that auto-submits error logs to telemetry
    let tel_aware_logger = TelemetryAwareLogger::new(primary_logger);

    log::set_max_level(log_level_filter);
    log::set_boxed_logger(Box::new(tel_aware_logger))?;

    Ok(())
}

fn init_waf_logging(config: &Config) {
    use libddwaf::log::Level as DdwafLogLevel;
    use std::ffi::CStr;

    let min_level = match config.log_level {
        log::Level::Error => DdwafLogLevel::Error,
        log::Level::Warn => DdwafLogLevel::Warn,
        log::Level::Info => DdwafLogLevel::Warn, // intentional
        log::Level::Debug => DdwafLogLevel::Debug,
        log::Level::Trace => DdwafLogLevel::Trace,
    };

    unsafe {
        libddwaf::log::set_log_cb(
            |level: DdwafLogLevel,
             function: &'static CStr,
             file: &'static CStr,
             line: u32,
             message: &[u8]| {
                let msg_str = std::str::from_utf8(message);
                crate::client::log::client_log_gen!(
                    match level {
                        DdwafLogLevel::Error => log::Level::Error,
                        DdwafLogLevel::Warn => log::Level::Warn,
                        DdwafLogLevel::Info => log::Level::Debug, // intentional
                        DdwafLogLevel::Debug => log::Level::Trace, // intentional
                        DdwafLogLevel::Trace => log::Level::Trace,
                        _ => std::unreachable!("Invalid log level"),
                    },
                    "{} in {:?} at {:?}:{:?}",
                    msg_str.unwrap_or("(invalid utf-8 in message)"),
                    function,
                    file,
                    line
                );
            },
            min_level,
        )
    };
}

fn consume_atomic_ptr<T>(atomic_ptr: &AtomicPtr<T>) -> Option<Box<T>> {
    let ptr = atomic_ptr.load(Ordering::Acquire);
    if ptr.is_null() {
        return None;
    }

    let res = atomic_ptr.compare_exchange(
        ptr,
        std::ptr::null_mut(),
        Ordering::Relaxed,
        Ordering::Relaxed,
    );
    match res {
        // SAFETY: the pointer is not null, we know it came from a box, and
        // we're the only thread that managed to consume the pointer.
        // There is no ABA issue because the store should with a non-null value
        // happens only once, in the entrypoint.
        Ok(_) => Some(unsafe { Box::from_raw(ptr) }),
        Err(_) => None,
    }
}
