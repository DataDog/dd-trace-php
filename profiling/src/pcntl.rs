use crate::bindings::{
    datadog_php_install_handler, datadog_php_zif_handler, zend_execute_data, zend_long, zval,
    InternalFunctionHandler,
};
use crate::{Profiler, PROFILER, REQUEST_LOCALS};
use log::{error, warn};
use std::ffi::CStr;
use std::mem::{forget, swap};
use std::sync::atomic::Ordering;

static mut PCNTL_FORK_HANDLER: InternalFunctionHandler = None;
static mut PCNTL_RFORK_HANDLER: InternalFunctionHandler = None;
static mut PCNTL_FORKX_HANDLER: InternalFunctionHandler = None;

fn handle_pcntl_fork(
    name: &str,
    handler: unsafe extern "C" fn(*mut zend_execute_data, *mut zval),
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
) {
    /* Hold mutexes across the handler. If there are any spurious wakeups by
     * the threads while the fork is occurring, they cannot acquire locks
     * since this thread holds them, preventing a deadlock situation.
     */
    let mut profiler_lock = PROFILER.lock().unwrap();
    if let Some(profiler) = profiler_lock.as_ref() {
        profiler.fork_prepare();
    }

    // Safety: we're calling the original handler with correct args.
    unsafe { handler(execute_data, return_value) };

    if return_value.is_null() {
        // This _shouldn't_ ever be hit.

        if profiler_lock.is_some() {
            error!(
                "Failed to read return value of {}. A crash or deadlock may occur.",
                name
            );
        }

        /* We don't know if this is actually the parent or not, so do our best
         * to to prevent further profiling which could cause a crash/deadlock.
         */

        stop_and_forget_profiling(&mut profiler_lock);
    } else {
        // Safety: we checked it wasn't null above.
        let result: Result<zend_long, _> = unsafe { &mut *return_value }.try_into();
        match result {
            Err(r#type) => {
                warn!(
                    "Return type of {} was unexpected: {}. A crash or deadlock may occur.",
                    name, r#type
                );

                stop_and_forget_profiling(&mut profiler_lock);
            }
            Ok(pid) => {
                // The child gets pid of 0. For now, stop profiling for safety.
                if pid == 0 {
                    stop_and_forget_profiling(&mut profiler_lock);

                    /* When we fully support forking remember:
                     *  - Reset last_cpu_time and last_wall_time.
                     *  - Reset process_id and runtime-id tags.
                     */
                } else {
                    /* If it's negative, then no child process was made so we must be the parent.
                     * If it's positive then this is definitely the parent process.
                     */
                    if let Some(profiler) = profiler_lock.as_ref() {
                        profiler.post_fork_parent();
                    }
                }
            }
        }
    }
}

fn stop_and_forget_profiling(maybe_profiler: &mut Option<Profiler>) {
    /* In a forking situation, the currently active profiler may not be valid
     * because it has join handles and other state shared by other threads,
     * and threads are not copied when the process is forked.
     * Additionally, if we've hit certain other issues like not being able to
     * determine the return type of the pcntl_fork function, we don't know if
     * we're the parent or child.
     * So, we throw away the current profiler by swapping it with a None, and
     * and forgetting it, which avoids running the destructor. Yes, this will
     * most likely leak some small amount of memory.
     */
    let mut old_profiler = None;
    swap(&mut *maybe_profiler, &mut old_profiler);
    forget(old_profiler);

    /* Reset some global state to prevent further profiling and to not handle
     * any pending interrupts.
     */
    REQUEST_LOCALS.with(|cell| {
        let mut locals = cell.borrow_mut();
        locals.profiling_enabled = false;
        locals.cpu_samples.store(0, Ordering::SeqCst);
        locals.wall_samples.store(0, Ordering::SeqCst);
    });
}

unsafe extern "C" fn pcntl_fork(execute_data: *mut zend_execute_data, return_value: *mut zval) {
    // Safety: this function is only called if PCNTL_FORK_HANDLER was set.
    let handler = PCNTL_FORK_HANDLER.unwrap();
    handle_pcntl_fork("pcntl_fork", handler, execute_data, return_value);
}

unsafe extern "C" fn pcntl_rfork(execute_data: *mut zend_execute_data, return_value: *mut zval) {
    // Safety: this function is only called if PCNTL_RFORK_HANDLER was set.
    let handler = PCNTL_RFORK_HANDLER.unwrap();
    handle_pcntl_fork("pcntl_rfork", handler, execute_data, return_value);
}

unsafe extern "C" fn pcntl_forkx(execute_data: *mut zend_execute_data, return_value: *mut zval) {
    // Safety: this function is only called if PCNTL_FORKX_HANDLER was set.
    let handler = PCNTL_FORKX_HANDLER.unwrap();
    handle_pcntl_fork("pcntl_forkx", handler, execute_data, return_value);
}

// Safety: the provided slices are nul-terminated and don't contain any interior nul bytes.
const PCNTL_FORK: &CStr = unsafe { CStr::from_bytes_with_nul_unchecked(b"pcntl_fork\0") };
const PCNTL_RFORK: &CStr = unsafe { CStr::from_bytes_with_nul_unchecked(b"pcntl_rfork\0") };
const PCNTL_FORKX: &CStr = unsafe { CStr::from_bytes_with_nul_unchecked(b"pcntl_forkx\0") };

/// # Safety
/// Only call this from zend_extension's startup function. It's not designed
/// to be called from anywhere else.
pub(crate) unsafe fn startup() {
    /* Some of these handlers exist only on recent PHP versions, but since
     * datadog_php_install_handler fails gracefully on a missing function, we
     * don't have to worry about creating Rust configurations for them.
     * Safety: we can modify our own globals in the startup context.
     */
    let handlers = [
        datadog_php_zif_handler::new(PCNTL_FORK, &mut PCNTL_FORK_HANDLER, Some(pcntl_fork)),
        datadog_php_zif_handler::new(PCNTL_RFORK, &mut PCNTL_RFORK_HANDLER, Some(pcntl_rfork)),
        datadog_php_zif_handler::new(PCNTL_FORKX, &mut PCNTL_FORKX_HANDLER, Some(pcntl_forkx)),
    ];

    for handler in handlers.into_iter() {
        // Safety: we've set all the parameters correctly for this C call.
        datadog_php_install_handler(handler);
    }
}
