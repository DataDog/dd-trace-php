//! This module has code related to generating wall-time profiles. Due to
//! implementation reasons, it has cpu-time code as well.

use crate::bindings::{zend_execute_data, zend_interrupt_function, VmInterruptFn};
use crate::{profiling::Profiler, RefCellExt, REQUEST_LOCALS};
use core::ptr;
use log::debug;
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
    let result = REQUEST_LOCALS.try_with_borrow(|locals| {
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

    if let Err(err) = result {
        debug!("ddog_php_prof_interrupt_function failed to borrow request locals: {err}");
    }
}

#[cfg(php_frameless)]
mod frameless {
    #[cfg(any(target_arch = "x86_64", target_arch = "aarch64"))]
    mod trampoline {
        #[cfg(target_arch = "aarch64")]
        use dynasmrt::aarch64::Assembler;
        #[cfg(target_arch = "aarch64")]
        use dynasmrt::DynasmLabelApi;
        #[cfg(target_arch = "x86_64")]
        use dynasmrt::x64::Assembler;
        use dynasmrt::{dynasm, DynasmApi, ExecutableBuffer};
        use std::ffi::c_void;
        use std::sync::atomic::Ordering;
        use log::error;
        use crate::bindings::{zend_flf_functions, zend_flf_handlers, zend_frameless_function_info};
        use crate::{profiling::Profiler, RefCellExt, REQUEST_LOCALS, zend};

        // This ensures that the memory stays reachable and is replaced on apache reload for example
        static mut INFOS: Vec<zend_frameless_function_info> = Vec::new();
        static mut BUFFER: Option<ExecutableBuffer> = None;

        pub unsafe fn install() {
            // Collect frameless functions ahead of time to batch-process them.
            // Otherwise we get a new memory page per function.
            let mut originals = Vec::new();
            let mut i = 0;
            loop {
                let original = *zend_flf_handlers.add(i);
                if original.is_null() {
                    break;
                }
                originals.push(original);
                i += 1;
            }

            let mut assembler = match Assembler::new() {
                Ok(assembler) => assembler,
                Err(e) => {
                    error!("Failed to create assembler for FLF trampolines: {e}. Frameless functions will not appear in wall-time profiles.");
                    return;
                }
            };
            let interrupt_addr = ddog_php_prof_icall_trampoline_target as *const ();
            let mut offsets = Vec::new();  // keep function offsets
            for orig in originals.iter() {
                offsets.push(assembler.offset());
                // Calls original function, then calls interrupt function.
                #[cfg(target_arch = "aarch64")]
                {
                    // We need labels on aarch64 as immediates cannot be more than 16 bits
                    dynasm!(assembler
                        ; stp x29, x30, [sp, -16]! // save link register and allow clobber of x29
                        ; mov x29, sp // store stack pointer
                        ; ldr x16, >orig_label
                        ; blr x16
                        ; ldp x29, x30, [sp], 16 // restore link register and x29
                        ; ldr x16, >interrupt_label
                        ; br x16  // tail call
                        ; orig_label: ; .qword *orig as i64
                    );
                }
                #[cfg(target_arch = "x86_64")]
                dynasm!(assembler
                    ; push rbp  // align stack
                    ; mov rax, QWORD *orig as i64
                    ; call rax
                    ; pop rbp  // restore stack
                    ; mov rax, QWORD interrupt_addr as i64
                    ; jmp rax  // tail call
                );
            }
            #[cfg(target_arch = "aarch64")]
            dynasm!(assembler
                ; interrupt_label: ; .qword interrupt_addr as i64 );

            // Allocate enough space for all frameless_function_infos including trailing NULLs
            let mut infos = Vec::with_capacity(originals.len() * 2);

            let buffer = match assembler.finalize() {
                Ok(buffer) => buffer,
                Err(_) => {
                    error!("Failed to finalize FLF trampolines (mprotect PROT_EXEC denied?). Frameless functions will not appear in cpu/wall-time profiles. This may be caused by security policies (SELinux, seccomp, etc.).");
                    return;
                }
            };
            let mut last_infos = std::ptr::null_mut();
            for (i, offset) in offsets.iter().enumerate() {
                let wrapper = buffer.as_ptr().add(offset.0) as *mut c_void;
                *zend_flf_handlers.add(i) = wrapper;
                let func = &mut **zend_flf_functions.add(i);

                // We need to do copies of frameless_function_infos as they may be readonly memory
                let original_info = func.internal_function.frameless_function_infos;
                if original_info != last_infos {
                    let info_size = infos.len();
                    let mut ptr = original_info;
                    loop {
                        let info = *ptr;
                        infos.push(info);
                        if info.handler.is_null() {
                            break;
                        }
                        ptr = ptr.add(1);
                    }
                    last_infos = infos.as_ptr().add(info_size) as *mut _;
                    func.internal_function.frameless_function_infos = last_infos;
                }
                let mut ptr = last_infos;
                loop {
                    let info = &mut *ptr;
                    if info.handler.is_null() {
                        break;
                    }
                    if info.handler == originals[i] {
                        info.handler = wrapper;
                    }
                    ptr = ptr.add(1);
                }
            }

            INFOS = infos;
            BUFFER = Some(buffer);
        }

        #[no_mangle]
        #[inline(never)]
        pub extern "C" fn ddog_php_prof_icall_trampoline_target() {
            let interrupt_count = REQUEST_LOCALS
                .try_with_borrow(|locals| {
                    if !locals.system_settings().profiling_enabled {
                        return 0;
                    }
                    locals.interrupt_count.swap(0, Ordering::SeqCst)
                })
                .unwrap_or(0);

            if interrupt_count == 0 {
                return;
            }

            if let Some(profiler) = Profiler::get() {
                // SAFETY: profiler doesn't mutate execute_data
                let execute_data = unsafe { zend::ddog_php_prof_get_current_execute_data() };
                profiler.collect_time(execute_data, interrupt_count);
            }
        }
    }

    #[no_mangle]
    pub unsafe extern "C" fn ddog_php_prof_post_startup() {
        #[cfg(any(target_arch = "x86_64", target_arch = "aarch64"))]
        trampoline::install();
    }

    #[cfg(test)]
    mod tests {
        use crate::bindings::zend_function;

        #[no_mangle]
        pub static mut zend_flf_functions: *mut *mut zend_function = std::ptr::null_mut();
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
