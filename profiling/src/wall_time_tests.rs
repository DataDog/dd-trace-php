//! Tests for JIT trampoline unwinding functionality.
//!
//! These tests verify that stack unwinding works correctly through dynamically
//! generated JIT trampolines using various unwinding methods:
//! - Frame pointer (FP) walking
//! - _Unwind_Backtrace (generic unwind API)
//! - libunwind (unw_* API)
//! - backtrace crate
//! - Rust panic unwinding

use std::cell::Cell;
use std::ffi::{c_void, CStr};

// dladdr for symbol resolution
mod dladdr_api {
    use std::ffi::{c_char, c_void};

    #[repr(C)]
    pub struct DlInfo {
        pub dli_fname: *const c_char, // Pathname of shared object
        pub dli_fbase: *mut c_void,   // Base address of shared object
        pub dli_sname: *const c_char, // Name of nearest symbol
        pub dli_saddr: *mut c_void,   // Address of nearest symbol
    }

    extern "C" {
        pub fn dladdr(addr: *const c_void, info: *mut DlInfo) -> i32;
    }
}

// Generic unwind API (_Unwind_Backtrace, as seen in libgcc_s or llvm libunwind)
mod generic_unwind {
    use std::ffi::c_void;

    #[repr(C)]
    pub struct UnwindContext {
        _data: [u8; 1024],
    }

    #[repr(C)]
    #[allow(dead_code)]
    pub enum UnwindReasonCode {
        NoReason = 0,
        ForeignExceptionCaught = 1,
        FatalPhase2Error = 2,
        FatalPhase1Error = 3,
        NormalStop = 4,
        EndOfStack = 5,
        HandlerFound = 6,
        InstallContext = 7,
        Continue = 8,
    }

    pub type UnwindTraceFn =
        extern "C" fn(ctx: *mut UnwindContext, arg: *mut c_void) -> UnwindReasonCode;

    extern "C" {
        pub fn _Unwind_Backtrace(trace_fn: UnwindTraceFn, arg: *mut c_void) -> UnwindReasonCode;
        pub fn _Unwind_GetIP(ctx: *mut UnwindContext) -> usize;
    }
}

// libunwind API (unw_*)
mod libunwind_api {
    #[repr(C)]
    pub struct UnwContext {
        _data: [u8; 4096],
    }

    #[repr(C)]
    pub struct UnwCursor {
        _data: [u8; 4096],
    }

    #[cfg(all(
        target_os = "linux",
        target_arch = "aarch64",
        feature = "libunwind_link"
    ))]
    #[link(name = "unwind")]
    extern "C" {
        pub fn unw_getcontext(ctx: *mut UnwContext) -> i32;
        pub fn unw_init_local(cursor: *mut UnwCursor, ctx: *mut UnwContext) -> i32;
        pub fn unw_step(cursor: *mut UnwCursor) -> i32;
        pub fn unw_get_reg(cursor: *mut UnwCursor, reg: i32, val: *mut u64) -> i32;
        pub fn unw_get_proc_name(
            cursor: *mut UnwCursor,
            buf: *mut i8,
            buf_len: usize,
            offp: *mut u64,
        ) -> i32;
    }

    #[cfg(all(
        target_os = "linux",
        target_arch = "x86_64",
        feature = "libunwind_link"
    ))]
    #[link(name = "unwind")]
    extern "C" {
        pub fn unw_getcontext(ctx: *mut UnwContext) -> i32;
        pub fn unw_init_local(cursor: *mut UnwCursor, ctx: *mut UnwContext) -> i32;
        pub fn unw_step(cursor: *mut UnwCursor) -> i32;
        pub fn unw_get_reg(cursor: *mut UnwCursor, reg: i32, val: *mut u64) -> i32;
        pub fn unw_get_proc_name(
            cursor: *mut UnwCursor,
            buf: *mut i8,
            buf_len: usize,
            offp: *mut u64,
        ) -> i32;
    }

    #[cfg(target_os = "macos")]
    extern "C" {
        pub fn unw_getcontext(ctx: *mut UnwContext) -> i32;
        pub fn unw_init_local(cursor: *mut UnwCursor, ctx: *mut UnwContext) -> i32;
        pub fn unw_step(cursor: *mut UnwCursor) -> i32;
        pub fn unw_get_reg(cursor: *mut UnwCursor, reg: i32, val: *mut u64) -> i32;
        pub fn unw_get_proc_name(
            cursor: *mut UnwCursor,
            buf: *mut i8,
            buf_len: usize,
            offp: *mut u64,
        ) -> i32;
    }

    #[cfg(all(target_arch = "aarch64", target_os = "macos"))]
    pub const UNW_REG_IP: i32 = 32;

    #[cfg(all(
        target_arch = "aarch64",
        target_os = "linux",
        feature = "libunwind_link"
    ))]
    pub const UNW_REG_IP: i32 = -1;

    #[cfg(all(target_arch = "x86_64", target_os = "macos"))]
    pub const UNW_REG_IP: i32 = 16;

    #[cfg(all(
        target_arch = "x86_64",
        target_os = "linux",
        feature = "libunwind_link"
    ))]
    pub const UNW_REG_IP: i32 = -1;
}

thread_local! {
    static JIT_CODE_START: Cell<usize> = const { Cell::new(0) };
    static JIT_CODE_END: Cell<usize> = const { Cell::new(0) };
    static FRAMES_FOUND: Cell<usize> = const { Cell::new(0) };
    static JIT_FRAME_FOUND: Cell<bool> = const { Cell::new(false) };
    static CALLER_FUNC_FOUND: Cell<bool> = const { Cell::new(false) };
    static CALLER_FUNC_ADDR: Cell<usize> = const { Cell::new(0) };
    static UNWIND_METHOD: Cell<usize> = const { Cell::new(0) };
}

#[derive(Clone)]
struct FrameInfo {
    address: usize,
    symbol: Option<String>,
    offset: Option<usize>,
}

impl FrameInfo {
    fn new(address: usize) -> Self {
        Self {
            address,
            symbol: None,
            offset: None,
        }
    }

    fn with_symbol(address: usize, symbol: String, offset: usize) -> Self {
        Self {
            address,
            symbol: Some(symbol),
            offset: Some(offset),
        }
    }
}

fn is_in_jit_range(addr: usize) -> bool {
    let start = JIT_CODE_START.get();
    let end = JIT_CODE_END.get();
    addr >= start && addr < end
}

fn resolve_symbol_dladdr(addr: usize) -> FrameInfo {
    use dladdr_api::*;
    unsafe {
        let mut info: DlInfo = std::mem::zeroed();
        if dladdr(addr as *const c_void, &mut info) != 0 && !info.dli_sname.is_null() {
            let name = CStr::from_ptr(info.dli_sname)
                .to_string_lossy()
                .into_owned();
            let offset = addr.saturating_sub(info.dli_saddr as usize);
            FrameInfo::with_symbol(addr, name, offset)
        } else {
            FrameInfo::new(addr)
        }
    }
}

// Capture backtrace using generic unwind API (_Unwind_Backtrace)
fn capture_generic_unwind() -> Vec<FrameInfo> {
    use generic_unwind::*;

    extern "C" fn trace_callback(ctx: *mut UnwindContext, arg: *mut c_void) -> UnwindReasonCode {
        let frames: &mut Vec<usize> = unsafe { &mut *arg.cast() };
        let ip = unsafe { _Unwind_GetIP(ctx) };
        frames.push(ip);
        if frames.len() > 50 {
            return UnwindReasonCode::NormalStop;
        }
        UnwindReasonCode::NoReason
    }

    let mut addrs = Vec::new();
    unsafe {
        _Unwind_Backtrace(trace_callback, (&raw mut addrs).cast());
    }
    addrs.into_iter().map(resolve_symbol_dladdr).collect()
}

// Capture backtrace using libunwind API (unw_* functions)
#[cfg(any(target_os = "macos", feature = "libunwind_link"))]
fn capture_libunwind_unwind() -> Vec<FrameInfo> {
    use libunwind_api::*;

    let mut frames = Vec::new();
    unsafe {
        let mut ctx: UnwContext = std::mem::zeroed();
        let mut cursor: UnwCursor = std::mem::zeroed();

        if unw_getcontext(&mut ctx) != 0 {
            return frames;
        }
        if unw_init_local(&mut cursor, &mut ctx) != 0 {
            return frames;
        }

        let mut name_buf = [0i8; 512];
        loop {
            let mut ip: u64 = 0;
            unw_get_reg(&mut cursor, UNW_REG_IP, &mut ip);

            let mut offset: u64 = 0;
            let ret = unw_get_proc_name(
                &mut cursor,
                name_buf.as_mut_ptr(),
                name_buf.len(),
                &mut offset,
            );

            let frame = if ret == 0 {
                let name = CStr::from_ptr(name_buf.as_ptr().cast())
                    .to_string_lossy()
                    .into_owned();
                FrameInfo::with_symbol(ip as usize, name, offset as usize)
            } else {
                FrameInfo::new(ip as usize)
            };
            frames.push(frame);

            let ret = unw_step(&mut cursor);
            if ret <= 0 || frames.len() > 50 {
                break;
            }
        }
    }
    frames
}

// Capture backtrace using backtrace crate
#[inline(never)]
fn capture_backtrace_crate() -> Vec<FrameInfo> {
    let bt = backtrace::Backtrace::new_unresolved();
    let mut frames = Vec::new();
    for frame in bt.frames() {
        let ip = frame.ip() as usize;
        frames.push(resolve_symbol_dladdr(ip));
    }
    frames
}

// Capture backtrace using frame pointer walking
#[inline(never)]
fn capture_fp_backtrace() -> Vec<FrameInfo> {
    let mut addrs: Vec<usize> = Vec::new();

    #[cfg(target_arch = "x86_64")]
    unsafe {
        let mut fp: *const usize;
        std::arch::asm!("mov {}, rbp", out(reg) fp);
        while !fp.is_null() && (fp as usize) > 0x1000_usize {
            let ra = *fp.add(1);
            if ra == 0 {
                break;
            }
            addrs.push(ra);
            let next_fp = *fp;
            if next_fp <= fp as usize || addrs.len() > 50 {
                break;
            }
            fp = next_fp as *const usize;
        }
    }

    #[cfg(target_arch = "aarch64")]
    unsafe {
        let mut fp: *const usize;
        std::arch::asm!("mov {}, x29", out(reg) fp);
        while !fp.is_null() && (fp as usize) > 0x1000 {
            let ra = *fp.add(1);
            if ra == 0 {
                break;
            }
            addrs.push(ra);
            let next_fp = *fp;
            if next_fp <= fp as usize || addrs.len() > 50 {
                break;
            }
            fp = next_fp as *const usize;
        }
    }

    addrs.into_iter().map(resolve_symbol_dladdr).collect()
}

struct BacktraceAnalysis {
    found_jit: bool,
    found_caller: bool,
}

fn is_in_function(addr: usize, func_addr: usize) -> bool {
    addr >= func_addr && addr < func_addr + 512
}

// XXX: remove
fn is_caller_symbol(symbol: &Option<String>) -> bool {
    symbol
        .as_ref()
        .is_some_and(|s| s.contains("call_trampoline_wrapper"))
}

fn analyze_backtrace(frames: &[FrameInfo], method_name: &str) -> BacktraceAnalysis {
    println!("\n=== {} Backtrace ===", method_name);
    println!("Got {} frames:", frames.len());

    let mut found_jit = false;
    let mut found_caller = false;
    let caller_addr = CALLER_FUNC_ADDR.get();

    for (i, frame) in frames.iter().enumerate() {
        let in_jit = is_in_jit_range(frame.address);
        let in_caller =
            is_in_function(frame.address, caller_addr) || is_caller_symbol(&frame.symbol);

        let mut markers = String::new();
        if in_jit {
            markers.push_str(" <-- JIT TRAMPOLINE");
        }
        if in_caller {
            markers.push_str(" <-- CALLER");
        }

        let symbol_info = match (&frame.symbol, frame.offset) {
            (Some(name), Some(offset)) => format!("{}+0x{:x}", name, offset),
            (Some(name), None) => name.clone(),
            (None, _) => "??".to_string(),
        };

        println!(
            "  [{:2}] 0x{:016x} {}{}",
            i, frame.address, symbol_info, markers
        );

        if in_jit {
            found_jit = true;
        }
        if in_caller {
            found_caller = true;
        }
    }

    println!("\nFound JIT frame in backtrace: {}", found_jit);
    println!("Found caller function in backtrace: {}", found_caller);

    BacktraceAnalysis {
        found_jit,
        found_caller,
    }
}

#[derive(Clone, Copy, PartialEq)]
#[repr(usize)]
enum UnwindMethod {
    FramePointer = 0,
    GenericUnwind = 1,
    #[cfg(any(target_os = "macos", feature = "libunwind_link"))]
    Libunwind = 2,
    BacktraceCrate = 3,
}

impl UnwindMethod {
    fn name(&self) -> &'static str {
        match self {
            UnwindMethod::FramePointer => "Frame Pointer",
            UnwindMethod::GenericUnwind => "_Unwind_Backtrace",
            #[cfg(any(target_os = "macos", feature = "libunwind_link"))]
            UnwindMethod::Libunwind => "libunwind (unw_*)",
            UnwindMethod::BacktraceCrate => "backtrace crate",
        }
    }
}

thread_local! {
    static TRAMPOLINE_PTR: Cell<usize> = const { Cell::new(0) };
}

// Call the trampoline function. This is so that we can identify that we
// frames frames beyong the trampoline. If we see this function in the
// backtrace, we know that we have unwound through the trampoline.
#[inline(never)]
fn call_trampoline_wrapper() {
    let ptr = TRAMPOLINE_PTR.get() as *mut c_void;
    let trampoline: extern "C" fn() = unsafe { std::mem::transmute(ptr) };
    trampoline();
    std::hint::black_box(());
}

fn run_production_trampoline_test(
    test_name: &str,
    capture_from: &str,
    unwind_method: UnwindMethod,
) {
    use super::jit_trampoline;

    #[cfg(target_arch = "x86_64")]
    let arch = "x86_64";
    #[cfg(target_arch = "aarch64")]
    let arch = "aarch64";

    println!("\n======================================================================");
    println!(
        "TEST: {} ({}) - capture from {}",
        test_name, arch, capture_from
    );
    println!("======================================================================");
    println!("Unwinding method: {}", unwind_method.name());
    println!("Backtrace captured from: {} function\n", capture_from);

    FRAMES_FOUND.set(0);
    JIT_FRAME_FOUND.set(false);
    UNWIND_METHOD.set(unwind_method as usize);

    #[inline(never)]
    fn capture_backtrace_by_method() -> Vec<FrameInfo> {
        match UNWIND_METHOD.get() {
            0 => capture_fp_backtrace(),
            1 => capture_generic_unwind(),
            #[cfg(any(target_os = "macos", feature = "libunwind_link"))]
            2 => capture_libunwind_unwind(),
            3 => capture_backtrace_crate(),
            _ => unreachable!(),
        }
    }

    fn method_short_name() -> &'static str {
        match UNWIND_METHOD.get() {
            0 => "FP",
            1 => "GenericUnwind",
            #[cfg(any(target_os = "macos", feature = "libunwind_link"))]
            2 => "libunwind",
            3 => "backtrace_crate",
            _ => unreachable!(),
        }
    }

    #[inline(never)]
    extern "C" fn original_fn_with_capture() {
        println!("  >>> Inside original_fn (capturing backtrace here) <<<");
        let frames = capture_backtrace_by_method();
        let analysis = analyze_backtrace(
            &frames,
            &format!("{} from original_fn", method_short_name()),
        );
        FRAMES_FOUND.set(frames.len());
        JIT_FRAME_FOUND.set(analysis.found_jit);
        CALLER_FUNC_FOUND.set(analysis.found_caller);
    }

    extern "C" fn original_fn_no_capture() {
        println!("  original_fn called (no capture)");
    }

    #[inline(never)]
    extern "C" fn interrupt_fn_with_capture() {
        println!("  >>> Inside interrupt_fn (capturing backtrace here) <<<");
        let frames = capture_backtrace_by_method();
        let analysis = analyze_backtrace(
            &frames,
            &format!("{} from interrupt_fn", method_short_name()),
        );
        FRAMES_FOUND.set(frames.len());
        JIT_FRAME_FOUND.set(analysis.found_jit);
        CALLER_FUNC_FOUND.set(analysis.found_caller);
    }

    extern "C" fn interrupt_fn_no_capture() {
        println!("  interrupt_fn called (no capture)");
    }

    let (original_fn, interrupt_fn): (extern "C" fn(), extern "C" fn()) = match capture_from {
        "original" => (original_fn_with_capture, interrupt_fn_no_capture),
        "interrupt" => (original_fn_no_capture, interrupt_fn_with_capture),
        _ => panic!("capture_from must be 'original' or 'interrupt'"),
    };

    let originals = vec![original_fn as *mut c_void];
    let batch = jit_trampoline::generate(&originals, interrupt_fn as *const ())
        .expect("Failed to generate JIT trampoline");

    let trampoline_ptr = unsafe { batch.get_trampoline(0) };
    let code_start = batch.buffer.as_ptr();
    let code_end = unsafe { code_start.add(batch.buffer.len()) };

    JIT_CODE_START.set(code_start as usize);
    JIT_CODE_END.set(code_end as usize);

    println!("Trampoline buffer: {:p} - {:p}", code_start, code_end);
    println!("Trampoline function: {:p}", trampoline_ptr);

    CALLER_FUNC_ADDR.set(call_trampoline_wrapper as usize);
    println!(
        "Caller wrapper function: {:p}",
        call_trampoline_wrapper as *const ()
    );

    TRAMPOLINE_PTR.set(trampoline_ptr as usize);
    call_trampoline_wrapper();

    let frames = FRAMES_FOUND.get();
    let found_jit = JIT_FRAME_FOUND.get();
    let found_caller = CALLER_FUNC_FOUND.get();

    println!("\n--- {} Result ---", test_name);
    println!("Captured {} frames", frames);
    println!("JIT frame visible: {}", found_jit);
    println!("Caller function found: {}", found_caller);

    match capture_from {
        "original" => {
            if !found_jit {
                panic!(
                    "FAILED: JIT frame not visible when capturing from original_fn \
                     ({} total frames).",
                    frames
                );
            }
            if !found_caller {
                panic!(
                    "FAILED: Caller function (call_trampoline_wrapper) not found in backtrace. \
                     Unwinding stopped at JIT code ({} total frames).",
                    frames
                );
            }
            println!(
                "SUCCESS: Complete backtrace - JIT frame and caller found, {} total frames",
                frames
            );
        }
        "interrupt" => {
            if unwind_method == UnwindMethod::FramePointer {
                if frames < 5 {
                    panic!(
                        "FAILED: FP backtrace too short ({} frames). FP chain may be broken.",
                        frames
                    );
                }
                println!(
                    "SUCCESS: FP backtrace complete ({} frames, caller skipped due to tail call)",
                    frames
                );
            } else {
                if !found_caller {
                    panic!(
                        "FAILED: Caller function (call_trampoline_wrapper) not found in backtrace. \
                         Unwinding failed ({} total frames).",
                        frames
                    );
                }
                println!(
                    "SUCCESS: Complete backtrace - caller found, {} total frames",
                    frames
                );
            }
        }
        _ => unreachable!(),
    }
}

// ==================== Frame Pointer Tests ====================

#[test]
fn test_unwind_from_original_fp() {
    run_production_trampoline_test(
        "FP unwind from original_fn",
        "original",
        UnwindMethod::FramePointer,
    );
}

#[test]
fn test_unwind_from_interrupt_fp() {
    run_production_trampoline_test(
        "FP unwind from interrupt_fn",
        "interrupt",
        UnwindMethod::FramePointer,
    );
}

// ==================== _Unwind_Backtrace Tests ====================

#[test]
fn test_unwind_from_original_generic() {
    run_production_trampoline_test(
        "_Unwind_Backtrace from original_fn",
        "original",
        UnwindMethod::GenericUnwind,
    );
}

#[test]
fn test_unwind_from_interrupt_generic() {
    run_production_trampoline_test(
        "_Unwind_Backtrace from interrupt_fn",
        "interrupt",
        UnwindMethod::GenericUnwind,
    );
}

// ==================== libunwind (unw_*) Tests ====================

#[test]
#[cfg(any(target_os = "macos", feature = "libunwind_link"))]
fn test_unwind_from_original_libunwind() {
    run_production_trampoline_test(
        "libunwind (unw_*) from original_fn",
        "original",
        UnwindMethod::Libunwind,
    );
}

#[test]
#[cfg(any(target_os = "macos", feature = "libunwind_link"))]
fn test_unwind_from_interrupt_libunwind() {
    run_production_trampoline_test(
        "libunwind (unw_*) from interrupt_fn",
        "interrupt",
        UnwindMethod::Libunwind,
    );
}

// ==================== backtrace crate Tests ====================

#[test]
fn test_unwind_from_original_backtrace_crate() {
    run_production_trampoline_test(
        "backtrace crate from original_fn",
        "original",
        UnwindMethod::BacktraceCrate,
    );
}

#[test]
fn test_unwind_from_interrupt_backtrace_crate() {
    run_production_trampoline_test(
        "backtrace crate from interrupt_fn",
        "interrupt",
        UnwindMethod::BacktraceCrate,
    );
}

// ==================== Rust Panic Unwinding Tests ====================

fn run_panic_unwind_test(test_name: &str, panic_from: &str) {
    use super::jit_trampoline;
    use std::panic::{self, AssertUnwindSafe};

    #[cfg(target_arch = "x86_64")]
    let arch = "x86_64";
    #[cfg(target_arch = "aarch64")]
    let arch = "aarch64";

    println!("======================================================================");
    println!("TEST: {} ({}) - panic from {}", test_name, arch, panic_from);
    println!("======================================================================");
    println!("Testing: Rust panic unwinding through JIT trampoline\n");

    thread_local! {
        static ORIGINAL_CALLED: Cell<bool> = const { Cell::new(false) };
        static INTERRUPT_CALLED: Cell<bool> = const { Cell::new(false) };
    }

    extern "C-unwind" fn original_fn_panic() {
        ORIGINAL_CALLED.set(true);
        println!("  >>> Inside original_fn - about to panic! <<<");
        panic!("intentional panic from original_fn");
    }

    extern "C-unwind" fn original_fn_no_panic() {
        ORIGINAL_CALLED.set(true);
        println!("  original_fn called (no panic)");
    }

    extern "C-unwind" fn interrupt_fn_panic() {
        INTERRUPT_CALLED.set(true);
        println!("  >>> Inside interrupt_fn - about to panic! <<<");
        panic!("intentional panic from interrupt_fn");
    }

    extern "C-unwind" fn interrupt_fn_no_panic() {
        INTERRUPT_CALLED.set(true);
        println!("  interrupt_fn called (no panic)");
    }

    let (original_fn, interrupt_fn): (extern "C-unwind" fn(), extern "C-unwind" fn()) =
        match panic_from {
            "original" => (original_fn_panic, interrupt_fn_no_panic),
            "interrupt" => (original_fn_no_panic, interrupt_fn_panic),
            _ => panic!("panic_from must be 'original' or 'interrupt'"),
        };

    let originals = vec![original_fn as *mut c_void];
    let batch = jit_trampoline::generate(&originals, interrupt_fn as *const ())
        .expect("Failed to generate JIT trampoline");

    let trampoline_ptr = unsafe { batch.get_trampoline(0) };
    let code_start = batch.buffer.as_ptr();
    let code_end = unsafe { code_start.add(batch.buffer.len()) };

    println!("Trampoline buffer: {:p} - {:p}", code_start, code_end);
    println!("Trampoline function: {:p}", trampoline_ptr);

    ORIGINAL_CALLED.set(false);
    INTERRUPT_CALLED.set(false);

    let trampoline: extern "C-unwind" fn() = unsafe { std::mem::transmute(trampoline_ptr) };

    let result = panic::catch_unwind(AssertUnwindSafe(|| {
        trampoline();
    }));

    println!("\n--- {} Result ---", test_name);
    println!("original_fn was called: {}", ORIGINAL_CALLED.get());
    println!("interrupt_fn was called: {}", INTERRUPT_CALLED.get());

    match result {
        Ok(()) => {
            panic!(
                "FAILED: Trampoline returned normally - expected panic from {}",
                panic_from
            );
        }
        Err(payload) => {
            let msg = if let Some(s) = payload.downcast_ref::<&str>() {
                s.to_string()
            } else if let Some(s) = payload.downcast_ref::<String>() {
                s.clone()
            } else {
                "unknown panic payload".to_string()
            };

            println!("Panic caught successfully!");
            println!("Panic message: {}", msg);

            let expected_msg = format!("intentional panic from {}_fn", panic_from);
            if !msg.contains(&expected_msg) {
                panic!(
                    "FAILED: Panic message mismatch. Expected '{}', got '{}'",
                    expected_msg, msg
                );
            }

            match panic_from {
                "original" => {
                    if !ORIGINAL_CALLED.get() {
                        panic!("FAILED: original_fn was not called before panic");
                    }
                    if INTERRUPT_CALLED.get() {
                        panic!("FAILED: interrupt_fn was called after original_fn panicked");
                    }
                }
                "interrupt" => {
                    if !ORIGINAL_CALLED.get() {
                        panic!("FAILED: original_fn was not called");
                    }
                    if !INTERRUPT_CALLED.get() {
                        panic!("FAILED: interrupt_fn was not called before panic");
                    }
                }
                _ => unreachable!(),
            }

            println!(
                "SUCCESS: Panic from {} was caught - unwinding worked through JIT code!",
                panic_from
            );
        }
    }
}

#[test]
fn test_panic_unwind_from_original() {
    run_panic_unwind_test("Rust panic unwind from original_fn", "original");
}

#[test]
fn test_panic_unwind_from_interrupt() {
    run_panic_unwind_test("Rust panic unwind from interrupt_fn", "interrupt");
}
