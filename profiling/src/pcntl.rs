use crate::bindings::{
    datadog_php_install_handler, datadog_php_zif_handler, zend_execute_data, zend_long, zval,
    InternalFunctionHandler,
};
use crate::{RequestLocals, PROFILER, REQUEST_LOCALS};
use log::{error, warn};
use std::cell::RefMut;
use std::ffi::CStr;
use std::mem::{forget, swap};

static mut PCNTL_FORK_HANDLER: InternalFunctionHandler = None;
static mut PCNTL_RFORK_HANDLER: InternalFunctionHandler = None;
static mut PCNTL_FORKX_HANDLER: InternalFunctionHandler = None;

fn handle_child_fork(mut locals: RefMut<RequestLocals>, retval: &mut zval) {
    let result: Result<zend_long, _> = retval.try_into();
    match result {
        Ok(pid) => {
            // The child gets pid of 0. For now, stop profiling for safety.
            if pid == 0 {
                match PROFILER.lock() {
                    Ok(mut profiler) => {
                        let mut new_profiler = None;
                        swap(&mut *profiler, &mut new_profiler);

                        /* Due to the swap, this has the old profiler.
                         * Forget about it, it has garbage in it.
                         */
                        forget(new_profiler);
                        locals.profiling_enabled = false;

                        /* When we fully support forking remember:
                         *  - Reset last_cpu_time and last_wall_time
                         *  - Reset process_id and runtime-id tags.
                         */
                    }
                    Err(err) => {
                        error!(
                            "Forked child failed to acquire profiler lock; a crash is likely: {}",
                            err
                        )
                    }
                }
            }
        }
        Err(r#type) => {
            warn!(
                "Return type of pcntl_*fork* function was unexpected: {}",
                r#type
            )
        }
    }
}

unsafe fn handle_pcntl_fork(return_value: *mut zval) {
    /* This hook is installed prior to knowing if the profiler will be enabled,
     * so we must guard it with a runtime check.
     */
    REQUEST_LOCALS.with(|cell| {
        let locals = cell.borrow_mut();
        if locals.profiling_enabled && !return_value.is_null() {
            /* Safety: we just checked it's not null; if any other invariants
             * are messed up then we're totally hosed already.
             */
            handle_child_fork(locals, &mut *return_value);
        }
    });
}

unsafe extern "C" fn pcntl_fork(execute_data: *mut zend_execute_data, return_value: *mut zval) {
    // Safety: this function is only called if PCNTL_FORK_HANDLER was set.
    let handler = PCNTL_FORK_HANDLER.unwrap();
    handler(execute_data, return_value);
    handle_pcntl_fork(return_value);
}

unsafe extern "C" fn pcntl_rfork(execute_data: *mut zend_execute_data, return_value: *mut zval) {
    // Safety: this function is only called if PCNTL_RFORK_HANDLER was set.
    let handler = PCNTL_RFORK_HANDLER.unwrap();
    handler(execute_data, return_value);
    handle_pcntl_fork(return_value);
}

unsafe extern "C" fn pcntl_forkx(execute_data: *mut zend_execute_data, return_value: *mut zval) {
    // Safety: this function is only called if PCNTL_FORKX_HANDLER was set.
    let handler = PCNTL_FORKX_HANDLER.unwrap();
    handler(execute_data, return_value);
    handle_pcntl_fork(return_value);
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
