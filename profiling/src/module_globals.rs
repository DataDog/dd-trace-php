use crate::profiling::allocation;
use core::cell::{Cell, UnsafeCell};
use core::ffi::c_void;
use core::mem::MaybeUninit;
#[cfg(any(
    not(all(feature = "profiling", feature = "tracer")),
    target_os = "linux",
    test
))]
use core::ptr;
use core::sync::atomic::AtomicU32;

#[cfg(target_os = "linux")]
use crate::profiling::process_context::ProcessContextCache;
#[cfg(target_os = "linux")]
use core::cell::RefCell;

#[cfg(php_zend_mm_set_custom_handlers_ex)]
use crate::profiling::allocation::allocation_ge84::ZendMMState;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
use crate::profiling::allocation::allocation_le83::ZendMMState;

#[repr(C)]
pub struct ProfilerGlobals {
    /// Wrapped in `Cell` to prevent torn reads/writes when allocation hooks
    /// are called re-entrantly during `rinit()`/`rshutdown()`.
    pub zend_mm_state: Cell<ZendMMState>,
    /// Number of profiler time interrupts pending for this PHP thread.
    ///
    /// The profiler timer thread updates this through a pointer registered by
    /// the PHP thread, so the value must remain atomic despite living in
    /// thread-local PHP module globals.
    pub cpu_sample_count: AtomicU32,
    #[cfg(target_os = "linux")]
    pub(crate) process_context: RefCell<ProcessContextCache>,
    /// Per-thread allocation sampling state. Kept in PHP globals so allocator
    /// hooks can reuse an already-resolved TSRM cache instead of accessing Rust TLS.
    pub allocation_profiling_stats: UnsafeCell<MaybeUninit<allocation::AllocationProfilingStats>>,
}

/// Only used by unit tests, which don't link the real PHP engine or
/// ext/datadog.c: it stands in for the TSRM resource id that a dedicated
/// `zend_module_entry::globals_id_ptr` would otherwise receive. Otherwise
/// `datadog_globals.profiling_globals` are used: see [`get_profiler_globals`].
#[cfg(all(php_zts, test))]
pub static mut GLOBALS_ID: i32 = 0;

/// Module globals stand-in for unit tests on NTS builds, which don't link the
/// real PHP engine or ext/datadog.c.
#[cfg(all(not(php_zts), test))]
pub static mut GLOBALS: ProfilerGlobals = ProfilerGlobals {
    zend_mm_state: Cell::new(ZendMMState::new()),
    cpu_sample_count: AtomicU32::new(0),
    #[cfg(target_os = "linux")]
    process_context: RefCell::new(ProcessContextCache::new()),
    allocation_profiling_stats: UnsafeCell::new(MaybeUninit::uninit()),
};

#[cfg(php_zts)]
mod zts {
    use core::ffi::c_void;

    extern "C" {
        fn tsrm_get_ls_cache() -> *mut c_void;
    }

    #[inline]
    pub unsafe fn get_ls_cache() -> *mut c_void {
        tsrm_get_ls_cache()
    }

    #[inline]
    pub unsafe fn tsrmg_bulk(ls_cache: *mut c_void, id: i32) -> *mut c_void {
        let storage = *(ls_cache as *mut *mut *mut c_void); // void** storage

        // TSRM_UNSHUFFLE_RSRC_ID(id) is just `id - 1`.
        let idx = (id - 1) as usize;
        let slot = storage.add(idx);
        *slot
    }
}

#[cfg(php_zts)]
#[inline]
pub unsafe fn get_tsrm_ls_cache() -> *mut c_void {
    zts::get_ls_cache()
}

#[cfg(php_zts)]
#[inline]
pub unsafe fn get_tsrm_resource_from_cache(ls_cache: *mut c_void, id: i32) -> *mut c_void {
    zts::tsrmg_bulk(ls_cache, id)
}

#[cfg(all(php_zts, test))]
#[inline]
pub unsafe fn get_profiler_globals_from_cache(ls_cache: *mut c_void) -> *mut ProfilerGlobals {
    // SAFETY: As long as this is called during the times documented by
    // get_profiler_globals(), GLOBALS_ID will be set by PHP.
    let id = ptr::addr_of!(GLOBALS_ID).read();
    get_tsrm_resource_from_cache(ls_cache, id).cast()
}

#[cfg(all(php_zts, not(test)))]
#[inline]
pub unsafe fn get_profiler_globals_from_cache(_ls_cache: *mut c_void) -> *mut ProfilerGlobals {
    // Storage is owned by ext/datadog.c's shared `datadog_globals` outside
    // of tests. The C accessor uses PHP's static TSRMLS cache, matching
    // DATADOG_G access.
    get_profiler_globals()
}

/// Returns a pointer to the profiler globals for the current thread.
///
/// # Safety
/// - Must be called during or after `GINIT` has been called for the current
///   thread. In ZTS builds, PHP allocates the TSRM slot for the thread before
///   calling `globals_ctor`, so the slot is available during `GINIT` (but it
///   doesn't really make sense to do, you are given a pointer to it already
///   in `ginit`).
/// - Must not be called after `GSHUTDOWN`.
#[inline]
pub unsafe fn get_profiler_globals() -> *mut ProfilerGlobals {
    #[cfg(not(test))]
    {
        unsafe extern "C" {
            fn datadog_php_profiling_globals() -> *mut c_void;
        }
        datadog_php_profiling_globals().cast()
    }

    #[cfg(all(test, php_zts))]
    {
        get_profiler_globals_from_cache(get_tsrm_ls_cache())
    }

    #[cfg(all(not(php_zts), test))]
    {
        ptr::addr_of_mut!(GLOBALS)
    }
}

/// Initializes the module globals. Called by PHP during thread initialization (GINIT).
///
/// # Safety
/// - Must be called by PHP's module initialization system.
#[export_name = "ddog_php_prof_ginit"]
pub unsafe extern "C" fn ginit(_globals_ptr: *mut c_void) {
    #[cfg(php_zts)]
    crate::profiling::timeline::timeline_ginit();

    // Initialize ZTS globals for tests.
    #[cfg(any(php_zts, not(test)))]
    {
        let globals = _globals_ptr.cast::<ProfilerGlobals>();
        (*globals).zend_mm_state = Cell::new(ZendMMState::new());
        (*globals).cpu_sample_count = AtomicU32::new(0);
        #[cfg(target_os = "linux")]
        ptr::addr_of_mut!((*globals).process_context)
            .write(RefCell::new(ProcessContextCache::new()));
        (*globals).allocation_profiling_stats = UnsafeCell::new(MaybeUninit::uninit());
    }

    // SAFETY: this is called in thread ginit as expected, and no other places.
    allocation::ginit();
}

/// Shuts down the module globals. Called by PHP during thread shutdown (GSHUTDOWN).
///
/// # Safety
/// - Must be called by PHP's module shutdown system.
#[export_name = "ddog_php_prof_gshutdown"]
pub unsafe extern "C" fn gshutdown(_globals_ptr: *mut c_void) {
    #[cfg(php_zts)]
    crate::profiling::timeline::timeline_gshutdown();

    #[cfg(target_os = "linux")]
    {
        let globals = _globals_ptr.cast::<ProfilerGlobals>();
        if let Ok(mut cache) = (*globals).process_context.try_borrow_mut() {
            cache.reset();
        }
        // The NTS-test GLOBALS static is reused across ginit/gshutdown cycles,
        // so it must not be dropped in place.
        #[cfg(any(php_zts, not(test)))]
        ptr::drop_in_place(ptr::addr_of_mut!((*globals).process_context));
    }

    // SAFETY: this is called in thread gshutdown as expected, no other places.
    allocation::gshutdown();
}

#[no_mangle]
pub extern "C" fn ddog_php_prof_globals_size() -> usize {
    core::mem::size_of::<ProfilerGlobals>()
}

// Unit tests are not loaded by PHP, so provide the PHP globals and TSRM symbol
// needed to link code retained in the test executable.
#[cfg(test)]
mod test_symbols {
    #[cfg(not(php_zts))]
    #[export_name = "compiler_globals"]
    static mut TEST_COMPILER_GLOBALS: core::mem::MaybeUninit<
        crate::profiling::zend::zend_compiler_globals,
    > = core::mem::MaybeUninit::zeroed();

    #[cfg(php_zts)]
    #[no_mangle]
    unsafe extern "C" fn tsrm_get_ls_cache() -> *mut core::ffi::c_void {
        core::ptr::null_mut()
    }
}
