//! This module has code related to generating wall-time profiles. Due to
//! implementation reasons, it has cpu-time code as well.

use crate::bindings::{
    zend_execute_data, zend_execute_internal, zend_interrupt_function, zval, VmInterruptFn,
    ZEND_ACC_CALL_VIA_TRAMPOLINE,
};
use crate::{profiling::Profiler, zend, REQUEST_LOCALS};
use std::mem::MaybeUninit;
use std::sync::atomic::Ordering;

/// The engine's previous [zend::zend_execute_internal] value, or
/// [zend::execute_internal] if none. This is a highly active path, so although
/// it could be made safe with Mutex, the cost is too high.
static mut PREV_EXECUTE_INTERNAL: MaybeUninit<
    unsafe extern "C" fn(execute_data: *mut zend_execute_data, return_value: *mut zval),
> = MaybeUninit::uninit();

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

        if let Some(profiler) = Profiler::get() {
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

/// Returns true if the func tied to the execute_data is a trampoline.
/// # Safety
/// This is only safe to execute _before_ executing the trampoline, because the trampoline may
/// free the `execute_data.func` _without_ setting it to NULL:
/// https://heap.space/xref/PHP-8.2/Zend/zend_closures.c?r=af2110e6#60-63
/// So no code can inspect the func after the call has been made, which is why you would call this function: find out before you
/// call the function if indeed you need to skip certain code after it has been executed.
unsafe fn execute_data_func_is_trampoline(execute_data: *const zend_execute_data) -> bool {
    if execute_data.is_null() {
        return false;
    }

    if (*execute_data).func.is_null() {
        return false;
    }
    ((*(*execute_data).func).common.fn_flags & ZEND_ACC_CALL_VIA_TRAMPOLINE) != 0
}

/// Overrides the engine's zend_execute_internal hook in order to process pending VM interrupts
/// while the internal function is still on top of the call stack. The VM does not process the
/// interrupt until the call returns so that it could theoretically jump to a different opcode,
/// like a fiber scheduler.
/// For our particular case this is problematic. For example, when the user does something like
/// `sleep(seconds: 10)`, the normal interrupt handling will not trigger until sleep returns, so
/// we'd then attribute all that time spent sleeping to whatever runs next. This is why we intercept
/// `zend_execute_internal` and process our own VM interrupts, but it doesn't delegate to the
/// previous VM interrupt hook, as it's not expecting to be called from this state.
pub extern "C" fn execute_internal(execute_data: *mut zend_execute_data, return_value: *mut zval) {
    // SAFETY: called before executing the trampoline.
    let leaf_frame = if unsafe { execute_data_func_is_trampoline(execute_data) } {
        // SAFETY: if is_trampoline is set, then there must be a valid execute_data.
        unsafe { *execute_data }.prev_execute_data
    } else {
        execute_data
    };

    // SAFETY: PREV_EXECUTE_INTERNAL was written during minit, doesn't change during runtime.
    let prev_execute_internal = unsafe { *PREV_EXECUTE_INTERNAL.as_mut_ptr() };

    // SAFETY: calling prev_execute without modification will be safe.
    unsafe { prev_execute_internal(execute_data, return_value) };

    // See safety section of `execute_data_func_is_trampoline` docs for why the leaf frame is used
    // instead of the execute_data ptr.
    ddog_php_prof_interrupt_function(leaf_frame);
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

    PREV_EXECUTE_INTERNAL.write(zend_execute_internal.unwrap_or(zend::execute_internal));
    zend_execute_internal = Some(execute_internal);
}
