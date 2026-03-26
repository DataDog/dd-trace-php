//! Panic-audit cdylib for `libdatadog-php-profiling-shm`.
//!
//! This crate is built with `RUSTC_BOOTSTRAP=1 cargo build -Z build-std=core
//! --profile panic-audit` and checked with `nm --demangle` for `core::panicking`
//! symbols. If any panic path survives LTO, it shows up here and the CI check fails.
//!
//! Every public API entry point is re-exported as a `#[no_mangle] extern "C"`
//! function so the linker treats each one as a root and cannot dead-strip it.
#![no_std]

use libdatadog_php_profiling_shm::{FunctionIndex, InternError, ShmRegion, StringIndex};

#[panic_handler]
fn panic_handler(_: &core::panic::PanicInfo) -> ! {
    // Body is unreachable: panic=abort routes through panic_fmt then calls abort().
    // We must provide this lang item to satisfy the linker even though it is never called.
    loop {}
}

#[no_mangle]
pub unsafe extern "C" fn audit_create(out: *mut *mut u8) -> i32 {
    match unsafe { ShmRegion::create() } {
        Ok(r) => {
            unsafe { *out = r.ptr() };
            0
        }
        Err(()) => -1,
    }
}

#[no_mangle]
pub unsafe extern "C" fn audit_unmap(ptr: *mut u8) {
    // Reconstruct a ShmRegion from a raw pointer so audit_unmap covers that path.
    // SAFETY: caller must ensure ptr is a valid ShmRegion mapping.
    let region = unsafe { core::ptr::read(&ptr as *const *mut u8 as *const ShmRegion) };
    unsafe { region.unmap() };
}

#[no_mangle]
pub extern "C" fn audit_intern_str(region: &ShmRegion, ptr: *const u8, len: usize) -> i32 {
    // SAFETY: caller guarantees ptr/len are valid UTF-8.
    let s = unsafe { core::str::from_utf8_unchecked(core::slice::from_raw_parts(ptr, len)) };
    match region.intern_str(s) {
        Ok(idx) => idx.0 as i32,
        Err(InternError::StrTooLong) => -1,
        Err(InternError::OutOfMemory) => -2,
        Err(InternError::WouldBlock) => -3,
    }
}

#[no_mangle]
pub extern "C" fn audit_intern_function(
    region: &ShmRegion,
    name: u32,
    file: u32,
) -> i32 {
    match region.intern_function(StringIndex(name), StringIndex(file)) {
        Ok(idx) => idx.0 as i32,
        Err(InternError::StrTooLong) => -1,
        Err(InternError::OutOfMemory) => -2,
        Err(InternError::WouldBlock) => -3,
    }
}

#[no_mangle]
pub extern "C" fn audit_get_str(region: &ShmRegion, idx: u32) -> usize {
    region.get_str(StringIndex(idx)).map_or(0, |s| s.len())
}

#[no_mangle]
pub extern "C" fn audit_get_function(region: &ShmRegion, idx: u32) -> u64 {
    region
        .get_function(FunctionIndex(idx))
        .map_or(u64::MAX, |(n, f)| ((n.0 as u64) << 32) | f.0 as u64)
}
