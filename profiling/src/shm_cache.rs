//! Caching layer for function names and filenames in `reserved[]` slots.
//!
//! This module is only compiled on PHP 8.0+ (`cfg(php_opcache_shm_cache)`)
//! because [`zend_get_resource_handle`] only contributes to `zend_system_id`
//! (used by opcache for cache invalidation) from PHP 8.0 onward. On PHP 7.x,
//! changes in handle assignments across runs would not invalidate the cache,
//! potentially leading to stale `reserved[]` pointers.
//!
//! ## Op-array (user function) caching
//!
//! Each op_array's `reserved[handle]` points to the following layout in SHM,
//! populated by the `op_array_persist_calc`/`op_array_persist` hooks:
//!
//! ```text
//! [ u16: fn_name_len ] [ fn_name_bytes... ] [ \0 ]
//! [ u16: filename_len ] [ filename_bytes... ] [ \0 ]
//! ```
//!
//! ## Internal (native) function caching
//!
//! For internal functions, `reserved[handle]` is lazily populated on first
//! access via an [`AtomicPtr`] compare-and-swap. The heap-allocated buffer
//! contains only the function name:
//!
//! ```text
//! [ u16: fn_name_len ] [ fn_name_bytes... ] [ \0 ]
//! ```
//!
//! Length prefixes are stored as native-endian `u16`, read/written with
//! unaligned access. The trailing `\0` after each string is not included in
//! the length and exists only to aid runtime debuggers. Both strings are
//! bounded by [`STR_LEN_LIMIT`] (`u16::MAX`), so `u16` is sufficient.

use crate::bindings::{
    self, _zend_op_array as zend_op_array, zai_str_from_zstr, zend_function, ZendExtension,
    ZendResult, ZEND_ACC_CALL_VIA_TRAMPOLINE,
};
use crate::vec_ext::VecExt;
use libc::{c_int, c_void};
use std::alloc::{self, Layout};
use std::mem;
use std::ptr;
use std::sync::atomic::{AtomicPtr, Ordering, Ordering::*};

const LARGE_STRING: &str = "[suspiciously large string]";

// TODO: `try_string_from_utf8_lossy` and `try_string_from_utf8_lossy_vec`
// duplicate logic from `libdd-profiling-ffi/src/profiles/utf8.rs`. That
// implementation should be moved to `libdd-profiling` (not `-ffi`) so both
// crates can reuse it without duplicating the fallible lossy UTF-8 conversion.

/// Lossy UTF-8 conversion of a `Vec<u8>` into a `String`, using only
/// fallible allocations. If the input is already valid UTF-8, this is
/// zero-copy (reuses the Vec's buffer). Returns `None` on OOM.
#[inline(always)]
fn try_string_from_utf8_lossy_vec(v: Vec<u8>) -> Option<String> {
    match String::from_utf8(v) {
        Ok(s) => Some(s),
        Err(e) => try_string_from_utf8_lossy(e.as_bytes()),
    }
}

/// Lossy UTF-8 conversion of a byte slice into an owned `String`, using
/// only fallible allocations and no operations that can panic. Adapted
/// from `libdd-profiling-ffi/src/profiles/utf8.rs`. Returns `None` on OOM.
#[inline(always)]
fn try_string_from_utf8_lossy(v: &[u8]) -> Option<String> {
    const REPLACEMENT: &[u8] = "\u{FFFD}".as_bytes();

    let mut iter = v.utf8_chunks();

    let first = iter.next()?;
    if first.invalid().is_empty() {
        // Entirely valid UTF-8 — allocate and copy via Vec.
        let mut buf = Vec::new();
        buf.try_extend_from_slice(v).ok()?;
        // SAFETY: we just confirmed the input is valid UTF-8.
        return Some(unsafe { String::from_utf8_unchecked(buf) });
    }

    let mut buf = Vec::new();
    buf.try_reserve(v.len()).ok()?;
    buf.try_extend_from_slice(first.valid().as_bytes()).ok()?;
    buf.try_extend_from_slice(REPLACEMENT).ok()?;

    for chunk in iter {
        buf.try_extend_from_slice(chunk.valid().as_bytes()).ok()?;
        if !chunk.invalid().is_empty() {
            buf.try_extend_from_slice(REPLACEMENT).ok()?;
        }
    }

    // SAFETY: we only pushed valid UTF-8 fragments and the UTF-8
    // replacement character, so the result is valid UTF-8.
    Some(unsafe { String::from_utf8_unchecked(buf) })
}

/// The acquired `reserved[]` slot handle. -1 means not acquired.
static mut RESOURCE_HANDLE: c_int = -1;

/// Platform alignment, matching `PLATFORM_ALIGNMENT` in PHP's
/// `zend_shared_alloc.h`. On 64-bit this is 8.
const PLATFORM_ALIGNMENT: usize = mem::size_of::<usize>();

/// Equivalent to PHP's `ZEND_ALIGNED_SIZE` macro.
#[inline]
const fn zend_aligned_size(size: usize) -> usize {
    (size + PLATFORM_ALIGNMENT - 1) & !(PLATFORM_ALIGNMENT - 1)
}

/// Maximum string length we'll cache. Matches `STR_LEN_LIMIT` in
/// `stack_walking.rs`.
const STR_LEN_LIMIT: usize = u16::MAX as usize;

/// Acquires a `reserved[]` slot via `zend_get_resource_handle` and configures
/// the extension's `resource_number` and persist hooks.
///
/// Must be called during module init on a `ZendExtension` that has not yet
/// been registered (the engine copies the struct during registration).
///
/// # Safety
/// Must be called during module init (minit), single-threaded.
pub unsafe fn minit(extension: &mut ZendExtension) -> ZendResult {
    // SAFETY: extension.name is a valid C string set during construction.
    let handle = unsafe { bindings::zend_get_resource_handle(extension.name) };
    if handle < 0 {
        return ZendResult::Failure;
    }
    // SAFETY: minit is single-threaded.
    unsafe { RESOURCE_HANDLE = handle };

    extension.resource_number = handle;
    extension.op_array_persist_calc = Some(op_array_persist_calc);
    extension.op_array_persist = Some(op_array_persist);

    ZendResult::Success
}

/// Computes the FQN for a function (both user and internal), returning it as
/// a lossy-UTF-8 owned [`String`]. Names exceeding [`STR_LEN_LIMIT`] are
/// replaced with `"[suspiciously large string]"`.
///
/// All allocations are fallible (no panics). Returns `None` on OOM.
/// Top-level script code (no function name) uses `"<?php"` as the name.
#[inline(always)]
fn compute_function_name(func: &zend_function) -> Option<String> {
    const PHP_OPEN_TAG: &[u8] = b"<?php";
    let method_name: &[u8] = func.name().unwrap_or(PHP_OPEN_TAG);

    let module_name = func.module_name().unwrap_or(b"");
    let class_name = func.scope_name().unwrap_or(b"");

    let (has_module, has_class) = (!module_name.is_empty(), !class_name.is_empty());
    let module_len = has_module as usize + module_name.len(); // "|" separator
    let class_name_len = has_class as usize * 2 + class_name.len(); // "::" separator
    let raw_len = module_len + class_name_len + method_name.len();

    if raw_len >= STR_LEN_LIMIT {
        try_string_from_utf8_lossy(LARGE_STRING.as_bytes())
    } else {
        let mut buffer = Vec::<u8>::new();
        buffer.try_reserve_exact(raw_len).ok()?;

        // Capacity was pre-reserved for the exact total length, so these
        // try_extend_from_slice calls will not allocate.
        if has_module {
            buffer.try_extend_from_slice(module_name).ok()?;
            buffer.try_extend_from_slice(b"|").ok()?;
        }
        if has_class {
            buffer.try_extend_from_slice(class_name).ok()?;
            buffer.try_extend_from_slice(b"::").ok()?;
        }
        buffer.try_extend_from_slice(method_name).ok()?;

        try_string_from_utf8_lossy_vec(buffer)
    }
}

/// Computes the FQN and filename strings for an op_array, returning them as
/// lossy-UTF-8 owned [`String`]s.
///
/// All allocations are fallible (no panics). Returns `None` on OOM.
#[inline(always)]
fn compute_shm_strings(op_array: &zend_op_array) -> Option<(String, String)> {
    // SAFETY: we cast to zend_function to use its helper methods. This is safe
    // because zend_op_array is the op_array variant of the zend_function union,
    // and we only call methods that access common/op_array fields.
    let func: &zend_function =
        unsafe { &*(op_array as *const zend_op_array as *const zend_function) };

    let function_name = compute_function_name(func)?;

    // Extract filename (lossy UTF-8 converted).
    // SAFETY: op_array.filename is valid during persist hooks.
    let filename: String = {
        let file_str = unsafe { zai_str_from_zstr(op_array.filename.as_mut()) };
        let bytes = file_str.as_bytes();
        try_string_from_utf8_lossy(if bytes.len() >= STR_LEN_LIMIT {
            LARGE_STRING.as_bytes()
        } else {
            bytes
        })?
    };

    Some((function_name, filename))
}

/// Size of the length prefix for each string in SHM.
const LEN_PREFIX_SIZE: usize = mem::size_of::<u16>();

/// Computes the total SHM size needed for the given strings (unaligned).
///
/// Layout: `[u16 fn_len][fn_bytes][\0][u16 file_len][file_bytes][\0]`
#[inline(always)]
fn compute_total_size(function_name: &str, filename: &str) -> usize {
    // Both strings are bounded by STR_LEN_LIMIT (u16::MAX), so these
    // additions cannot overflow on any platform with usize >= 32 bits.
    let fn_entry = LEN_PREFIX_SIZE + function_name.len() + 1;
    let file_entry = LEN_PREFIX_SIZE + filename.len() + 1;
    fn_entry + file_entry
}

/// Resolves the op_array pointer and computes the SHM strings and aligned
/// size. Shared by both persist hooks.
///
/// # Safety
/// `op_array` must be a valid pointer provided by the engine during opcache
/// persistence.
#[inline(always)]
unsafe fn prepare_persist(op_array: *mut zend_op_array) -> Option<(String, String, usize)> {
    let op_array = unsafe { op_array.as_ref() }?;
    let (function_name, filename) = compute_shm_strings(op_array)?;
    let aligned_total = zend_aligned_size(compute_total_size(&function_name, &filename));
    Some((function_name, filename, aligned_total))
}

/// `op_array_persist_calc` hook for opcache. Computes the number of bytes
/// needed in SHM for this op_array's cached strings.
///
/// # Safety
/// Called by the engine during opcache persistence with a valid op_array.
#[export_name = "ddog_php_prof_op_array_persist_calc"]
#[cfg_attr(not(debug_assertions), no_panic::no_panic)]
pub unsafe extern "C" fn op_array_persist_calc(op_array: *mut zend_op_array) -> usize {
    match unsafe { prepare_persist(op_array) } {
        Some((_fn_name, _filename, size)) => size,
        None => 0,
    }
}

/// `op_array_persist` hook for opcache. Writes the cached strings into the
/// provided SHM memory and stores a pointer in `op_array->reserved[handle]`.
///
/// # Safety
/// Called by the engine during opcache persistence with a valid op_array and
/// a `mem` pointer to SHM memory of the size returned by `persist_calc`.
#[export_name = "ddog_php_prof_op_array_persist"]
#[cfg_attr(not(debug_assertions), no_panic::no_panic)]
pub unsafe extern "C" fn op_array_persist(op_array: *mut zend_op_array, mem: *mut c_void) -> usize {
    let handle = unsafe { RESOURCE_HANDLE };

    let Some((function_name, filename, aligned_total)) = (unsafe { prepare_persist(op_array) })
    else {
        return 0;
    };

    // Write the two length-prefixed strings into SHM.
    let cursor = write_shm_str(mem as *mut u8, &function_name);
    let _cursor = write_shm_str(cursor, &filename);

    // Store the SHM pointer in op_array->reserved[handle].
    // SAFETY: handle was acquired via zend_get_resource_handle during minit,
    // which guarantees 0 <= handle < ZEND_MAX_RESERVED_RESOURCES. If it had
    // failed (returned -1), minit would have returned FAILURE and these
    // hooks would never be installed.
    if handle >= 0 {
        let op_array_mut = unsafe { &mut *op_array };
        unsafe { *op_array_mut.reserved.get_unchecked_mut(handle as usize) = mem };
    }

    aligned_total
}

/// Writes a length-prefixed string entry (`[u16 len][bytes...][\0]`) into
/// SHM at `dst` and returns a pointer past the trailing NUL.
///
/// # Safety
/// `dst` must point to writable memory with at least
/// `LEN_PREFIX_SIZE + s.len() + 1` bytes available.
#[inline(always)]
unsafe fn write_shm_str(dst: *mut u8, s: &str) -> *mut u8 {
    let len = s.len() as u16;
    // Write length prefix (unaligned, native-endian).
    unsafe { core::ptr::write_unaligned(dst as *mut u16, len) };
    // Write string bytes.
    let bytes_dst = unsafe { dst.add(LEN_PREFIX_SIZE) };
    unsafe {
        core::ptr::copy_nonoverlapping(s.as_ptr(), bytes_dst, s.len());
    }
    // Write trailing NUL for debugger convenience.
    let nul_dst = unsafe { bytes_dst.add(s.len()) };
    unsafe { *nul_dst = 0 };
    // Return pointer past the NUL.
    unsafe { nul_dst.add(1) }
}

/// Attempts to read cached function name and (optionally) filename from the
/// `reserved[]` slot for a given function.
///
/// For user functions (op_arrays), the data lives in opcache SHM and contains
/// both function name and filename. For internal (native) functions, the data
/// is lazily heap-allocated on first access and contains only the function
/// name (filename is `None`).
///
/// Returns `Some((function_name, Some(filename)))` for op_arrays,
/// `Some((function_name, None))` for internal functions, or `None` on miss.
///
/// # Safety
/// The caller must ensure `func` is a valid reference to a live
/// `zend_function`.
pub unsafe fn try_get_cached<'a>(func: &zend_function) -> Option<(&'a str, Option<&'a str>)> {
    let handle = unsafe { RESOURCE_HANDLE };
    // Not initialized (minit wasn't called, e.g. in benchmarks).
    // todo: remove plumb through testing so we don't need to have this at
    //       runtime for performance.
    if handle < 0 {
        return None;
    }
    let handle = handle as usize;

    // Trampolines (e.g. __call, Closure::__invoke) are temporary
    // zend_function structs whose reserved[] slots are uninitialized.
    // SAFETY: common.fn_flags is always safe to read on any zend_function.
    if unsafe { func.common.fn_flags } & ZEND_ACC_CALL_VIA_TRAMPOLINE != 0 {
        return None;
    }

    if func.is_internal() {
        return unsafe { try_get_cached_internal(func, handle) };
    }

    // Op-array path: read directly from the SHM pointer written by
    // op_array_persist. No atomics needed — SHM is immutable after persist.
    let ptr = unsafe { func.op_array.reserved[handle] };
    if ptr.is_null() {
        return None;
    }

    let cursor = ptr as *const u8;
    let (function_name, cursor) = unsafe { read_shm_str(cursor) };
    let (filename, _cursor) = unsafe { read_shm_str(cursor) };
    Some((function_name, Some(filename)))
}

/// Lazily populates and reads the cached function name for an internal
/// function. Uses [`AtomicPtr`] compare-and-swap for thread-safe one-time
/// initialization.
///
/// # Safety
/// `func` must be a valid internal function and `handle` must be in-bounds.
unsafe fn try_get_cached_internal<'a>(
    func: &zend_function,
    handle: usize,
) -> Option<(&'a str, Option<&'a str>)> {
    // SAFETY: AtomicPtr<c_void> has the same size and alignment as
    // *mut c_void (verified by test). We reinterpret the reserved[] slot
    // as an AtomicPtr for lock-free lazy initialization. The slot is only
    // ever written via this atomic CAS or is null (the engine zero-inits
    // internal_function.reserved[] and never writes to our handle).
    let slot = unsafe {
        &*(&func.internal_function.reserved[handle] as *const *mut c_void
            as *const AtomicPtr<c_void>)
    };

    // On ZTS, Acquire/Release pairs ensure the buffer writes from the
    // publishing thread are visible to readers. On NTS there's only one
    // thread, so Relaxed suffices for all operations.
    const LOAD_ORDER: Ordering = if cfg!(php_zts) { Acquire } else { Relaxed };
    const CAS_SUCCESS: Ordering = if cfg!(php_zts) { Release } else { Relaxed };
    const CAS_FAILURE: Ordering = if cfg!(php_zts) { Acquire } else { Relaxed };

    let ptr = slot.load(LOAD_ORDER);
    let data_ptr = if !ptr.is_null() {
        ptr as *const u8
    } else {
        // Cache miss — compute and try to install.
        let (new_ptr, layout) = allocate_internal_cache(func)?;
        match slot.compare_exchange(ptr::null_mut(), new_ptr.cast(), CAS_SUCCESS, CAS_FAILURE) {
            Ok(_) => new_ptr,
            Err(winner) => {
                // Another thread populated the slot first. Free ours.
                unsafe { alloc::dealloc(new_ptr, layout) };
                winner as *const u8
            }
        }
    };

    let (function_name, _cursor) = unsafe { read_shm_str(data_ptr) };
    Some((function_name, None))
}

/// Allocates a heap buffer containing the cached function name for an
/// internal function. Layout: `[u16 len][bytes...][\0]`.
///
/// Returns the buffer pointer and its [`Layout`] (for deallocation on CAS
/// failure), or `None` on OOM.
fn allocate_internal_cache(func: &zend_function) -> Option<(*mut u8, Layout)> {
    let function_name = compute_function_name(func)?;
    let total_size = LEN_PREFIX_SIZE + function_name.len() + 1;

    // SAFETY: total_size >= 4 (function name is always non-empty), align 1
    // is always valid.
    let layout = unsafe { Layout::from_size_align_unchecked(total_size, 1) };
    let ptr = unsafe { alloc::alloc(layout) };
    if ptr.is_null() {
        return None;
    }

    unsafe { write_shm_str(ptr, &function_name) };
    Some((ptr, layout))
}

/// Reads a length-prefixed string entry (`[u16 len][bytes...][\0]`) from
/// SHM at `src`. Returns the `&str` and a pointer past the trailing NUL.
///
/// The returned `&str` has an unbounded lifetime — the caller must ensure
/// the SHM data outlives the returned reference (it lives until opcache
/// restart / process exit).
///
/// # Safety
/// `src` must point to readable SHM written by [`write_shm_str`].
#[inline(always)]
unsafe fn read_shm_str<'a>(src: *const u8) -> (&'a str, *const u8) {
    let len = unsafe { core::ptr::read_unaligned(src as *const u16) } as usize;
    let bytes_ptr = unsafe { src.add(LEN_PREFIX_SIZE) };
    let bytes = unsafe { core::slice::from_raw_parts(bytes_ptr, len) };
    // SAFETY: the data was written as valid UTF-8 by op_array_persist or
    // allocate_internal_cache.
    let s = unsafe { core::str::from_utf8_unchecked(bytes) };
    // Advance past bytes + trailing NUL.
    let next = unsafe { bytes_ptr.add(len + 1) };
    (s, next)
}

#[cfg(test)]
mod tests {
    use std::ffi::c_void;
    use std::sync::atomic::AtomicPtr;

    /// The internal function cache reinterprets a `*mut c_void` slot as an
    /// `AtomicPtr<c_void>`. This test verifies the layout assumption.
    #[test]
    fn atomic_ptr_layout_matches_raw_pointer() {
        assert_eq!(
            std::mem::size_of::<AtomicPtr<c_void>>(),
            std::mem::size_of::<*mut c_void>(),
        );
        assert_eq!(
            std::mem::align_of::<AtomicPtr<c_void>>(),
            std::mem::align_of::<*mut c_void>(),
        );
    }
}
