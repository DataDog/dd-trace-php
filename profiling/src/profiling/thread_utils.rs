use libc::{c_char, sched_yield};
use log::warn;
use std::mem::MaybeUninit;
use std::thread::JoinHandle;
use std::time::{Duration, Instant};

use crate::SAPI;

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

/// Waits for the handle to be finished. If finished, it will join the handle.
/// Otherwise, it will leak the handle.
/// # Panics
/// Panics if the thread being joined has panic'd.
pub fn join_timeout(handle: JoinHandle<()>, timeout: Duration, impact: &str) {
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
            let name = handle.thread().name().unwrap_or("{unknown}");
            warn!("Timeout of {timeout:?} reached when joining thread '{name}'. {impact}");
            return;
        }
    }

    if let Err(err) = handle.join() {
        std::panic::resume_unwind(err)
    }
}

pub fn get_current_thread_name() -> String {
    let mut name = [0u8; 32];

    let result = unsafe {
        libc::pthread_getname_np(
            libc::pthread_self(),
            name.as_mut_ptr() as *mut c_char,
            name.len(),
        )
    };

    let mut thread_name = SAPI.to_string();

    if result == 0 {
        // If successful, convert the result to a Rust String
        let cstr = unsafe { std::ffi::CStr::from_ptr(name.as_ptr() as *const c_char) };
        let str_slice: &str = cstr.to_str().unwrap();
        if str_slice.len() > 0 {
            thread_name.push_str(": ");
            thread_name.push_str(str_slice);
        }
    }

    return thread_name;
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_get_current_thread_name() {
        unsafe {
            // When running `cargo test`, the thread name for this test will be set to
            // `profiling::thread_utils::tests:` which would interfer with this test
            libc::pthread_setname_np(
                #[cfg(target_os = "linux")]
                libc::pthread_self(),
                b"\0".as_ptr() as *const c_char,
            );
        }
        assert_eq!(get_current_thread_name(), "unknown");

        unsafe {
            libc::pthread_setname_np(
                #[cfg(target_os = "linux")]
                libc::pthread_self(),
                b"foo\0".as_ptr() as *const c_char,
            );
        }
        assert_eq!(get_current_thread_name(), "unknown: foo");

        unsafe {
            libc::pthread_setname_np(
                #[cfg(target_os = "linux")]
                libc::pthread_self(),
                b"bar\0".as_ptr() as *const c_char,
            );
        }
        assert_eq!(get_current_thread_name(), "unknown: bar");
    }
}
