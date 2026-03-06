use crate::sapi::Sapi;
use crate::SAPI;
use core::ffi::c_void;
use libc::{c_char, sched_yield};
use std::cell::OnceCell;
use std::mem::MaybeUninit;
use std::thread::JoinHandle;
use std::time::{Duration, Instant};

/// Spawns a thread and masks off the signals that the Zend Engine uses.
pub fn spawn<F, T>(name: &str, f: F) -> JoinHandle<T>
where
    F: FnOnce() -> T + Send + 'static,
    T: Send + 'static,
{
    let result = std::thread::Builder::new()
        .name(name.to_string())
        .spawn(move || {
            /* Thread must not handle signals intended for PHP threads.
             * See Zend/zend_signal.c for which signals it registers.
             */
            unsafe {
                let mut sigset_mem = MaybeUninit::uninit();
                let sigset = sigset_mem.as_mut_ptr();
                libc::sigemptyset(sigset);

                const SIGNALS: [libc::c_int; 6] = [
                    libc::SIGPROF, // todo: SIGALRM on __CYGWIN__/__PHASE__
                    libc::SIGHUP,
                    libc::SIGINT,
                    libc::SIGTERM,
                    libc::SIGUSR1,
                    libc::SIGUSR2,
                ];

                for signal in SIGNALS {
                    libc::sigaddset(sigset, signal);
                }
                libc::pthread_sigmask(libc::SIG_BLOCK, sigset, std::ptr::null_mut());
            }
            f()
        });

    match result {
        Ok(handle) => handle,
        Err(err) => panic!("Failed to spawn thread {name}: {err}"),
    }
}

#[derive(thiserror::Error, Debug)]
#[error("timeout of {timeout_ms} ms reached when joining thread {thread}")]
pub struct TimeoutError {
    thread: String,
    timeout_ms: u128,
}

/// Waits for the handle to be finished. If finished, it will join the handle.
/// Otherwise, it will leak the handle and return an error.
/// # Panics
/// If the thread being joined has panic'd, this will resume the panic.
pub fn join_timeout(handle: JoinHandle<()>, timeout: Duration) -> Result<(), TimeoutError> {
    // After notifying the other threads, it's likely they'll need some time
    // to respond adequately. Joining on the JoinHandle is supposed to be the
    // correct way to do this, but we've observed this can panic:
    // https://github.com/DataDog/dd-trace-php/issues/1919
    // Thus far, we have not been able to reproduce it and address the root
    // cause. So, for now, mitigate it instead with a busy loop.
    let start = Instant::now();
    while !handle.is_finished() {
        unsafe { sched_yield() };
        if start.elapsed() >= timeout {
            let thread = handle.thread().name().unwrap_or("{unknown}").to_string();
            let timeout_ms = timeout.as_millis();
            return Err(TimeoutError { thread, timeout_ms });
        }
    }

    if let Err(err) = handle.join() {
        std::panic::resume_unwind(err);
    }
    Ok(())
}

/// Returns true if the current PHP thread is a worker spawned by ext-parallel.
///
/// Strategy:
///  1. Look up the "parallel" module in PHP's module_registry via the matrix.
///  2. Call `php_parallel_is_parallel_worker_thread` if present, added in
///     parallel 1.2.9.
///  3. Otherwise, fall back to reading the `php_parallel_scheduler_context`
///     TLS variable:
///     - Linux/musl: dlsym returns the resolved per-thread address directly.
///     - macOS: dlsym returns a TLS descriptor struct; call its thunk to get
///       the address.
fn is_parallel_thread(_: crate::OnPhpThread) -> bool {
    let module = crate::find_module_entry(c"parallel");
    if module.is_null() {
        return false;
    }
    let handle = unsafe { (*module).handle };
    if handle.is_null() {
        return false;
    }

    // Try the public API added in parallel 1.2.9.
    let new_api = unsafe {
        libc::dlsym(
            handle,
            b"php_parallel_is_parallel_worker_thread\0".as_ptr() as *const c_char,
        )
    };
    if !new_api.is_null() {
        type IsWorkerFn = unsafe extern "C" fn() -> bool;
        let is_worker: IsWorkerFn = unsafe { core::mem::transmute(new_api) };
        return unsafe { is_worker() };
    }

    // Fallback: TLS variable present in older parallel versions.
    let tls_sym = unsafe {
        libc::dlsym(
            handle,
            b"php_parallel_scheduler_context\0".as_ptr() as *const c_char,
        )
    };
    if tls_sym.is_null() {
        return false;
    }

    // Resolve the per-thread TLS address, then check whether it's non-null.
    // A non-null context means PHP initialised this thread as a parallel worker.
    let tls_ptr = resolve_tls_ptr(tls_sym);
    if tls_ptr.is_null() {
        return false;
    }
    unsafe { !(*tls_ptr).is_null() }
}

/// Resolve a TLS symbol address to the per-thread pointer.
///
/// On macOS, `dlsym` returns a *descriptor* (thunk + metadata) rather than
/// the variable's address. We must call the thunk to get the real address.
/// See dyld source and https://developer.apple.com/library/archive/.
///
/// On Linux (glibc and musl), `dlsym` handles STT_TLS symbols internally and
/// returns the already-resolved per-thread address.
fn resolve_tls_ptr(sym: *mut c_void) -> *const *mut c_void {
    #[cfg(target_os = "macos")]
    {
        /// Layout of the TLS descriptor returned by dlsym on macOS.
        #[repr(C)]
        struct TlsDescriptor {
            thunk: Option<unsafe extern "C" fn(*mut c_void) -> *mut c_void>,
            key: usize,
            offset: usize,
        }
        let desc = sym as *mut TlsDescriptor;
        match unsafe { (*desc).thunk } {
            None => core::ptr::null(),
            Some(thunk) => unsafe { thunk(sym) as *const *mut c_void },
        }
    }
    #[cfg(not(target_os = "macos"))]
    {
        sym as *const *mut c_void
    }
}

thread_local! {
    /// This is a cache for the thread name. It will not change after the thread has been
    /// created, as SAPI's do not change thread names and ext-pthreads / ext-parallel do not
    /// provide an interface for renaming a thread.
    static THREAD_NAME: OnceCell<String> = const { OnceCell::new() };
}

pub fn get_current_thread_name(php_thread: crate::OnPhpThread) -> String {
    THREAD_NAME.with(|name| {
        name.get_or_init(|| -> String {
            if !crate::matrix_entry().is_zts() {
                return SAPI.to_string();
            }
            if is_parallel_thread(php_thread) {
                return "parallel worker".to_string();
            }
            let mut thread_name = SAPI.to_string();
            // So far, only FrankenPHP sets meaningful thread names
            if *SAPI == Sapi::FrankenPHP {
                let mut buf = [0u8; 32];
                let result = unsafe {
                    libc::pthread_getname_np(
                        libc::pthread_self(),
                        buf.as_mut_ptr() as *mut c_char,
                        buf.len(),
                    )
                };
                if result == 0 {
                    let cstr = unsafe { std::ffi::CStr::from_ptr(buf.as_ptr() as *const c_char) };
                    let str_slice = cstr.to_str().unwrap_or_default();
                    if !str_slice.is_empty() {
                        thread_name.push_str(": ");
                        thread_name.push_str(str_slice);
                    }
                }
            }
            thread_name
        })
        .clone()
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use libc::c_char;

    #[test]
    fn test_get_current_thread_name() {
        crate::init_matrix_for_tests();
        unsafe {
            // When running `cargo test`, the thread name for this test will be set to
            // `profiling::thread_utils::tests:` which would interfer with this test
            libc::pthread_setname_np(
                #[cfg(target_os = "linux")]
                libc::pthread_self(),
                c"".as_ptr() as *const c_char,
            );
        }
        crate::ON_PHP_THREAD_ACTIVE.with(|b| b.set(true));
        // SAFETY: test runs in a PHP-like context; ON_PHP_THREAD_ACTIVE set above.
        assert_eq!(
            get_current_thread_name(unsafe { crate::OnPhpThread::new() }),
            "unknown"
        );
        crate::ON_PHP_THREAD_ACTIVE.with(|b| b.set(false));
    }
}
