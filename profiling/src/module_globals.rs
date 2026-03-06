use crate::allocation;
use crate::allocation::{AllocationProfilingStats, ZendMMState};
use crate::bindings::zend_execute_data;
use core::cell::Cell;
use core::ffi::c_void;
use core::mem::MaybeUninit;
use core::ptr;
use std::cell::UnsafeCell;
use std::sync::atomic::AtomicBool;

use crate::universal;

#[repr(C)]
pub struct ProfilerGlobals {
    /// Wrapped in `Cell` to prevent torn reads/writes when allocation hooks
    /// are called re-entrantly during `rinit()`/`rshutdown()`.
    pub zend_mm_state: Cell<ZendMMState>,
    /// Allocation profiling stats for this thread/process. Initialized in
    /// ginit, dropped in gshutdown.
    pub allocation_profiling_stats: UnsafeCell<MaybeUninit<AllocationProfilingStats>>,
    /// Cached pointer to EG(vm_interrupt) for this thread/process.
    /// Computed once in ginit when executor_globals is first available.
    /// Null if the matrix entry or EG offset is not available.
    pub vm_interrupt_ptr: *const AtomicBool,
    /// Cached pointer to the EG(current_execute_data) slot for this thread/process.
    /// Dereference this pointer to read the current execute_data value.
    /// Computed once in ginit; null if unavailable.
    pub current_execute_data_ptr: *const *mut zend_execute_data,
    /// Cached pointer to the EG(active_fiber) slot for this thread/process.
    /// Dereference this pointer to read the currently running fiber (PHP 8.1+).
    /// Null on PHP < 8.1 or if the offset is unavailable.
    pub active_fiber_ptr: *const *mut c_void,
}

/// ZTS: TSRM stores the resource id here. Pointed to by ModuleEntry::globals_id_ptr.
pub static mut GLOBALS_ID: i32 = 0;

/// NTS: PHP uses this static directly. Pointed to by ModuleEntry::globals_ptr.
pub static mut GLOBALS: ProfilerGlobals = ProfilerGlobals {
    zend_mm_state: Cell::new(ZendMMState::le83_default()),
    allocation_profiling_stats: UnsafeCell::new(MaybeUninit::uninit()),
    vm_interrupt_ptr: ptr::null(),
    current_execute_data_ptr: ptr::null(),
    active_fiber_ptr: ptr::null(),
};

mod zts {
    use core::ffi::c_void;
    use core::sync::atomic::{AtomicUsize, Ordering};

    type TsrmGetLsCacheFn = unsafe extern "C" fn() -> *mut c_void;

    // Cache the resolved tsrm_get_ls_cache address. Hard-linking tsrm_get_ls_cache
    // causes undefined-symbol load failures on NTS builds. 0 = not yet resolved.
    static TSRM_FN: AtomicUsize = AtomicUsize::new(0);

    #[cold]
    unsafe fn resolve_tsrm() -> TsrmGetLsCacheFn {
        let addr = crate::universal::runtime::symbol_addr("tsrm_get_ls_cache") as usize;
        debug_assert!(addr != 0, "tsrm_get_ls_cache not found in ZTS build");
        TSRM_FN.store(addr, Ordering::Relaxed);
        core::mem::transmute(addr)
    }

    #[inline]
    pub unsafe fn tsrmg_bulk(id: i32) -> *mut c_void {
        let cached = TSRM_FN.load(Ordering::Relaxed);
        let tsrm_get_ls_cache: TsrmGetLsCacheFn = if cached != 0 {
            core::mem::transmute(cached)
        } else {
            resolve_tsrm()
        };
        let tls = tsrm_get_ls_cache() as *mut *mut *mut c_void;
        let storage = *tls; // void** storage

        // TSRM_UNSHUFFLE_RSRC_ID(id) is just `id - 1`.
        let idx = (id - 1) as usize;
        let slot = storage.add(idx);
        *slot
    }
}

/// Returns a pointer to the profiler globals for the current thread.
///
/// Requires [`crate::OnPhpThread`], which proves we are within the
/// GINIT–GSHUTDOWN window and therefore the TSRM slot (ZTS) or the global
/// (NTS) is valid.
#[inline]
pub fn get_profiler_globals(_: crate::OnPhpThread) -> *mut ProfilerGlobals {
    if universal::is_zts() {
        // SAFETY: OnPhpThread guarantees GINIT–GSHUTDOWN, so GLOBALS_ID and
        // tsrmg_bulk are safe to access/read/call.
        let id = unsafe { ptr::addr_of!(GLOBALS_ID).read() };
        unsafe { zts::tsrmg_bulk(id).cast() }
    } else {
        ptr::addr_of_mut!(GLOBALS)
    }
}

/// Initializes the module globals. Called by PHP during thread initialization (GINIT).
///
/// # Safety
/// - Must be called by PHP's module initialization system.
#[export_name = "ddog_php_prof_ginit"]
pub unsafe extern "C" fn ginit(_globals_ptr: *mut c_void) {
    crate::ON_PHP_THREAD_ACTIVE.with(|b| b.set(true));
    if universal::is_zts() {
        crate::timeline::timeline_ginit();
    }
    // Initialize zend_mm_state for both ZTS (per-thread TSRM slot) and NTS (GLOBALS).
    // Using get_profiler_globals() rather than _globals_ptr so NTS also gets
    // the correct runtime variant instead of keeping the le83_default().
    let globals = get_profiler_globals(crate::OnPhpThread::new());
    (*globals).zend_mm_state = Cell::new(ZendMMState::new());

    // Cache direct pointers to EG fields for this thread. executor_globals is
    // available here because TSRM has already set up the per-thread storage
    // before calling globals_ctor. These pointers remain valid for the lifetime
    // of this thread, so reading them later requires no TSRM lookup or offset
    // arithmetic.
    let cache = crate::executor_globals_cache();
    let entry = crate::matrix_entry();
    let eg = cache.get();
    if !eg.is_null() {
        let int_off = entry.offsets.eg_vm_interrupt;
        if int_off >= 0 {
            (*globals).vm_interrupt_ptr = eg.add(int_off as usize) as *const AtomicBool;
        }
        let ced_off = entry.offsets.eg_current_execute_data;
        if ced_off >= 0 {
            (*globals).current_execute_data_ptr =
                eg.add(ced_off as usize) as *const *mut zend_execute_data;
        }
        let af_off = entry.offsets.eg_active_fiber;
        if af_off >= 0 {
            (*globals).active_fiber_ptr = eg.add(af_off as usize) as *const *mut c_void;
        }
    }

    allocation::ginit();
}

/// Shuts down the module globals. Called by PHP during thread shutdown (GSHUTDOWN).
///
/// # Safety
/// - Must be called by PHP's module shutdown system.
#[export_name = "ddog_php_prof_gshutdown"]
pub unsafe extern "C" fn gshutdown(_globals_ptr: *mut c_void) {
    if universal::is_zts() {
        crate::timeline::timeline_gshutdown();
    }

    // SAFETY: this is called in thread gshutdown as expected, no other places.
    allocation::gshutdown();
    crate::ON_PHP_THREAD_ACTIVE.with(|b| b.set(false));
}
