#[cfg(feature = "allocation_profiling")]
use crate::allocation::alloc_prof_rshutdown;
use crate::bindings::{
    datadog_php_install_handler, datadog_php_zif_handler, zend_execute_data, zend_long, zval,
    InternalFunctionHandler,
};
use crate::{config, Profiler};
use ddcommon::cstr;
use log::{error, warn};
use std::ffi::CStr;
use std::ptr;

static mut PCNTL_FORK_HANDLER: InternalFunctionHandler = None;
static mut PCNTL_RFORK_HANDLER: InternalFunctionHandler = None;
static mut PCNTL_FORKX_HANDLER: InternalFunctionHandler = None;

enum ForkId {
    Child,
    Parent,
}

enum ForkError {
    NullRetval,
    ZvalType(u8),
}

fn dispatch(
    handler: unsafe extern "C" fn(*mut zend_execute_data, *mut zval),
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
) -> Result<ForkId, ForkError> {
    // Safety: we're calling the original handler with correct args.
    unsafe { handler(execute_data, return_value) };

    if return_value.is_null() {
        // This _shouldn't_ ever be hit.
        Err(ForkError::NullRetval)
    } else {
        // Safety: we checked it wasn't null above.
        let result: Result<zend_long, _> = unsafe { &mut *return_value }.try_into();
        match result {
            Err(r#type) => Err(ForkError::ZvalType(r#type)),
            Ok(pid) => {
                // The child gets pid of 0.
                if pid == 0 {
                    Ok(ForkId::Child)
                } else {
                    Ok(ForkId::Parent)
                }
            }
        }
    }
}

#[cold]
#[inline(never)]
unsafe fn handle_fork(
    handler: unsafe extern "C" fn(*mut zend_execute_data, *mut zval),
    execute_data: *mut zend_execute_data,
    return_value: *mut zval,
) {
    // Hold mutexes across the handler. If there are any spurious wakeups by
    // the threads while the fork is occurring, they cannot acquire locks
    // since this thread holds them, preventing a deadlock situation.
    if let Some(profiler) = Profiler::get() {
        let _ = profiler.fork_prepare();
    }

    match dispatch(handler, execute_data, return_value) {
        Ok(ForkId::Parent) => {
            if let Some(profiler) = Profiler::get() {
                profiler.post_fork_parent();
            }
            return;
        }
        Ok(ForkId::Child) => { /* fallthrough */ }
        Err(ForkError::NullRetval) => {
            // Skip error message if no profiler.
            if Profiler::get().is_some() {
                error!(
                    "Failed to read return value of forking function. A crash or deadlock may occur."
                );
            }
            // fallthrough
        }

        Err(ForkError::ZvalType(r#type)) => {
            // Skip error message if no profiler.
            if Profiler::get().is_some() {
                warn!(
                    "Return type of forking function was unexpected: {type}. A crash or deadlock may occur."
                );
            }
            // fallthrough
        }
    }

    // Disable the profiler because either:
    //  1. This is the child, and we don't support this yet.
    //  2. Something went wrong, and disable it to be safe.
    // And then leak the old profiler. Its drop method is not safe to run in
    // these situations.
    Profiler::kill();

    #[cfg(feature = "allocation_profiling")]
    alloc_prof_rshutdown();

    // Reset some global state to prevent further profiling and to not handle
    // any pending interrupts.
    // SAFETY: done after config is used to shut down other things, and in a
    //         thread-safe context.
    config::on_fork_in_child();
}

unsafe extern "C" fn fork(execute_data: *mut zend_execute_data, return_value: *mut zval) {
    // Safety: this function is only called if PCNTL_FORK_HANDLER was set.
    let handler = PCNTL_FORK_HANDLER.unwrap();
    handle_fork(handler, execute_data, return_value);
}

unsafe extern "C" fn rfork(execute_data: *mut zend_execute_data, return_value: *mut zval) {
    // Safety: this function is only called if PCNTL_RFORK_HANDLER was set.
    let handler = PCNTL_RFORK_HANDLER.unwrap();
    handle_fork(handler, execute_data, return_value);
}

unsafe extern "C" fn forkx(execute_data: *mut zend_execute_data, return_value: *mut zval) {
    // Safety: this function is only called if PCNTL_FORKX_HANDLER was set.
    let handler = PCNTL_FORKX_HANDLER.unwrap();
    handle_fork(handler, execute_data, return_value);
}

const PCNTL_FORK: &CStr = cstr!("pcntl_fork");
const PCNTL_RFORK: &CStr = cstr!("pcntl_rfork");
const PCNTL_FORKX: &CStr = cstr!("pcntl_forkx");

/// # Safety
/// Only call this from zend_extension's startup function. It's not designed
/// to be called from anywhere else.
pub(crate) unsafe fn startup() {
    // Some of these handlers exist only on recent PHP versions, but since
    // datadog_php_install_handler fails gracefully on a missing function, we
    // don't have to worry about creating Rust configurations for them.
    // Safety: we can modify our own globals in the startup context.
    let handlers = [
        datadog_php_zif_handler::new(
            PCNTL_FORK,
            ptr::addr_of_mut!(PCNTL_FORK_HANDLER),
            Some(fork),
        ),
        datadog_php_zif_handler::new(
            PCNTL_RFORK,
            ptr::addr_of_mut!(PCNTL_RFORK_HANDLER),
            Some(rfork),
        ),
        datadog_php_zif_handler::new(
            PCNTL_FORKX,
            ptr::addr_of_mut!(PCNTL_FORKX_HANDLER),
            Some(forkx),
        ),
    ];

    for handler in handlers.into_iter() {
        // Safety: we've set all the parameters correctly for this C call.
        datadog_php_install_handler(handler);
    }
}
