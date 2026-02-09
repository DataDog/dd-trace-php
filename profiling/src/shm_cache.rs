//! Caching layer that maps PHP functions to [`FunctionId2`] handles in the
//! global [`ProfilesDictionary`].
//!
//! This module is only compiled on PHP 8.0+ (`cfg(php_opcache_shm_cache)`)
//! because [`zend_get_resource_handle`] only contributes to `zend_system_id`
//! (used by opcache for cache invalidation) from PHP 8.0 onward. On PHP 7.x,
//! changes in handle assignments across runs would not invalidate the cache,
//! potentially leading to stale `reserved[]` pointers.
//!
//! ## Op-array (user function) caching — PHP 8.4+ only
//!
//! On PHP 8.4+ (`cfg(php_opcache_restart_hook)`), opcache persist hooks write
//! function name and filename strings into SHM with a dense index prefix:
//!
//! ```text
//! [ u32: index ] [ u16: fn_name_len ] [ fn_name_bytes... ] [ \0 ]
//!                [ u16: filename_len ] [ filename_bytes... ] [ \0 ]
//! ```
//!
//! A shared `AtomicU32` counter assigns indices during `op_array_persist`.
//! A thread-local `Vec<FunctionId2>` maps index → `FunctionId2` for O(1)
//! cross-request lookups. The vec is cleared when an opcache restart is
//! detected via a shared generation counter.
//!
//! On PHP 8.0–8.3, persist hooks are **not installed** because
//! `FunctionId2` values cannot be efficiently cached across requests without
//! the opcache restart hook for invalidation. Layer 2 (per-request
//! `run_time_cache`) handles user function caching instead, calling into the
//! [`ProfilesDictionary`] once per function per request.
//!
//! ## Internal (native) function caching — PHP 8.0+
//!
//! For internal functions, `reserved[handle]` directly stores a
//! [`FunctionId2`] (transmuted to `*mut c_void`), lazily populated on first
//! access via an [`AtomicPtr`] compare-and-swap. The function name is
//! computed once and inserted into the [`ProfilesDictionary`]; no separate
//! heap allocation is needed. This works on all PHP 8.0+ versions because
//! internal functions are never freed during the process lifetime.
//!
//! ## SHM string encoding (PHP 8.4+ only)
//!
//! Length prefixes in SHM are stored as native-endian `u16`, read/written
//! with unaligned access. The trailing `\0` after each string is not included
//! in the length and exists only to aid runtime debuggers. Both strings are
//! bounded by [`STR_LEN_LIMIT`] (`u16::MAX`), so `u16` is sufficient.

use crate::bindings::{self, ZendExtension};
use libc::c_int;

#[cfg(php_opcache_restart_hook)]
use crate::bindings::{
    _zend_op_array as zend_op_array, zai_str_from_zstr, zend_function, ZendResult,
    ZEND_ACC_CALL_VIA_TRAMPOLINE,
};
#[cfg(php_opcache_restart_hook)]
use crate::profiling::extract_function_name;
#[cfg(php_opcache_restart_hook)]
use crate::vec_ext;
#[cfg(php_opcache_restart_hook)]
use libc::c_void;
#[cfg(php_opcache_restart_hook)]
use libdd_profiling::profiles::datatypes::{Function2, FunctionId2, StringId2};
#[cfg(php_opcache_restart_hook)]
use std::mem;
#[cfg(php_opcache_restart_hook)]
use std::ptr;
#[cfg(php_opcache_restart_hook)]
use std::sync::atomic::{AtomicPtr, AtomicU32, AtomicU64, Ordering, Ordering::*};

/// The acquired `reserved[]` slot handle. -1 means not acquired.
static mut RESOURCE_HANDLE: c_int = -1;

// ---------------------------------------------------------------------------
// Flat cache (PHP 8.4+ only — requires the opcache restart hook)
// ---------------------------------------------------------------------------

/// Pointer to a `mmap(MAP_SHARED|MAP_ANON)` page with the following layout:
///
/// ```text
/// offset 0: AtomicU32  — function index counter (dense sequential IDs)
/// offset 4: AtomicU32  — generation counter (bumped on opcache restart)
/// ```
///
/// Shared across all forked worker processes. Initialized in [`startup`],
/// unmapped in [`shutdown`].
#[cfg(php_opcache_restart_hook)]
static mut SHARED_PAGE: *mut u8 = ptr::null_mut();

/// Size of the `u32` index prefix prepended to each SHM entry (PHP 8.4+).
#[cfg(php_opcache_restart_hook)]
const INDEX_PREFIX_SIZE: usize = mem::size_of::<u32>();

/// Returns a shared reference to the profiler globals for the current
/// thread/process. Fields use interior mutability (`Cell`/`RefCell`).
///
/// # Safety
/// Must be called after `ginit` and before `gshutdown`.
#[cfg(php_opcache_restart_hook)]
#[inline(always)]
unsafe fn globals() -> &'static crate::module_globals::ProfilerGlobals {
    unsafe { &*crate::module_globals::get_profiler_globals() }
}

/// Counts how many times an SHM index was found to be outside the
/// process-local cache bounds. This should not happen under normal
/// operation (activate pre-sizes the cache), but acts as a canary for
/// bugs or unexpected opcache behavior.
#[cfg(php_opcache_restart_hook)]
pub static SHM_INDEX_OUT_OF_BOUNDS: AtomicU64 = AtomicU64::new(0);

/// Returns a reference to the shared function index counter (offset 0).
///
/// # Safety
/// [`SHARED_PAGE`] must have been initialized by [`startup`].
#[cfg(php_opcache_restart_hook)]
#[inline(always)]
unsafe fn shared_counter() -> &'static AtomicU32 {
    // SAFETY: SHARED_PAGE points to a page-aligned mmap'd region that is
    // at least 8 bytes and suitably aligned for AtomicU32.
    unsafe { &*(SHARED_PAGE as *const AtomicU32) }
}

/// Returns a reference to the shared generation counter (offset 4).
///
/// # Safety
/// [`SHARED_PAGE`] must have been initialized by [`startup`].
#[cfg(php_opcache_restart_hook)]
#[inline(always)]
unsafe fn shared_generation() -> &'static AtomicU32 {
    // SAFETY: SHARED_PAGE is page-aligned; offset 4 is still within the
    // page and properly aligned for AtomicU32.
    unsafe { &*((SHARED_PAGE as *const AtomicU32).add(1)) }
}

// ---------------------------------------------------------------------------
// Persist hooks and SHM string encoding (PHP 8.4+ only)
// ---------------------------------------------------------------------------

/// Platform alignment, matching `PLATFORM_ALIGNMENT` in PHP's
/// `zend_shared_alloc.h`. On 64-bit this is 8.
#[cfg(php_opcache_restart_hook)]
const PLATFORM_ALIGNMENT: usize = mem::size_of::<usize>();

/// Equivalent to PHP's `ZEND_ALIGNED_SIZE` macro.
#[cfg(php_opcache_restart_hook)]
#[inline]
const fn zend_aligned_size(size: usize) -> usize {
    (size + PLATFORM_ALIGNMENT - 1) & !(PLATFORM_ALIGNMENT - 1)
}

/// Maximum string length we'll cache. Matches `STR_LEN_LIMIT` in
/// `stack_walking.rs`.
#[cfg(php_opcache_restart_hook)]
const STR_LEN_LIMIT: usize = u16::MAX as usize;

/// Acquires a `reserved[]` slot via `zend_get_resource_handle` and, on
/// PHP 8.4+, configures the persist hooks for SHM op-array caching.
///
/// Must be called during PHP module init on a `ZendExtension` that has not
/// yet been registered (the engine copies the struct during registration).
///
/// # Safety
/// Must be called during module init (minit), single-threaded.
pub unsafe fn minit(extension: &mut ZendExtension) -> bindings::ZendResult {
    // SAFETY: extension.name is a valid C string set during construction.
    let handle = unsafe { bindings::zend_get_resource_handle(extension.name) };
    if handle < 0 {
        return bindings::ZendResult::Failure;
    }
    // SAFETY: minit is single-threaded.
    unsafe { RESOURCE_HANDLE = handle };

    extension.resource_number = handle;

    // Persist hooks are only useful on PHP 8.4+ where we have the flat
    // cache and the opcache restart hook for invalidation. On 8.0–8.3,
    // Layer 2 (per-request run_time_cache) handles user function caching.
    #[cfg(php_opcache_restart_hook)]
    {
        extension.op_array_persist_calc = Some(op_array_persist_calc);
        extension.op_array_persist = Some(op_array_persist);
    }

    bindings::ZendResult::Success
}

/// Allocates the shared mmap page for the atomic counter.
///
/// Must be called during zend extension startup (after `minit` but before
/// any worker forks), so the page is inherited by all forked workers.
///
/// # Safety
/// Must be called during zend extension startup, single-threaded.
#[cfg(php_opcache_restart_hook)]
pub unsafe fn startup() -> ZendResult {
    // Allocate a shared page for the atomic counter. MAP_SHARED + MAP_ANON
    // means the page is inherited by forked workers and visible across
    // processes. The counter starts at 0 because mmap zero-initializes.
    let page = unsafe {
        libc::mmap(
            ptr::null_mut(),
            page_size(),
            libc::PROT_READ | libc::PROT_WRITE,
            libc::MAP_SHARED | libc::MAP_ANON,
            -1,
            0,
        )
    };
    if page == libc::MAP_FAILED {
        return ZendResult::Failure;
    }
    // SAFETY: startup is single-threaded.
    unsafe { SHARED_PAGE = page as *mut u8 };

    ZendResult::Success
}

/// Unmaps the shared page and clears the local cache.
///
/// # Safety
/// Must be called during zend extension shutdown, single-threaded.
#[cfg(php_opcache_restart_hook)]
pub unsafe fn shutdown() {
    // SAFETY: SHARED_PAGE was set by startup (or is still null if it failed).
    let page = unsafe { SHARED_PAGE };
    if !page.is_null() {
        unsafe { libc::munmap(page as *mut libc::c_void, page_size()) };
        unsafe { SHARED_PAGE = ptr::null_mut() };
    }
    let g = unsafe { globals() };
    g.local_cache.borrow_mut().clear();
    g.local_generation.set(0);
}

/// Pre-sizes the process-local cache to accommodate any new indices
/// assigned since the last call. Also detects opcache restarts via the
/// shared generation counter and clears the local cache when a restart
/// is detected.
///
/// This should be called at the start of each request (`activate`).
///
/// # Safety
/// `SHARED_PAGE` must have been initialized by [`startup`].
/// Must be called from the main thread (NTS) or with appropriate
/// thread-local storage (ZTS).
#[cfg(php_opcache_restart_hook)]
pub unsafe fn activate() {
    let page = unsafe { SHARED_PAGE };
    if page.is_null() {
        return;
    }

    let g = unsafe { globals() };

    // Check for opcache restart: if the shared generation has advanced
    // past our local snapshot, all SHM indices are invalidated.
    let gen = unsafe { shared_generation() }.load(Acquire);
    if gen != g.local_generation.get() {
        g.local_generation.set(gen);
        g.local_cache.borrow_mut().clear();
    }

    let counter = unsafe { shared_counter() }.load(Acquire) as usize;
    let mut cache = g.local_cache.borrow_mut();
    if counter > cache.len() {
        // Extend with default (empty) entries. Existing entries are
        // untouched — this is the whole point of the cross-request cache.
        cache.resize(counter, FunctionId2::default());
    }
}

/// Returns the OS page size.
#[cfg(php_opcache_restart_hook)]
fn page_size() -> usize {
    // SAFETY: sysconf is always safe to call with _SC_PAGESIZE.
    unsafe { libc::sysconf(libc::_SC_PAGESIZE) as usize }
}

/// Computes the FQN and filename strings for an op_array, returning them as
/// lossy-UTF-8 [`Cow<str>`] values.
///
/// All allocations are fallible (no panics). Returns `None` on OOM.
#[cfg(php_opcache_restart_hook)]
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
#[cfg(php_opcache_restart_hook)]
const LEN_PREFIX_SIZE: usize = mem::size_of::<u16>();

/// Computes the total SHM size needed for the given strings (unaligned).
///
/// Layout: `[u32 index][u16 fn_len][fn_bytes][\0][u16 file_len][file_bytes][\0]`
#[cfg(php_opcache_restart_hook)]
#[inline(always)]
fn compute_total_size(function_name: &str, filename: &str) -> usize {
    // Both strings are bounded by STR_LEN_LIMIT (u16::MAX), so these
    // additions cannot overflow on any platform with usize >= 32 bits.
    let fn_entry = LEN_PREFIX_SIZE + function_name.len() + 1;
    let file_entry = LEN_PREFIX_SIZE + filename.len() + 1;
    INDEX_PREFIX_SIZE + fn_entry + file_entry
}

/// Resolves the op_array pointer and computes the SHM strings and aligned
/// size. Shared by both persist hooks.
///
/// # Safety
/// `op_array` must be a valid pointer provided by the engine during opcache
/// persistence.
#[cfg(php_opcache_restart_hook)]
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
#[cfg(php_opcache_restart_hook)]
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
/// The opcache SHM lock is held, serializing calls across processes.
#[cfg(php_opcache_restart_hook)]
#[export_name = "ddog_php_prof_op_array_persist"]
#[cfg_attr(not(debug_assertions), no_panic::no_panic)]
pub unsafe extern "C" fn op_array_persist(op_array: *mut zend_op_array, mem: *mut c_void) -> usize {
    let handle = unsafe { RESOURCE_HANDLE };

    let Some((function_name, filename, aligned_total)) = (unsafe { prepare_persist(op_array) })
    else {
        return 0;
    };

    let dst = mem as *mut u8;

    // Prepend a dense sequential index for the flat cache.
    // The opcache SHM lock serializes persist calls, but we still use
    // Release ordering so that workers reading with Acquire see the
    // index and the associated SHM string data consistently.
    let index = if unsafe { !SHARED_PAGE.is_null() } {
        unsafe { shared_counter() }.fetch_add(1, Release)
    } else {
        // Shared page not available (shouldn't happen if startup succeeded).
        0
    };
    unsafe { core::ptr::write_unaligned(dst as *mut u32, index) };
    let cursor = unsafe { dst.add(INDEX_PREFIX_SIZE) };

    // Write the two length-prefixed strings into SHM.
    let cursor = write_shm_str(cursor, &function_name);
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
#[cfg(php_opcache_restart_hook)]
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
/// For internal (native) functions, the [`FunctionId2`] is stored directly
/// in `reserved[handle]` via CAS and returned without any dictionary
/// lookup on the hot path.
///
/// For user functions (op_arrays), the data lives in opcache SHM with a
/// flat cache for O(1) cross-request lookups.
///
/// Returns `None` if the function has no cached data (no opcache, no
/// reserved handle, trampoline, or dictionary insertion failure).
///
/// # Safety
/// The caller must ensure `func` is a valid reference to a live
/// `zend_function`.
#[cfg(php_opcache_restart_hook)]
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

    // Op-array path: data lives in opcache SHM with a dense index prefix.
    let ptr = unsafe { func.op_array.reserved[handle] };
    if ptr.is_null() {
        return None;
    }

    let base = ptr as *const u8;

    // Read the dense index assigned during op_array_persist.
    let index = unsafe { core::ptr::read_unaligned(base as *const u32) } as usize;

    // Fast path: check the thread/process-local cache.
    let cache = unsafe { globals() }.local_cache.borrow_mut();
    if index >= cache.len() {
        // Index is outside the vec that was pre-sized in activate. This
        // shouldn't happen under normal operation. Track it and fall back
        // to a plain dictionary insertion without caching.
        drop(cache);
        SHM_INDEX_OUT_OF_BOUNDS.fetch_add(1, Relaxed);
        return insert_from_shm(base);
    }

    let cached = cache[index];
    if !cached.is_empty() {
        return Some(cached);
    }

    // Cache miss (empty slot) — populate from SHM strings.
    // Drop the borrow before calling insert_from_shm, which doesn't
    // need the cache. Re-borrow afterwards to store the result.
    drop(cache);
    let function_id = insert_from_shm(base)?;
    unsafe { globals() }.local_cache.borrow_mut()[index] = function_id;
    Some(function_id)
}

/// Reads the SHM strings after the index prefix and inserts them into the
/// global [`ProfilesDictionary`], returning the resulting [`FunctionId2`].
///
/// Used by the flat cache path on cache misses and OOB fallback.
///
/// # Safety
/// `base` must point to a valid SHM entry written by [`op_array_persist`],
/// with the `u32` index prefix at offset 0.
#[cfg(php_opcache_restart_hook)]
#[inline(always)]
unsafe fn insert_from_shm(base: *const u8) -> Option<FunctionId2> {
    let cursor = unsafe { base.add(INDEX_PREFIX_SIZE) };
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
#[cfg(php_opcache_restart_hook)]
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

/// Resets the shared function counter and bumps the generation counter.
/// Also clears this process's local cache immediately.
///
/// Other worker processes will detect the generation change on their next
/// [`activate`] call and clear their own caches then.
///
/// # Safety
/// Must be called from the opcache restart hook (single-threaded in the
/// process that triggers the restart).
#[cfg(php_opcache_restart_hook)]
pub unsafe fn opcache_restart() {
    let page = unsafe { SHARED_PAGE };
    if !page.is_null() {
        unsafe { shared_counter() }.store(0, Release);
        // Bump generation so all workers detect the restart.
        unsafe { shared_generation() }.fetch_add(1, Release);
    }
    // Clear this thread's cache immediately.
    unsafe { globals() }.local_cache.borrow_mut().clear();
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
#[cfg(php_opcache_restart_hook)]
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
