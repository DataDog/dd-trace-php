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

/// JIT trampoline generation for frameless function interception.
///
/// This module provides the core assembly generation for trampolines that:
/// 1. Call an "original" function
/// 2. Tail-call an "interrupt" function afterward
///
/// The trampoline sets up frame pointers for FP-based unwinding, and generates
/// .eh_frame DWARF CFI data for DWARF-based stack unwinding.
#[cfg(any(target_arch = "x86_64", target_arch = "aarch64"))]
pub(crate) mod jit_trampoline {
    #[cfg(target_arch = "aarch64")]
    use dynasmrt::aarch64::Assembler;
    #[cfg(target_arch = "x86_64")]
    use dynasmrt::x64::Assembler;
    #[cfg(target_arch = "aarch64")]
    use dynasmrt::DynasmLabelApi;
    use dynasmrt::{dynasm, DynasmApi};
    use gimli::write::{
        Address, CallFrameInstruction, CommonInformationEntry, EhFrame, EndianVec,
        FrameDescriptionEntry, FrameTable,
    };
    use gimli::{Encoding, Format, LittleEndian, Register};
    use log::error;
    use std::ffi::c_void;

    // DWARF register numbers
    #[cfg(target_arch = "x86_64")]
    const RSP: Register = Register(7);
    #[cfg(target_arch = "x86_64")]
    const RBP: Register = Register(6);
    #[cfg(target_arch = "x86_64")]
    const RIP: Register = Register(16);

    #[cfg(target_arch = "aarch64")]
    const X29: Register = Register(29);
    #[cfg(target_arch = "aarch64")]
    const X30: Register = Register(30);
    #[cfg(target_arch = "aarch64")]
    const SP: Register = Register(31);

    // Size of each trampoline in bytes (fixed, known at compile time)
    // x86_64: push rbp (1) + mov rbp,rsp (3) + mov rax,imm64 (10) + call rax (2) +
    //         pop rbp (1) + mov rax,imm64 (10) + jmp rax (2) = 29 bytes
    #[cfg(target_arch = "x86_64")]
    const TRAMPOLINE_SIZE: u32 = 29;

    // aarch64: stp (4) + mov x29,sp (4) + ldr x16 (4) + blr x16 (4) +
    //          ldp (4) + ldr x16 (4) + br x16 (4) + .qword (8) = 36 bytes per trampoline
    //          Plus shared interrupt_label .qword (8) at end of batch
    #[cfg(target_arch = "aarch64")]
    const TRAMPOLINE_SIZE: u32 = 36;

    // macOS uses a llvm's libunwind, so __unw_add_dynamic_eh_frame_section is
    // always available and required.
    // Like in general for llvm's libunwind, the __register_frame on macOS is
    // aliased to __unw_add_dynamic_fde which only accepts single FDEs, not full
    // .eh_frame sections.
    #[cfg(target_os = "macos")]
    extern "C" {
        fn __unw_add_dynamic_eh_frame_section(eh_frame_start: usize);
        fn __unw_remove_dynamic_eh_frame_section(eh_frame_start: usize);
    }

    // On Linux, we try __unw_add_dynamic_eh_frame_section first (LLVM libunwind),
    // falling back to __register_frame (libgcc) if not available.
    // __register_frame is always available, so we can have strong linkage here.
    // However, __register_frame from LLVM libunwind doesn't accept a full
    // .eh_frame section, so we can't use it.
    // See the abandoned https://reviews.llvm.org/D44494
    // For the libunwind symbol, we need to use dlsym (weak linkage attribute
    // is unstable)
    #[cfg(target_os = "linux")]
    extern "C" {
        fn __register_frame(begin: *const u8);
        fn __deregister_frame(begin: *const u8);
        fn dlsym(handle: *mut c_void, symbol: *const std::ffi::c_char) -> *mut c_void;
    }

    #[cfg(target_os = "linux")]
    const RTLD_DEFAULT: *mut c_void = std::ptr::null_mut();

    #[cfg(target_os = "linux")]
    type EhFrameSectionFn = unsafe extern "C" fn(usize);

    /// Cached function pointers for LLVM libunwind's eh_frame section API.
    /// Looked up once via dlsym, then cached for subsequent calls.
    #[cfg(target_os = "linux")]
    struct UnwDynamicEhFrameFns {
        add: Option<EhFrameSectionFn>,
        remove: Option<EhFrameSectionFn>,
    }

    #[cfg(target_os = "linux")]
    static UNW_DYNAMIC_EH_FRAME_FNS: std::sync::OnceLock<UnwDynamicEhFrameFns> =
        std::sync::OnceLock::new();

    #[cfg(all(target_os = "linux", feature = "libunwind_link"))]
    fn get_unw_dynamic_eh_frame_fns() -> &'static UnwDynamicEhFrameFns {
        UNW_DYNAMIC_EH_FRAME_FNS.get_or_init(|| unsafe {
            let add_ptr = dlsym(RTLD_DEFAULT, c"__unw_add_dynamic_eh_frame_section".as_ptr());
            let remove_ptr = dlsym(
                RTLD_DEFAULT,
                c"__unw_remove_dynamic_eh_frame_section".as_ptr(),
            );
            UnwDynamicEhFrameFns {
                add: if add_ptr.is_null() {
                    None
                } else {
                    Some(std::mem::transmute(add_ptr))
                },
                remove: if remove_ptr.is_null() {
                    None
                } else {
                    Some(std::mem::transmute(remove_ptr))
                },
            }
        })
    }

    #[cfg(all(target_os = "linux", not(feature = "libunwind_link")))]
    fn get_unw_dynamic_eh_frame_fns() -> &'static UnwDynamicEhFrameFns {
        // Without libunwind_link feature, always use __register_frame (libgcc)
        UNW_DYNAMIC_EH_FRAME_FNS.get_or_init(|| UnwDynamicEhFrameFns {
            add: None,
            remove: None,
        })
    }

    /// Result of generating trampolines for multiple original functions.
    pub struct TrampolineBatch {
        pub buffer: dynasmrt::mmap::ExecutableBuffer,
        pub offsets: Vec<dynasmrt::AssemblyOffset>,
        /// eh_frame data for DWARF unwinding. Must be kept alive as long as the
        /// trampolines are in use, as the runtime unwinder references this data.
        /// The data itself is not read after construction, but dropping it would
        /// invalidate the registered unwind information.
        #[allow(dead_code)]
        eh_frame_data: Vec<u8>,
    }

    impl TrampolineBatch {
        /// Get the trampoline function pointer for the i-th original function.
        ///
        /// # Safety
        /// The index must be valid (< number of originals passed to generate).
        pub unsafe fn get_trampoline(&self, i: usize) -> *mut c_void {
            self.buffer.as_ptr().add(self.offsets[i].0) as *mut c_void
        }
    }

    impl Drop for TrampolineBatch {
        fn drop(&mut self) {
            // Deregister eh_frame data
            if !self.eh_frame_data.is_empty() {
                #[cfg(target_os = "macos")]
                unsafe {
                    __unw_remove_dynamic_eh_frame_section(self.eh_frame_data.as_ptr() as usize);
                }
                #[cfg(target_os = "linux")]
                unsafe {
                    let fns = get_unw_dynamic_eh_frame_fns();
                    if let Some(remove_fn) = fns.remove {
                        remove_fn(self.eh_frame_data.as_ptr() as usize);
                    } else {
                        __deregister_frame(self.eh_frame_data.as_ptr());
                    }
                }
            }
            // eh_frame_data is dropped here, after deregistration
        }
    }

    /// Generate trampolines for a batch of original functions.
    pub fn generate(originals: &[*mut c_void], interrupt_fn: *const ()) -> Option<TrampolineBatch> {
        let mut assembler = Assembler::new().unwrap();
        let mut offsets = Vec::with_capacity(originals.len());

        for orig in originals.iter() {
            let start = assembler.offset();
            offsets.push(start);
            emit_trampoline(&mut assembler, *orig, interrupt_fn);

            let actual_size = assembler.offset().0 - start.0;
            assert_eq!(
                actual_size, TRAMPOLINE_SIZE as usize,
                "TRAMPOLINE_SIZE mismatch: expected {}, got {}. \
                 Update TRAMPOLINE_SIZE constant to match actual generated code.",
                TRAMPOLINE_SIZE, actual_size
            );
        }

        // to generate a PC relative load of the interrupt function address;
        // more efficient than loading the 64-bit immediate address into x16,
        // which would require 4 instructions on each trampoline
        #[cfg(target_arch = "aarch64")]
        dynasm!(assembler
            ; interrupt_label: ; .qword interrupt_fn as i64
        );

        let buffer = match assembler.finalize() {
            Ok(buffer) => buffer,
            Err(e) => {
                error!(
                    "Failed to finalize FLF trampolines (mprotect PROT_EXEC denied?): {:?}. \
                    Frameless functions will not appear in cpu/wall-time profiles. \
                    This may be caused by security policies (SELinux, seccomp, etc.).",
                    e
                );
                return None;
            }
        };

        // generate and register eh_frame data
        let code_base = buffer.as_ptr() as u64;

        let trampoline_addrs: Vec<u64> = offsets.iter().map(|o| code_base + o.0 as u64).collect();

        #[cfg(target_os = "macos")]
        let eh_frame_data = {
            // macOS always uses libunwind's __unw_add_dynamic_eh_frame_section,
            // so we always need the libunwind workaround terminator
            let data = generate_eh_frame_section(&trampoline_addrs, TRAMPOLINE_SIZE, true);
            if !data.is_empty() {
                unsafe {
                    __unw_add_dynamic_eh_frame_section(data.as_ptr() as usize);
                }
            }
            data
        };

        #[cfg(target_os = "linux")]
        let eh_frame_data = {
            let fns = get_unw_dynamic_eh_frame_fns();
            let use_libunwind = fns.add.is_some();

            let data = generate_eh_frame_section(&trampoline_addrs, TRAMPOLINE_SIZE, use_libunwind);

            if !data.is_empty() {
                unsafe {
                    if let Some(add_fn) = fns.add {
                        add_fn(data.as_ptr() as usize);
                    } else {
                        __register_frame(data.as_ptr());
                    }
                }
            }
            data
        };

        Some(TrampolineBatch {
            buffer,
            offsets,
            eh_frame_data,
        })
    }

    /// Emit trampoline assembly for a single function.
    fn emit_trampoline(
        assembler: &mut Assembler,
        original: *mut c_void,
        #[allow(unused_variables)] interrupt_fn: *const (),
    ) {
        #[cfg(target_arch = "aarch64")]
        {
            // aarch64 trampoline layout (all instructions 4 bytes):
            //   0x00: stp x29, x30, [sp, #-16]!  - save FP and LR
            //   0x04: mov x29, sp                - set up frame pointer
            //   0x08: ldr x16, >label            - load original function address
            //   0x0c: blr x16                    - call original
            //   0x10: ldp x29, x30, [sp], #16    - restore FP and LR
            //   0x14: ldr x16, >interrupt_label  - load interrupt address
            //   0x18: br x16                     - tail call interrupt
            //   0x1c: .qword original            - 8-byte address
            dynasm!(assembler
                ; stp x29, x30, [sp, -16]! // save link register and frame pointer
                ; mov x29, sp              // set up frame pointer
                ; ldr x16, >label
                ; blr x16
                ; ldp x29, x30, [sp], 16   // restore link register and frame pointer
                ; ldr x16, >interrupt_label
                ; br x16                   // tail call
                ; label: ; .qword original as i64
            );
        }

        #[cfg(target_arch = "x86_64")]
        {
            // x86_64 trampoline layout:
            //   0x00: push rbp                   - 1 byte, save frame pointer
            //   0x01: mov rbp, rsp               - 3 bytes, establish frame pointer chain
            //   0x04: mov rax, imm64             - 10 bytes (REX + opcode + 8-byte imm)
            //   0x0e: call rax                   - 2 bytes
            //   0x10: pop rbp                    - 1 byte, restore frame pointer
            //   0x11: mov rax, imm64             - 10 bytes
            //   0x1b: jmp rax                    - 2 bytes
            // Total: 29 bytes
            let _ = interrupt_fn; // Used via direct embedding below
            dynasm!(assembler
                ; push rbp                 // save frame pointer (for FP unwinding)
                ; mov rbp, rsp             // establish frame pointer chain
                ; mov rax, QWORD original as i64
                ; call rax
                ; pop rbp                  // restore frame pointer
                ; mov rax, QWORD interrupt_fn as i64
                ; jmp rax                  // tail call
            );
        }
    }

    /// Generate eh_frame section for multiple trampolines.
    ///
    /// The `use_libunwind_workaround` parameter controls the terminator format:
    /// - `true`: Use 8-byte terminator to work around LLVM libunwind 17.x bug
    /// - `false`: Use standard 4-byte zero terminator (for libgcc's __register_frame)
    fn generate_eh_frame_section(
        code_addresses: &[u64],
        code_size: u32,
        use_libunwind_workaround: bool,
    ) -> Vec<u8> {
        if code_addresses.is_empty() {
            return Vec::new();
        }

        let encoding = Encoding {
            format: Format::Dwarf32,
            version: 1,
            address_size: 8,
        };

        let mut frame_table = FrameTable::default();
        let cie_id = frame_table.add_cie(create_cie(encoding));

        for &addr in code_addresses {
            let fde = create_fde(addr, code_size);
            frame_table.add_fde(cie_id, fde);
        }

        let mut eh_frame = EhFrame::from(EndianVec::new(LittleEndian));
        frame_table.write_eh_frame(&mut eh_frame).unwrap();
        let mut data = eh_frame.slice().to_vec();

        if use_libunwind_workaround {
            // IMPORTANT: Use non-zero-length terminator to work around a bug in
            // LLVM libunwind 17.x and earlier (fixed in commit 58b33d03, Jan 2024).
            // See: https://github.com/llvm/llvm-project/issues/76957
            // When __unw_add_dynamic_eh_frame_section encounters a zero-length entry,
            // parseCIE returns success without updating cieLength, causing the loop
            // to use stale values and read past the buffer.
            // Our terminator (length=4, CIE_ID=0xFFFFFFFF) causes both parseCIE
            // ("CIE ID is not zero") and decodeFDE ("CIE start does not match") to
            // return errors, cleanly exiting the loop on all libunwind versions.
            data.extend_from_slice(&[0x04, 0x00, 0x00, 0x00, 0xFF, 0xFF, 0xFF, 0xFF]);
        } else {
            // standard 4-byte zero terminator for libgcc's __register_frame
            // It crashes with libunwind's workaround...
            data.extend_from_slice(&[0x00, 0x00, 0x00, 0x00]);
        }
        data
    }

    /// Create the Common Information Entry (CIE) for trampolines.
    fn create_cie(encoding: Encoding) -> CommonInformationEntry {
        #[cfg(target_arch = "x86_64")]
        {
            // x86_64: At entry, CFA = RSP + 8, return address at CFA - 8
            let mut cie = CommonInformationEntry::new(encoding, 1, -8, RIP);
            cie.add_instruction(CallFrameInstruction::Cfa(RSP, 8));
            cie.add_instruction(CallFrameInstruction::Offset(RIP, -8));
            cie
        }

        #[cfg(target_arch = "aarch64")]
        {
            // aarch64: At entry, CFA = SP + 0, return address in x30 (LR)
            let mut cie = CommonInformationEntry::new(encoding, 4, -8, X30);
            cie.add_instruction(CallFrameInstruction::Cfa(SP, 0));
            cie
        }
    }

    /// Create a Frame Description Entry (FDE) for a trampoline.
    fn create_fde(code_address: u64, code_size: u32) -> FrameDescriptionEntry {
        let mut fde = FrameDescriptionEntry::new(Address::Constant(code_address), code_size);

        #[cfg(target_arch = "x86_64")]
        {
            // After push rbp (offset 1): CFA = RSP + 16, RBP saved at CFA - 16
            fde.add_instruction(1, CallFrameInstruction::CfaOffset(16));
            fde.add_instruction(1, CallFrameInstruction::Offset(RBP, -16));
            // After mov rbp, rsp (offset 4): CFA is now RBP + 16
            fde.add_instruction(4, CallFrameInstruction::CfaRegister(RBP));
        }

        #[cfg(target_arch = "aarch64")]
        {
            // After stp x29, x30, [sp, #-16]! (offset 4):
            // CFA = SP + 16, x29 at CFA - 16, x30 at CFA - 8
            fde.add_instruction(4, CallFrameInstruction::CfaOffset(16));
            fde.add_instruction(4, CallFrameInstruction::Offset(X29, -16));
            fde.add_instruction(4, CallFrameInstruction::Offset(X30, -8));
            // After mov x29, sp (offset 8): CFA is now X29 + 16
            fde.add_instruction(8, CallFrameInstruction::CfaRegister(X29));
        }

        fde
    }
}

#[cfg(php_frameless)]
mod frameless {
    #[cfg(any(target_arch = "x86_64", target_arch = "aarch64"))]
    mod trampoline {
        use super::super::jit_trampoline::TrampolineBatch;
        use crate::bindings::{
            zend_flf_functions, zend_flf_handlers, zend_frameless_function_info,
        };
        use crate::zend;
        use crate::{profiling::Profiler, RefCellExt, REQUEST_LOCALS};
        use log::debug;
        use std::ffi::c_void;
        use std::sync::atomic::Ordering;

        // This ensures that the memory stays reachable and is replaced on apache reload for example
        static mut INFOS: Vec<zend_frameless_function_info> = Vec::new();
        static mut BATCH: Option<TrampolineBatch> = None;

        pub unsafe fn install() {
            use super::super::jit_trampoline;

            // Collect frameless functions ahead of time to batch-process them.
            // Otherwise we get a new memory page per function.
            let mut originals: Vec<*mut c_void> = Vec::new();
            let mut i = 0;
            loop {
                let original = *zend_flf_handlers.add(i);
                if original.is_null() {
                    break;
                }
                originals.push(original);
                i += 1;
            }

            let interrupt_addr = ddog_php_prof_icall_trampoline_target as *const ();
            let batch = match jit_trampoline::generate(&originals, interrupt_addr) {
                Some(b) => b,
                None => {
                    // Failed to generate trampolines, frameless functions will not be profiled
                    return;
                }
            };

            // Allocate enough space for all frameless_function_infos including trailing NULLs
            let mut infos = Vec::with_capacity(originals.len() * 2);

            let mut last_infos = std::ptr::null_mut();
            for (i, _) in batch.offsets.iter().enumerate() {
                let wrapper = batch.get_trampoline(i);
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
            BATCH = Some(batch);
        }

        #[no_mangle]
        #[inline(never)]
        pub extern "C" fn ddog_php_prof_icall_trampoline_target() {
            let result = REQUEST_LOCALS.try_with_borrow(|locals| {
                if !locals.system_settings().profiling_enabled {
                    return;
                }

                // Check whether we are actually wanting an interrupt to be handled.
                let interrupt_count = locals.interrupt_count.swap(0, Ordering::SeqCst);
                if interrupt_count == 0 {
                    return;
                }

                if let Some(profiler) = Profiler::get() {
                    // SAFETY: profiler doesn't mutate execute_data
                    let execute_data = unsafe { zend::ddog_php_prof_get_current_execute_data() };
                    profiler.collect_time(execute_data, interrupt_count);
                }
            });

            if let Err(err) = result {
                debug!(
                    "ddog_php_prof_icall_trampoline_target failed to borrow request locals: {err}"
                );
            }
        }
    }

    #[no_mangle]
    pub unsafe extern "C" fn ddog_php_prof_post_startup() {
        #[cfg(any(target_arch = "x86_64", target_arch = "aarch64"))]
        trampoline::install();
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

#[cfg(test)]
#[cfg(any(target_arch = "x86_64", target_arch = "aarch64"))]
#[path = "wall_time_tests.rs"]
mod tests;
