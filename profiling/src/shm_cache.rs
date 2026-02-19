//! SHM string table for the PHP profiler.
//!
//! All function names and filenames are interned into a shared-memory
//! [`ShmStringTable`] that is visible across forked worker processes.
//! Frames use [`ShmStringId`] pairs instead of heap-allocated strings.
//!
//! ## Lifecycle
//!
//! 1. **`minit`** (module init, PHP 8.0+): acquires a `reserved[]` slot
//!    via `zend_get_resource_handle` and sets the persist hooks.
//! 2. **`startup`** (zend extension startup, before forks): mmaps a
//!    region, writes [`ShmGlobals`] at offset 0 (with refcount=1),
//!    initializes the [`ShmStringTable`] in the remaining space, and
//!    interns sentinel strings.
//! 3. **`pre_intern_internal_functions`** (called right after `startup`,
//!    PHP 8.0+): iterates all internal functions and class methods,
//!    interns their names, and stores the [`ShmStringId`] in each
//!    function's `reserved[handle]` slot.
//! 4. **`op_array_persist`** (opcache, PHP 8.0+): interns function name
//!    and filename into the SHM table and packs two [`ShmStringId`] values
//!    into `op_array->reserved[handle]`.
//! 5. **`shutdown`** (zend extension shutdown): nulls the global pointer
//!    and decrements the refcount. If no [`ShmRef`] holders remain, the
//!    region is munmap'd immediately; otherwise the last [`ShmRef::drop`]
//!    does it.
//!
//! On PHP < 8.0, `reserved[]` is not used and `collect_call_frame` falls
//! back to sentinel strings like `"[user function]"` or
//! `"[internal function]"`.

use crate::bindings;
#[cfg(php_opcache_shm_cache)]
use crate::bindings::ZendExtension;
use libc::{c_int, c_void};
use libdd_profiling_shm::{ShmStringId, ShmStringTable, SHM_REGION_SIZE};
use std::ptr::{self, NonNull};
use std::sync::atomic::{AtomicPtr, AtomicU32, Ordering};

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

// TODO: revisit mmap size — could adjust ShmStringTable::init to accept
// smaller regions instead of over-allocating by size_of::<ShmGlobals>().
const TOTAL_MMAP_SIZE: usize = std::mem::size_of::<ShmGlobals>() + SHM_REGION_SIZE;

/// Global pointer to the active [`ShmGlobals`], which lives at offset 0
/// of the mmap'd region. Null when no region is active.
static SHM_GLOBALS_PTR: AtomicPtr<ShmGlobals> = AtomicPtr::new(ptr::null_mut());

/// Pre-interned string IDs, the SHM table handle, and a refcount that
/// controls the lifetime of the backing mmap region.
///
/// Stored at offset 0 of the mmap'd region. The [`ShmStringTable`] data
/// occupies the remaining bytes after this struct.
#[allow(dead_code)]
pub struct ShmGlobals {
    refcount: AtomicU32,

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
// backing memory is MAP_SHARED. ShmStringId is Copy. The refcount is atomic.
unsafe impl Send for ShmGlobals {}
unsafe impl Sync for ShmGlobals {}

/// A counted reference to [`ShmGlobals`]. When the last `ShmRef` is
/// dropped, the backing mmap region is munmap'd.
///
/// Clone increments the refcount; Drop decrements it using the same
/// fence protocol as `Arc`.
pub struct ShmRef {
    ptr: NonNull<ShmGlobals>,
}

// SAFETY: ShmGlobals is Send + Sync, and we manage lifetime via refcount.
unsafe impl Send for ShmRef {}
unsafe impl Sync for ShmRef {}

impl ShmRef {
    #[inline]
    pub fn globals(&self) -> &ShmGlobals {
        unsafe { self.ptr.as_ref() }
    }
}

impl Clone for ShmRef {
    fn clone(&self) -> Self {
        self.globals().refcount.fetch_add(1, Ordering::Relaxed);
        ShmRef { ptr: self.ptr }
    }
}

impl Drop for ShmRef {
    fn drop(&mut self) {
        let g = self.globals();
        if g.refcount.fetch_sub(1, Ordering::Release) == 1 {
            std::sync::atomic::fence(Ordering::Acquire);
            unsafe {
                libc::munmap(self.ptr.as_ptr() as *mut libc::c_void, TOTAL_MMAP_SIZE);
            }
        }
    }
}

/// Returns a reference to the active SHM globals, or `None` if
/// [`startup`] hasn't been called or [`shutdown`] has already run.
///
/// This is the hot-path accessor used by stack walking on PHP threads.
/// No refcount is touched — the pointer is valid because the PHP thread
/// that calls this is the same thread that will later call shutdown.
#[inline(always)]
pub fn shm_globals() -> Option<&'static ShmGlobals> {
    let ptr = SHM_GLOBALS_PTR.load(Ordering::Acquire);
    if ptr.is_null() {
        None
    } else {
        Some(unsafe { &*ptr })
    }
}

/// Acquires a counted reference ([`ShmRef`]) to the active SHM globals.
/// Returns `None` if no region is active.
///
/// The caller's `ShmRef` keeps the mmap region alive even after
/// [`shutdown`] nulls the global pointer.
pub fn acquire_ref() -> Option<ShmRef> {
    let ptr = SHM_GLOBALS_PTR.load(Ordering::Acquire);
    let nn = NonNull::new(ptr)?;
    let g = unsafe { nn.as_ref() };
    g.refcount.fetch_add(1, Ordering::Relaxed);
    Some(ShmRef { ptr: nn })
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

/// Allocates the SHM region, writes [`ShmGlobals`] at offset 0 (with
/// refcount=1), initializes the [`ShmStringTable`] in the remaining
/// space, and interns all sentinel/synthetic strings.
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
            TOTAL_MMAP_SIZE,
            libc::PROT_READ | libc::PROT_WRITE,
            libc::MAP_SHARED | libc::MAP_ANON,
            -1,
            0,
        )
    };
    if region_ptr == libc::MAP_FAILED {
        return bindings::ZendResult::Failure;
    }

    let globals_ptr = region_ptr as *mut ShmGlobals;

    // ShmStringTable sub-region starts right after ShmGlobals.
    let table_base = unsafe { (region_ptr as *mut u8).add(std::mem::size_of::<ShmGlobals>()) };
    let table_slice = ptr::slice_from_raw_parts_mut(table_base, SHM_REGION_SIZE);
    let Some(nn) = NonNull::new(table_slice) else {
        unsafe { libc::munmap(region_ptr, TOTAL_MMAP_SIZE) };
        return bindings::ZendResult::Failure;
    };

    let Some(table) = (unsafe { ShmStringTable::init(nn) }) else {
        unsafe { libc::munmap(region_ptr, TOTAL_MMAP_SIZE) };
        return bindings::ZendResult::Failure;
    };

    let intern = |s: &str| -> ShmStringId { table.intern(s).unwrap_or(ShmStringTable::EMPTY) };

    let trampoline = intern("[trampoline]");
    let user_function = intern("[user function]");
    let internal_function = intern("[internal function]");
    let truncated = intern("[truncated]");
    let idle = intern("[idle]");
    let gc = intern("[gc]");
    let include = intern("[include]");
    let require = intern("[require]");
    let include_unknown = intern("[]");
    let thread_start = intern("[thread start]");
    let thread_stop = intern("[thread stop]");
    let eval = intern("[eval]");
    let fatal = intern("[fatal]");
    let opcache_restart = intern("[opcache restart]");
    let php_open_tag = intern("<?php");
    let suspiciously_large = intern("[suspiciously large string]");

    let globals = ShmGlobals {
        refcount: AtomicU32::new(1),
        table,
        trampoline,
        user_function,
        internal_function,
        truncated,
        idle,
        gc,
        include,
        require,
        include_unknown,
        thread_start,
        thread_stop,
        eval,
        fatal,
        opcache_restart,
        php_open_tag,
        suspiciously_large,
    };

    unsafe { ptr::write(globals_ptr, globals) };
    SHM_GLOBALS_PTR.store(globals_ptr, Ordering::Release);

    bindings::ZendResult::Success
}

/// Nulls the global pointer and decrements the refcount. If this was
/// the last reference, the region is munmap'd immediately.
///
/// # Safety
/// Must be called during zend extension shutdown, single-threaded.
#[cfg_attr(not(debug_assertions), no_panic::no_panic)]
pub unsafe extern "C" fn shutdown() {
    let ptr = SHM_GLOBALS_PTR.swap(ptr::null_mut(), Ordering::AcqRel);
    if ptr.is_null() {
        return;
    }
    let g = unsafe { &*ptr };
    if g.refcount.fetch_sub(1, Ordering::Release) == 1 {
        std::sync::atomic::fence(Ordering::Acquire);
        unsafe { libc::munmap(ptr as *mut libc::c_void, TOTAL_MMAP_SIZE) };
    }
}

/// Acquires a `reserved[]` slot via `zend_get_resource_handle` and
/// configures the persist hooks.
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

/// Context passed through the C callback for pre-interning internal functions.
#[cfg(php_opcache_shm_cache)]
struct InternCtx {
    handle: usize,
    count: u32,
}

/// Callback invoked by `datadog_php_profiling_foreach_internal_function`
/// for each internal function/method.
#[cfg(php_opcache_shm_cache)]
#[cfg_attr(not(debug_assertions), no_panic::no_panic)]
unsafe extern "C" fn intern_internal_func(func: *mut zend_function, ctx: *mut c_void) {
    let ctx = unsafe { &mut *(ctx as *mut InternCtx) };
    let Some(func_ref) = (unsafe { func.as_ref() }) else {
        return;
    };
    let Some(g) = shm_globals() else { return };

    let Ok(name) = extract_function_name(func_ref) else {
        return;
    };
    let Some(name_id) = g.table.intern(&name) else {
        return;
    };

    let packed = pack(name_id, ShmStringTable::EMPTY);
    unsafe {
        *(*func)
            .internal_function
            .reserved
            .get_unchecked_mut(ctx.handle) = packed;
    }
    ctx.count += 1;
}

/// Pre-interns all internal function and method names into the SHM
/// string table. Must be called during zend extension startup, after
/// [`startup`] and [`minit`] have both succeeded.
#[cfg(php_opcache_shm_cache)]
pub fn pre_intern_internal_functions() {
    let Some(handle) = resource_handle() else {
        return;
    };

    let start = std::time::Instant::now();
    let mut ctx = InternCtx { handle, count: 0 };

    unsafe {
        bindings::datadog_php_profiling_foreach_internal_function(
            intern_internal_func,
            &mut ctx as *mut InternCtx as *mut c_void,
        );
    }

    let elapsed = start.elapsed();
    log::info!(
        "Pre-interned {} internal functions in {:.1}ms",
        ctx.count,
        elapsed.as_secs_f64() * 1000.0,
    );
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
