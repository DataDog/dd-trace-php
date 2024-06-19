//! This module has code related to generating wall-time profiles. Due to
//! implementation reasons, it has cpu-time code as well.

use crate::bindings::{zend_execute_data, zend_interrupt_function, VmInterruptFn};
use crate::{PROFILER, REQUEST_LOCALS};
use std::sync::atomic::Ordering;

/// The engine's previous `zend_interrupt_function` value, if there is one.
/// Note that because of things like Apache reload which call minit more than
/// once per process, this cannot be made into a OnceCell nor lazy_static.
static mut PREV_INTERRUPT_FUNCTION: Option<VmInterruptFn> = None;

/// Gathers a time sample if the configured period has elapsed.
///
/// Exposed to the C API so the tracer can handle pending profiler interrupts
/// before calling a tracing closure from an internal function hook; if this
/// isn't done then the closure is erroneously at the top of the stack.
///
/// # Safety
/// The zend_execute_data pointer should come from the engine to ensure it and
/// its sub-objects are valid.
#[no_mangle]
#[inline(never)]
pub extern "C" fn ddog_php_prof_interrupt_function(execute_data: *mut zend_execute_data) {
    REQUEST_LOCALS.with(|cell| {
        // try to borrow and bail out if not successful
        let Ok(locals) = cell.try_borrow() else {
            return;
        };

        if !locals.system_settings().profiling_enabled {
            return;
        }

        /* Other extensions/modules or the engine itself may trigger an
         * interrupt, but given how expensive it is to gather a stack trace,
         * it should only be done if we triggered it ourselves. So
         * interrupt_count serves dual purposes:
         *  1. Track how many interrupts there were.
         *  2. Ensure we don't collect on someone else's interrupt.
         */
        let interrupt_count = locals.interrupt_count.swap(0, Ordering::SeqCst);
        if interrupt_count == 0 {
            return;
        }

        if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
            // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
            profiler.collect_time(execute_data, interrupt_count);
        }
    });
}

/// A wrapper for the `ddog_php_prof_interrupt_function` to call the
/// previous interrupt handler, if there was one.
#[no_mangle]
extern "C" fn ddog_php_prof_interrupt_function_wrapper(execute_data: *mut zend_execute_data) {
    ddog_php_prof_interrupt_function(execute_data);

    // SAFETY: PREV_INTERRUPT_FUNCTION was written during minit, doesn't change during runtime.
    if let Some(prev_interrupt) = unsafe { PREV_INTERRUPT_FUNCTION.as_ref() } {
        // SAFETY: calling the interrupt handler with correct args at right place.
        unsafe { prev_interrupt(execute_data) };
    }
}

/// # Safety
/// Only call during PHP's minit phase.
pub unsafe fn minit() {
    PREV_INTERRUPT_FUNCTION = zend_interrupt_function;
    let function = if zend_interrupt_function.is_some() {
        ddog_php_prof_interrupt_function_wrapper
    } else {
        ddog_php_prof_interrupt_function
    };
    zend_interrupt_function = Some(function);
}
