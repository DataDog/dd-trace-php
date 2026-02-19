//! SHM string table for the PHP profiler.
//!
//! All function names and filenames are interned into a shared-memory
//! [`ShmStringTable`] that is visible across forked worker processes.
//! Frames use [`ShmStringId`] pairs instead of heap-allocated strings.
//!
//! ## Lifecycle
//!
//! 1. **`startup`** (zend extension startup, before forks): mmaps a
//!    `SHM_REGION_SIZE` region, initializes the [`ShmStringTable`], and
//!    interns sentinel strings into [`ShmGlobals`].
//! 2. **`minit`**: acquires a `reserved[]` slot via
//!    `zend_get_resource_handle` and, on PHP 8.4+, sets the persist hooks.
//! 3. **`op_array_persist`** (PHP 8.4+, opcache): interns function name
//!    and filename into the SHM table and packs two [`ShmStringId`] values
//!    into `op_array->reserved[handle]`.
//! 4. **`shutdown`** (zend extension shutdown): unmaps the SHM region.
//!
//! On PHP versions without persist hooks, `reserved[]` is always zero and
//! `collect_call_frame` falls back to sentinel strings like
//! `"[user function]"` or `"[internal function]"`.

use crate::bindings;
#[cfg(php_opcache_shm_cache)]
use crate::bindings::ZendExtension;
use libc::{c_int, c_void};
use libdd_profiling_shm::{ShmStringId, ShmStringTable, SHM_REGION_SIZE};
use std::mem::MaybeUninit;
use std::ptr::{self, NonNull};
use std::sync::atomic::{AtomicBool, Ordering};

#[cfg(php_opcache_shm_cache)]
use crate::bindings::{
    _zend_op_array as zend_op_array, zai_str_from_zstr, zend_function, ZEND_ACC_CALL_VIA_TRAMPOLINE,
};
#[cfg(php_opcache_shm_cache)]
use crate::profiling::extract_function_name;
#[cfg(php_opcache_shm_cache)]
use crate::vec_ext;

/// The acquired `reserved[]` slot handle. -1 means not acquired.
static mut RESOURCE_HANDLE: c_int = -1;

/// Pointer to the mmap'd region backing the [`ShmStringTable`].
static mut SHM_REGION: *mut u8 = ptr::null_mut();

/// The global SHM string table and pre-interned sentinel IDs.
static mut SHM_GLOBALS: MaybeUninit<ShmGlobals> = MaybeUninit::uninit();

/// Guard: `true` after [`SHM_GLOBALS`] has been fully initialized.
static SHM_INITIALIZED: AtomicBool = AtomicBool::new(false);

/// Pre-interned string IDs and the SHM table handle.
#[allow(dead_code)]
pub struct ShmGlobals {
    pub table: ShmStringTable,

    // Sentinel IDs for fallback frames.
    pub trampoline: ShmStringId,
    pub user_function: ShmStringId,
    pub internal_function: ShmStringId,

    // Synthetic frame name IDs (interned at startup).
    pub truncated: ShmStringId,
    pub idle: ShmStringId,
    pub gc: ShmStringId,
    pub include: ShmStringId,
    pub require: ShmStringId,
    pub include_unknown: ShmStringId,
    pub thread_start: ShmStringId,
    pub thread_stop: ShmStringId,
    pub eval: ShmStringId,
    pub fatal: ShmStringId,
    pub opcache_restart: ShmStringId,
    pub php_open_tag: ShmStringId,
    pub suspiciously_large: ShmStringId,
}

// SAFETY: ShmStringTable uses internal synchronization (spinlock) and its
// backing memory is MAP_SHARED. ShmStringId is Copy. Access is mediated by
// the AtomicBool guard.
unsafe impl Send for ShmGlobals {}
unsafe impl Sync for ShmGlobals {}

/// Returns a reference to the initialized SHM globals, or `None` if
/// [`startup`] hasn't been called or failed.
#[inline(always)]
pub fn shm_globals() -> Option<&'static ShmGlobals> {
    if SHM_INITIALIZED.load(Ordering::Acquire) {
        // SAFETY: the Acquire load synchronizes with the Release store in
        // startup, guaranteeing SHM_GLOBALS is fully written. Using
        // addr_of! avoids creating a reference to the static mut.
        Some(unsafe { (*ptr::addr_of!(SHM_GLOBALS)).assume_init_ref() })
    } else {
        None
    }
}

/// Returns the `reserved[]` slot handle, or `None` if not acquired.
#[inline(always)]
pub fn resource_handle() -> Option<usize> {
    let h = unsafe { RESOURCE_HANDLE };
    if h >= 0 {
        Some(h as usize)
    } else {
        None
    }
}

// ─── Pack / unpack two ShmStringId into a pointer-sized value ────────

/// Packs two [`ShmStringId`] values (each ≤31 bits) into a `*mut c_void`.
#[cfg(php_opcache_shm_cache)]
#[inline]
fn pack(name: ShmStringId, file: ShmStringId) -> *mut c_void {
    let packed: u64 = (name.index() as u64) | ((file.index() as u64) << 32);
    packed as usize as *mut c_void
}

/// Unpacks a `*mut c_void` into two [`ShmStringId`] values.
/// Returns `(EMPTY, EMPTY)` if the pointer is null or the indices are
/// out of range.
#[inline]
pub fn unpack(ptr: *mut c_void) -> (ShmStringId, ShmStringId) {
    let packed = ptr as usize as u64;
    let name = ShmStringId::new(packed as u32).unwrap_or(ShmStringTable::EMPTY);
    let file = ShmStringId::new((packed >> 32) as u32).unwrap_or(ShmStringTable::EMPTY);
    (name, file)
}

// ─── Lifecycle ───────────────────────────────────────────────────────

/// Allocates the SHM region, initializes the [`ShmStringTable`], and
/// interns all sentinel/synthetic strings into [`ShmGlobals`].
///
/// Must be called during zend extension startup (before worker forks)
/// so the mmap'd region is inherited by all workers.
///
/// # Safety
/// Must be called during zend extension startup, single-threaded.
#[cfg_attr(not(debug_assertions), no_panic::no_panic)]
pub unsafe extern "C" fn startup() -> bindings::ZendResult {
    let region_ptr = unsafe {
        libc::mmap(
            ptr::null_mut(),
            SHM_REGION_SIZE,
            libc::PROT_READ | libc::PROT_WRITE,
            libc::MAP_SHARED | libc::MAP_ANON,
            -1,
            0,
        )
    };
    if region_ptr == libc::MAP_FAILED {
        return bindings::ZendResult::Failure;
    }

    unsafe { SHM_REGION = region_ptr as *mut u8 };

    let slice_ptr = ptr::slice_from_raw_parts_mut(region_ptr as *mut u8, SHM_REGION_SIZE);
    let Some(nn) = NonNull::new(slice_ptr) else {
        unsafe { libc::munmap(region_ptr, SHM_REGION_SIZE) };
        unsafe { SHM_REGION = ptr::null_mut() };
        return bindings::ZendResult::Failure;
    };

    let Some(table) = (unsafe { ShmStringTable::init(nn) }) else {
        unsafe { libc::munmap(region_ptr, SHM_REGION_SIZE) };
        unsafe { SHM_REGION = ptr::null_mut() };
        return bindings::ZendResult::Failure;
    };

    let intern = |s: &str| -> ShmStringId { table.intern(s).unwrap_or(ShmStringTable::EMPTY) };

    let globals = ShmGlobals {
        trampoline: intern("[trampoline]"),
        user_function: intern("[user function]"),
        internal_function: intern("[internal function]"),
        truncated: intern("[truncated]"),
        idle: intern("[idle]"),
        gc: intern("[gc]"),
        include: intern("[include]"),
        require: intern("[require]"),
        include_unknown: intern("[]"),
        thread_start: intern("[thread start]"),
        thread_stop: intern("[thread stop]"),
        eval: intern("[eval]"),
        fatal: intern("[fatal]"),
        opcache_restart: intern("[opcache restart]"),
        php_open_tag: intern("<?php"),
        suspiciously_large: intern("[suspiciously large string]"),
        table,
    };

    unsafe { ptr::addr_of_mut!(SHM_GLOBALS).write(MaybeUninit::new(globals)) };
    SHM_INITIALIZED.store(true, Ordering::Release);

    bindings::ZendResult::Success
}

/// Unmaps the SHM region.
///
/// # Safety
/// Must be called during zend extension shutdown, single-threaded.
#[cfg_attr(not(debug_assertions), no_panic::no_panic)]
pub unsafe extern "C" fn shutdown() {
    SHM_INITIALIZED.store(false, Ordering::Release);

    let region = unsafe { SHM_REGION };
    if !region.is_null() {
        unsafe { libc::munmap(region as *mut libc::c_void, SHM_REGION_SIZE) };
        unsafe { SHM_REGION = ptr::null_mut() };
    }
}

/// Acquires a `reserved[]` slot via `zend_get_resource_handle` and
/// configures the persist hooks for SHM op-array caching.
///
/// # Safety
/// Must be called during module init (minit), single-threaded.
#[cfg(php_opcache_shm_cache)]
pub unsafe fn minit(extension: &mut ZendExtension) -> bindings::ZendResult {
    let handle = unsafe { bindings::zend_get_resource_handle(extension.name) };
    if handle < 0 {
        return bindings::ZendResult::Failure;
    }
    unsafe { RESOURCE_HANDLE = handle };
    extension.resource_number = handle;

    extension.op_array_persist_calc = Some(op_array_persist_calc);
    extension.op_array_persist = Some(op_array_persist);

    bindings::ZendResult::Success
}

// ─── Persist hooks ───────────────────────────────────────────────────

/// Maximum string length we'll intern. Strings exceeding this are
/// replaced with `"[suspiciously large string]"`.
#[cfg(php_opcache_shm_cache)]
const STR_LEN_LIMIT: usize = u16::MAX as usize;

/// Computes the FQN and filename strings for an op_array, returning them
/// as lossy-UTF-8 [`Cow<str>`] values.
///
/// All allocations are fallible (no panics). Returns `None` on OOM.
#[cfg(php_opcache_shm_cache)]
#[inline(always)]
fn compute_shm_strings(
    op_array: &zend_op_array,
) -> Option<(
    std::borrow::Cow<'static, str>,
    std::borrow::Cow<'static, str>,
)> {
    let func: &zend_function =
        unsafe { &*(op_array as *const zend_op_array as *const zend_function) };

    let function_name = extract_function_name(func).ok()?;

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

/// `op_array_persist_calc` hook: returns 0 because we store data in the
/// SHM string table, not in opcache's SHM allocation.
#[cfg(php_opcache_shm_cache)]
#[export_name = "ddog_php_prof_op_array_persist_calc"]
#[cfg_attr(not(debug_assertions), no_panic::no_panic)]
pub unsafe extern "C" fn op_array_persist_calc(_op_array: *mut zend_op_array) -> usize {
    0
}

/// `op_array_persist` hook: interns function name and filename into the
/// SHM string table and packs the two [`ShmStringId`] values into
/// `op_array->reserved[handle]`.
#[cfg(php_opcache_shm_cache)]
#[export_name = "ddog_php_prof_op_array_persist"]
#[cfg_attr(not(debug_assertions), no_panic::no_panic)]
pub unsafe extern "C" fn op_array_persist(
    op_array: *mut zend_op_array,
    _mem: *mut c_void,
) -> usize {
    let handle = unsafe { RESOURCE_HANDLE };
    if handle < 0 {
        return 0;
    }

    let Some(g) = shm_globals() else { return 0 };
    let Some(op_array_ref) = (unsafe { op_array.as_ref() }) else {
        return 0;
    };

    // Skip trampolines — their reserved[] slots are uninitialized.
    let func: &zend_function =
        unsafe { &*(op_array_ref as *const zend_op_array as *const zend_function) };
    if unsafe { func.common.fn_flags } & ZEND_ACC_CALL_VIA_TRAMPOLINE != 0 {
        return 0;
    }

    let Some((function_name, filename)) = compute_shm_strings(op_array_ref) else {
        return 0;
    };

    let Some(name_id) = g.table.intern(&function_name) else {
        return 0;
    };
    let Some(file_id) = g.table.intern(&filename) else {
        return 0;
    };

    let op_array_mut = unsafe { &mut *op_array };
    unsafe {
        *op_array_mut.reserved.get_unchecked_mut(handle as usize) = pack(name_id, file_id);
    }

    0
}
