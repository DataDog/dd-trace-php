//! This module has code related to generating wall-time profiles. Due to
//! implementation reasons, it has cpu-time code as well.

use crate::bindings::{zend_execute_data, zend_interrupt_function, VmInterruptFn};
use crate::{allocation, profiling::Profiler, REQUEST_LOCALS};
use core::ptr;
use std::sync::atomic::Ordering;

#[cfg(not(php_frameless))]
mod execute_internal {
    use super::*;
    use crate::zend;
    use std::mem::MaybeUninit;
    use zend::{zend_execute_internal, zval, ZEND_ACC_CALL_VIA_TRAMPOLINE};

    /// The engine's previous [zend::zend_execute_internal] value, or
    /// [zend::execute_internal] if none. This is a highly active path, so
    /// although it could be made safe with Mutex, the cost is too high.
    static mut PREV_EXECUTE_INTERNAL: MaybeUninit<
        unsafe extern "C" fn(execute_data: *mut zend_execute_data, return_value: *mut zval),
    > = MaybeUninit::uninit();

    /// Returns true if the func tied to the execute_data is a trampoline.
    /// # Safety
    /// This is only safe to execute _before_ executing the trampoline, because
    /// the trampoline may  free the `execute_data.func` _without_ setting it
    /// to NULL:
    /// https://heap.space/xref/PHP-8.2/Zend/zend_closures.c?r=af2110e6#60-63
    /// So no code can inspect the func after the call has been made, which is
    /// why you would call this function: find out before you call the function
    /// if indeed you need to skip certain code after it has been executed.
    unsafe fn execute_data_func_is_trampoline(execute_data: *const zend_execute_data) -> bool {
        if execute_data.is_null() {
            return false;
        }

        if (*execute_data).func.is_null() {
            return false;
        }
        ((*(*execute_data).func).common.fn_flags & ZEND_ACC_CALL_VIA_TRAMPOLINE) != 0
    }

    /// Overrides the engine's zend_execute_internal hook to process pending
    /// VM interrupts while the internal function is still on top of the call
    /// stack.
    ///
    /// Before PHP 8.4, the VM does not process the interrupt until the call
    /// returns so that it could theoretically jump to a different opcode,
    /// like a fiber scheduler. However, in practice, this hasn't been
    /// possible since PHP 8.0 when zend_call_function started calling
    /// zend_interrupt_function, while the internal frame is still on the call
    /// stack.
    ///
    /// Consider when the user does something like `sleep(seconds: 10)`. The
    /// normal interrupt handling will not trigger until sleep returns, so
    /// we'll attribute all the time spent sleeping to whatever runs next.
    /// This is why we intercept `zend_execute_internal` and process our own
    /// VM interrupts, but it doesn't delegate to the previous VM interrupt
    /// hook, as the interrupt hooks weren't expected to be called from this
    /// place.
    ///
    /// Levi changed this in 8.4: https://github.com/php/php-src/pull/14627,
    /// which is why this isn't needed on 8.4+.
    extern "C" fn execute_internal(execute_data: *mut zend_execute_data, return_value: *mut zval) {
        // SAFETY: called before executing the trampoline.
        let leaf_frame = if unsafe { execute_data_func_is_trampoline(execute_data) } {
            // SAFETY: if is_trampoline is set, then there must be a valid execute_data.
            unsafe { *execute_data }.prev_execute_data
        } else {
            execute_data
        };

        // SAFETY: PREV_EXECUTE_INTERNAL was written during minit, doesn't change during runtime.
        let prev_execute_internal =
            unsafe { (*ptr::addr_of_mut!(PREV_EXECUTE_INTERNAL)).assume_init_mut() };

        // SAFETY: calling prev_execute without modification will be safe.
        unsafe { prev_execute_internal(execute_data, return_value) };

        // See safety section of `execute_data_func_is_trampoline` docs for why
        // the leaf frame is used  instead of the execute_data ptr.
        ddog_php_prof_interrupt_function(leaf_frame);
    }

    /// # Safety
    /// Only call during extension MINIT.
    pub unsafe fn minit() {
        (*ptr::addr_of_mut!(PREV_EXECUTE_INTERNAL))
            .write(zend_execute_internal.unwrap_or(zend::execute_internal));
        zend_execute_internal = Some(execute_internal);
    }
}

/// The engine's previous `zend_interrupt_function` value, if there is one.
/// Note that because of things like Apache reload which call minit more than
/// once per process, this cannot be made into a OnceCell nor lazy_static.
static mut PREV_INTERRUPT_FUNCTION: Option<VmInterruptFn> = None;

/// Gathers a sample if one is pending
///
/// Exposed to the C API so the tracer can handle pending profiler interrupts
/// before calling a tracing closure from an internal function hook; if this
/// isn't done then the closure is erroneously at the top of the stack.
///
/// # Safety
/// The `execute_data` is provided by the engine, and the profiler doesn't mutate it.
#[no_mangle]
#[inline(never)]
pub extern "C" fn ddog_php_prof_interrupt_function(execute_data: *mut zend_execute_data) {
    // Other extensions/modules or the engine itself may trigger an interrupt, but given how
    // expensive it is to gather a stack trace, it should only be done if we triggered it
    // ourselves. So `wall_cpu_time_interrupt_count` and `allocation_interrupt_count` serve
    // dual purposes:
    // 1. Track how many wall/cpu time and/or allocation interrupts there were.
    // 2. Ensure we don't collect on someone else's interrupt.
    let (wall_cpu_time_interrupt_count, allocation_interrupt_count) =
        REQUEST_LOCALS.with_borrow(|locals| {
            if !locals.system_settings().profiling_enabled {
                // Profiler disabled, so just say we have no pending samples.
                return (0, 0);
            }
            return (
                locals
                    .wall_cpu_time_interrupt_count
                    .swap(0, Ordering::SeqCst),
                locals.allocation_interrupt_count.swap(0, Ordering::SeqCst),
            );
        });

    // Collect pending wall/cpu time
    if wall_cpu_time_interrupt_count > 0 {
        if let Some(profiler) = Profiler::get() {
            profiler.collect_time(execute_data, wall_cpu_time_interrupt_count);
        }
    }

    // Collect pending allocations
    if allocation_interrupt_count > 0 {
        allocation::collect_pending_allocations(execute_data, allocation_interrupt_count as i64);
    }
}

/// A wrapper for the `ddog_php_prof_interrupt_function` to call the
/// previous interrupt handler, if there was one.
#[no_mangle]
extern "C" fn ddog_php_prof_interrupt_function_wrapper(execute_data: *mut zend_execute_data) {
    ddog_php_prof_interrupt_function(execute_data);

    // SAFETY: PREV_INTERRUPT_FUNCTION was written during minit, doesn't change during runtime.
    if let Some(prev_interrupt) = unsafe { (*ptr::addr_of_mut!(PREV_INTERRUPT_FUNCTION)).as_ref() }
    {
        // SAFETY: calling the interrupt handler with correct args at right place.
        unsafe { prev_interrupt(execute_data) };
    }
}

/// # Safety
/// Only call during PHP's minit phase.
pub unsafe fn minit() {
    ptr::addr_of_mut!(PREV_INTERRUPT_FUNCTION).write(zend_interrupt_function);
    let interrupt_function = ptr::addr_of_mut!(zend_interrupt_function);
    let function = if interrupt_function.read().is_some() {
        ddog_php_prof_interrupt_function_wrapper
    } else {
        ddog_php_prof_interrupt_function
    };
    interrupt_function.write(Some(function));

    #[cfg(not(php_frameless))]
    execute_internal::minit();
}
