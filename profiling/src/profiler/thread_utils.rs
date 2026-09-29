use crate::profiling::SAPI;
use std::cell::OnceCell;
use std::mem::MaybeUninit;
use std::thread::JoinHandle;
use std::time::{Duration, Instant};

#[cfg(php_zts)]
use crate::profiling::bindings::ddog_php_prof_is_parallel_thread;
#[cfg(php_zts)]
use crate::profiling::sapi::Sapi;
#[cfg(php_zts)]
use libc::c_char;

/// Spawns a thread with asynchronous signals masked.
pub fn spawn<F, T>(name: &str, f: F) -> JoinHandle<T>
where
    F: FnOnce() -> T + Send + 'static,
    T: Send + 'static,
{
    /* This helper thread has no valid PHP/TSRM context, so it must not run
     * any PHP signal handler. Block asynchronous signals in the parent before
     * spawning: the new thread inherits the mask atomically with its creation.
     * Masking only inside the new thread leaves a window in which PHP can
     * install and trigger an asynchronous handler before the helper runs.
     *
     * Synchronous fault signals remain unblocked so a genuine fault on the
     * helper thread is still reported, for example by the crashtracker.
     */
    let mut sigset_mem = MaybeUninit::uninit();
    let mut previous_mask_mem = MaybeUninit::uninit();
    let sigset = sigset_mem.as_mut_ptr();

    // SAFETY: both signal sets point to valid, suitably aligned storage.
    let mask_result = unsafe {
        libc::sigfillset(sigset);

        const KEEP_UNBLOCKED: [libc::c_int; 6] = [
            libc::SIGSEGV,
            libc::SIGBUS,
            libc::SIGFPE,
            libc::SIGILL,
            libc::SIGABRT,
            libc::SIGTRAP,
        ];

        for signal in KEEP_UNBLOCKED {
            libc::sigdelset(sigset, signal);
        }

        libc::pthread_sigmask(libc::SIG_BLOCK, sigset, previous_mask_mem.as_mut_ptr())
    };
    assert_eq!(
        mask_result, 0,
        "failed to block signals before spawning {name}"
    );

    let result = std::thread::Builder::new().name(name.to_string()).spawn(f);

    // SAFETY: pthread_sigmask initialized previous_mask_mem above, and this
    // restores the spawning PHP thread regardless of whether spawn succeeded.
    let restore_result = unsafe {
        libc::pthread_sigmask(
            libc::SIG_SETMASK,
            previous_mask_mem.as_ptr(),
            std::ptr::null_mut(),
        )
    };
    assert_eq!(
        restore_result, 0,
        "failed to restore signal mask after spawning {name}"
    );

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

const JOIN_POLL_INTERVAL: Duration = Duration::from_millis(100);

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
    // cause. So, for now, mitigate it instead with a loop.
    let start = Instant::now();
    while !handle.is_finished() {
        let elapsed = start.elapsed();
        if elapsed >= timeout {
            let thread = handle.thread().name().unwrap_or("{unknown}").to_string();
            let timeout_ms = timeout.as_millis();
            return Err(TimeoutError { thread, timeout_ms });
        }

        let remaining = timeout.saturating_sub(elapsed);
        std::thread::sleep(std::cmp::min(JOIN_POLL_INTERVAL, remaining));
    }

    if let Err(err) = handle.join() {
        std::panic::resume_unwind(err);
    }
    Ok(())
}

thread_local! {
    /// This is a cache for the thread name. It will not change after the thread has been
    /// created, as SAPI's do not change thread names and ext-pthreads / ext-parallel do not
    /// provide an interface for renaming a thread.
    static THREAD_NAME: OnceCell<String> = const { OnceCell::new() };
}

pub fn get_current_thread_name() -> String {
    THREAD_NAME.with(|name| {
        name.get_or_init(|| -> String {
            #[cfg(not(php_zts))]
            return SAPI.to_string();

            #[cfg(php_zts)]
            {
                if unsafe { ddog_php_prof_is_parallel_thread() } {
                    return "parallel worker".to_string();
                }
                let mut thread_name = SAPI.to_string();
                // So far, only FrankenPHP sets meaningful thread names
                if *SAPI == Sapi::FrankenPHP {
                    let mut name = [0u8; 32];

                    let result = unsafe {
                        libc::pthread_getname_np(
                            libc::pthread_self(),
                            name.as_mut_ptr() as *mut c_char,
                            name.len(),
                        )
                    };

                    if result == 0 {
                        // If successful, convert the result to a Rust String
                        let cstr =
                            unsafe { std::ffi::CStr::from_ptr(name.as_ptr() as *const c_char) };
                        let str_slice: &str = cstr.to_str().unwrap_or_default();
                        if !str_slice.is_empty() {
                            thread_name.push_str(": ");
                            thread_name.push_str(str_slice);
                        }
                    }
                }
                thread_name
            }
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
        unsafe {
            // When running `cargo test`, the thread name for this test will be set to
            // `profiling::thread_utils::tests:` which would interfer with this test
            libc::pthread_setname_np(
                #[cfg(target_os = "linux")]
                libc::pthread_self(),
                c"".as_ptr() as *const c_char,
            );
        }
        assert_eq!(get_current_thread_name(), "unknown");
    }
}
