use crate::allocation;
use core::cell::Cell;
use core::ffi::c_void;
use core::ptr;

#[cfg(php_zend_mm_set_custom_handlers_ex)]
use crate::allocation::allocation_ge84::ZendMMState;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
use crate::allocation::allocation_le83::ZendMMState;

#[repr(C)]
pub struct ProfilerGlobals {
    /// Wrapped in `Cell` to prevent torn reads/writes when allocation hooks
    /// are called re-entrantly during `rinit()`/`rshutdown()`.
    pub zend_mm_state: Cell<ZendMMState>,
    pub opcache_policy_initialized: Cell<bool>,
    pub opcache_enabled: Cell<bool>,
    pub opcache_file_cache_enabled: Cell<bool>,
    pub cli_opcache_enable_initialized: Cell<bool>,
    pub cli_opcache_enable: Cell<bool>,
}

/// We need TSRM to call into GINIT and GSHUTDOWN to observe spawning and
/// joining threads. This will be pointed to by the
/// [`ModuleEntry::globals_id_ptr`] in the `zend_module_entry` and the TSRM
/// will store it's thread-safe-resource id here; see:
/// <https://github.com/php/php-src/blob/5ce36453d66143548485cb57fb19bf4157ab60c2/Zend/zend_API.h#L253>
#[cfg(php_zts)]
pub static mut GLOBALS_ID: i32 = 0;

/// Module globals for NTS builds. In NTS mode, PHP uses this static directly.
/// The `globals_ctor` function will re-initialize this (though it's already
/// initialized here).
#[cfg(not(php_zts))]
pub static mut GLOBALS: ProfilerGlobals = ProfilerGlobals {
    zend_mm_state: Cell::new(ZendMMState::new()),
    opcache_policy_initialized: Cell::new(false),
    opcache_enabled: Cell::new(false),
    opcache_file_cache_enabled: Cell::new(false),
    cli_opcache_enable_initialized: Cell::new(false),
    cli_opcache_enable: Cell::new(false),
};

#[cfg(php_zts)]
mod zts {
    use core::ffi::c_void;

    extern "C" {
        fn tsrm_get_ls_cache() -> *mut c_void;
    }

    #[inline]
    pub unsafe fn tsrmg_bulk(id: i32) -> *mut c_void {
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
/// # Safety
/// - Must be called during or after `GINIT` has been called for the current
///   thread. In ZTS builds, PHP allocates the TSRM slot for the thread before
///   calling `globals_ctor`, so the slot is available during `GINIT` (but it
///   doesn't really make sense to do, you are given a pointer to it already
///   in `ginit`).
/// - Must not be called after `GSHUTDOWN`.
#[inline]
pub unsafe fn get_profiler_globals() -> *mut ProfilerGlobals {
    #[cfg(php_zts)]
    {
        // SAFETY: As long as this is called during the times documented by
        // our own safety requirements, GLOBALS_ID will be set by PHP.
        let id = ptr::addr_of!(GLOBALS_ID).read();
        zts::tsrmg_bulk(id).cast()
    }

    #[cfg(not(php_zts))]
    {
        ptr::addr_of_mut!(GLOBALS)
    }
}

#[inline]
pub unsafe fn request_opcache_policy_initialized() -> bool {
    (&*get_profiler_globals()).opcache_policy_initialized.get()
}

#[inline]
pub unsafe fn request_opcache_enabled() -> bool {
    (&*get_profiler_globals()).opcache_enabled.get()
}

#[inline]
pub unsafe fn request_opcache_file_cache_enabled() -> bool {
    (&*get_profiler_globals()).opcache_file_cache_enabled.get()
}

#[inline]
pub unsafe fn cached_cli_opcache_enable_state() -> (bool, bool) {
    let globals = &*get_profiler_globals();
    (
        globals.cli_opcache_enable_initialized.get(),
        globals.cli_opcache_enable.get(),
    )
}

#[no_mangle]
pub unsafe extern "C" fn ddog_php_prof_set_cached_request_opcache_policy(
    opcache_enabled: bool,
    opcache_file_cache_enabled: bool,
) {
    let globals = &*get_profiler_globals();
    globals.opcache_policy_initialized.set(true);
    globals.opcache_enabled.set(opcache_enabled);
    globals
        .opcache_file_cache_enabled
        .set(opcache_file_cache_enabled);
}

#[export_name = "ddog_php_prof_get_cached_cli_opcache_enable_state"]
pub unsafe extern "C" fn get_cached_cli_opcache_enable_state_ffi(
    initialized: *mut bool,
    enabled: *mut bool,
) {
    let (cached, value) = cached_cli_opcache_enable_state();
    if let Some(initialized) = initialized.as_mut() {
        *initialized = cached;
    }
    if let Some(enabled) = enabled.as_mut() {
        *enabled = value;
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_php_prof_set_cached_cli_opcache_enable_state(
    initialized: bool,
    enabled: bool,
) {
    let globals = &*get_profiler_globals();
    globals.cli_opcache_enable_initialized.set(initialized);
    globals.cli_opcache_enable.set(enabled);
}

/// Initializes the module globals. Called by PHP during thread initialization (GINIT).
///
/// # Safety
/// - Must be called by PHP's module initialization system.
#[export_name = "ddog_php_prof_ginit"]
pub unsafe extern "C" fn ginit(_globals_ptr: *mut c_void) {
    #[cfg(php_zts)]
    crate::timeline::timeline_ginit();

    // Initialize ZendMMState in PHP globals for ZTS builds. For NTS builds,
    // this was already done in its const initializer.
    #[cfg(php_zts)]
    {
        let globals = _globals_ptr.cast::<ProfilerGlobals>();
        (*globals).zend_mm_state = Cell::new(ZendMMState::new());
        (*globals).opcache_policy_initialized = Cell::new(false);
        (*globals).opcache_enabled = Cell::new(false);
        (*globals).opcache_file_cache_enabled = Cell::new(false);
        (*globals).cli_opcache_enable_initialized = Cell::new(false);
        (*globals).cli_opcache_enable = Cell::new(false);
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
    crate::timeline::timeline_gshutdown();

    // TODO: Florian, do we need this?
    // let globals = globals_ptr.cast::<ProfilerGlobals>();
    // (*globals).zend_mm_state = ZendMMState::new();

    // SAFETY: this is called in thread gshutdown as expected, no other places.
    allocation::gshutdown();
}
