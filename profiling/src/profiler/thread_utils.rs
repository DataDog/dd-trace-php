use crate::profiling::SAPI;
use std::thread::JoinHandle;
use std::time::{Duration, Instant};

#[cfg(php_zts)]
use {
    crate::profiling::{bindings::ddog_php_prof_is_parallel_thread, sapi::Sapi},
    libc::c_char,
};

/// Spawns a thread with asynchronous signals masked.
pub fn spawn<F, T>(name: &str, f: F) -> JoinHandle<T>
where
    F: FnOnce() -> T + Send + 'static,
    T: Send + 'static,
{
    crate::threads::spawn(name, f)
        .unwrap_or_else(|err| panic!("Failed to spawn thread {name}: {err}"))
}

#[derive(thiserror::Error, Debug)]
#[error("timeout of {timeout_ms} ms reached when joining thread {thread}")]
pub struct TimeoutError {
    thread: String,
    timeout_ms: u128,
}

const JOIN_POLL_MIN_INTERVAL: Duration = Duration::from_millis(1);
const JOIN_POLL_MAX_INTERVAL: Duration = Duration::from_millis(100);

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
    // Threads usually exit within a few ms of being signalled at shutdown, so
    // back off from a short poll instead of always sleeping the max interval.
    let start = Instant::now();
    let mut interval = JOIN_POLL_MIN_INTERVAL;
    while !handle.is_finished() {
        let elapsed = start.elapsed();
        if elapsed >= timeout {
            let thread = handle.thread().name().unwrap_or("{unknown}").to_string();
            let timeout_ms = timeout.as_millis();
            return Err(TimeoutError { thread, timeout_ms });
        }

        let remaining = timeout.saturating_sub(elapsed);
        std::thread::sleep(std::cmp::min(interval, remaining));
        interval = std::cmp::min(interval * 2, JOIN_POLL_MAX_INTERVAL);
    }

    if let Err(err) = handle.join() {
        std::panic::resume_unwind(err);
    }
    Ok(())
}

pub fn get_current_thread_name() -> String {
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
                let cstr = unsafe { std::ffi::CStr::from_ptr(name.as_ptr() as *const c_char) };
                let str_slice: &str = cstr.to_str().unwrap_or_default();
                if !str_slice.is_empty() {
                    thread_name.push_str(": ");
                    thread_name.push_str(str_slice);
                }
            }
        }
        thread_name
    }
}
