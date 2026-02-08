//! Caching layer that maps PHP functions to [`FunctionId2`] handles in the
//! global [`ProfilesDictionary`].
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
//! On read, the SHM strings are inserted into the global
//! [`ProfilesDictionary`] (deduplicating) and a [`FunctionId2`] is returned.
//!
//! ## Internal (native) function caching
//!
//! For internal functions, `reserved[handle]` directly stores a
//! [`FunctionId2`] (transmuted to `*mut c_void`), lazily populated on first
//! access via an [`AtomicPtr`] compare-and-swap. The function name is
//! computed once and inserted into the [`ProfilesDictionary`]; no separate
//! heap allocation is needed.
//!
//! Length prefixes in SHM are stored as native-endian `u16`, read/written
//! with unaligned access. The trailing `\0` after each string is not included
//! in the length and exists only to aid runtime debuggers. Both strings are
//! bounded by [`STR_LEN_LIMIT`] (`u16::MAX`), so `u16` is sufficient.

use crate::bindings::{
    self, _zend_op_array as zend_op_array, zai_str_from_zstr, zend_function, ZendExtension,
    ZendResult, ZEND_ACC_CALL_VIA_TRAMPOLINE,
};
use crate::profiling::extract_function_name;
use crate::vec_ext;
use libc::{c_int, c_void};
use libdd_profiling::profiles::datatypes::{Function2, FunctionId2, StringId2};
use std::mem;
use std::ptr;
use std::sync::atomic::{AtomicPtr, Ordering, Ordering::*};

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

/// Computes the FQN and filename strings for an op_array, returning them as
/// lossy-UTF-8 [`Cow<str>`] values.
///
/// All allocations are fallible (no panics). Returns `None` on OOM.
#[inline(always)]
fn compute_shm_strings(
    op_array: &zend_op_array,
) -> Option<(
    std::borrow::Cow<'static, str>,
    std::borrow::Cow<'static, str>,
)> {
    // SAFETY: we cast to zend_function to use its helper methods. This is safe
    // because zend_op_array is the op_array variant of the zend_function union,
    // and we only call methods that access common/op_array fields.
    let func: &zend_function =
        unsafe { &*(op_array as *const zend_op_array as *const zend_function) };

    let function_name = extract_function_name(func).ok()?;

    // Extract filename (lossy UTF-8 converted).
    // SAFETY: op_array.filename is valid during persist hooks.
    let filename = {
        let file_str = unsafe { zai_str_from_zstr(op_array.filename.as_mut()) };
        let bytes = file_str.as_bytes();
        if bytes.len() >= STR_LEN_LIMIT {
            std::borrow::Cow::Borrowed("[suspiciously large string]")
        } else {
            vec_ext::try_cow_from_utf8_lossy(bytes).ok()?
        }
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
unsafe fn prepare_persist(
    op_array: *mut zend_op_array,
) -> Option<(
    std::borrow::Cow<'static, str>,
    std::borrow::Cow<'static, str>,
    usize,
)> {
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

/// Attempts to resolve a [`FunctionId2`] for the given function.
///
/// For user functions (op_arrays), the data lives in opcache SHM; the
/// strings are read and inserted into the global [`ProfilesDictionary`] to
/// produce a [`FunctionId2`]. For internal (native) functions, the
/// [`FunctionId2`] is stored directly in `reserved[handle]` and returned
/// without any dictionary lookup on the hot path.
///
/// Returns `None` if the function has no cached data (no opcache, no
/// reserved handle, trampoline, or dictionary insertion failure).
///
/// # Safety
/// The caller must ensure `func` is a valid reference to a live
/// `zend_function`.
pub unsafe fn try_get_cached(func: &zend_function) -> Option<FunctionId2> {
    let handle = unsafe { RESOURCE_HANDLE };
    // Not initialized (minit wasn't called, e.g. in benchmarks).
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

    // Op-array path: read SHM strings and insert into dictionary.
    // No atomics needed — SHM is immutable after persist.
    let ptr = unsafe { func.op_array.reserved[handle] };
    if ptr.is_null() {
        return None;
    }

    let cursor = ptr as *const u8;
    let (function_name, cursor) = unsafe { read_shm_str(cursor) };
    let (filename, _cursor) = unsafe { read_shm_str(cursor) };

    let dict = crate::interning::dictionary();
    let name_id = dict.try_insert_str2(function_name).ok()?;
    let file_id = dict.try_insert_str2(filename).ok()?;
    let func2 = Function2 {
        name: name_id,
        system_name: StringId2::default(),
        file_name: file_id,
    };
    dict.try_insert_function2(func2).ok()
}

/// Lazily populates and reads a [`FunctionId2`] for an internal function.
/// Uses [`AtomicPtr`] compare-and-swap for thread-safe one-time
/// initialization. The [`FunctionId2`] is stored directly in the
/// `reserved[]` slot (transmuted to `*mut c_void`).
///
/// # Safety
/// `func` must be a valid internal function and `handle` must be in-bounds.
unsafe fn try_get_cached_internal(func: &zend_function, handle: usize) -> Option<FunctionId2> {
    // SAFETY: AtomicPtr<c_void> has the same size and alignment as
    // *mut c_void (verified by test). We reinterpret the reserved[] slot
    // as an AtomicPtr for lock-free lazy initialization. The slot is only
    // ever written via this atomic CAS or is null (the engine zero-inits
    // internal_function.reserved[] and never writes to our handle).
    let slot = unsafe {
        &*(&func.internal_function.reserved[handle] as *const *mut c_void
            as *const AtomicPtr<c_void>)
    };

    // On ZTS, Acquire/Release pairs ensure the dictionary writes from the
    // publishing thread are visible to readers. On NTS there's only one
    // thread, so Relaxed suffices for all operations.
    const LOAD_ORDER: Ordering = if cfg!(php_zts) { Acquire } else { Relaxed };
    const CAS_SUCCESS: Ordering = if cfg!(php_zts) { Release } else { Relaxed };
    const CAS_FAILURE: Ordering = if cfg!(php_zts) { Acquire } else { Relaxed };

    let ptr = slot.load(LOAD_ORDER);
    if !ptr.is_null() {
        // Cache hit: the pointer IS the FunctionId2 (stored via transmute).
        // SAFETY: we only ever store valid FunctionId2 values via CAS below.
        return Some(unsafe { mem::transmute::<*mut c_void, FunctionId2>(ptr) });
    }

    // Cache miss — compute name, insert into dictionary, CAS.
    let function_name = extract_function_name(func).ok()?;
    let dict = crate::interning::dictionary();
    let name_id = dict.try_insert_str2(&function_name).ok()?;
    let func2 = Function2 {
        name: name_id,
        system_name: StringId2::EMPTY,
        file_name: StringId2::EMPTY,
    };
    let function_id = dict.try_insert_function2(func2).ok()?;

    // SAFETY: FunctionId2 is #[repr(transparent)] over *mut Function2,
    // which has the same size and alignment as *mut c_void.
    let new_ptr: *mut c_void = unsafe { mem::transmute::<FunctionId2, *mut c_void>(function_id) };

    match slot.compare_exchange(ptr::null_mut(), new_ptr, CAS_SUCCESS, CAS_FAILURE) {
        Ok(_) => Some(function_id),
        Err(winner) => {
            // Another thread populated the slot first. Use their FunctionId2.
            // No deallocation needed — the dictionary handles dedup.
            // SAFETY: winner was stored by another thread via the same CAS,
            // but it should be the same function so this is fine.
            // todo: make a method in libdatadog for "is_repr_eq" which is
            //       similar to Eq but only works for things that come from the
            //       the same dictionary.
            Some(unsafe { mem::transmute::<*mut c_void, FunctionId2>(winner) })
        }
    }
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
