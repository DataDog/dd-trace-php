mod profiling_stats;

pub use profiling_stats::*;

use crate::bindings::{
    zend_mm_heap, VmMmCustomAllocFn, VmMmCustomFreeFn, VmMmCustomGcFn, VmMmCustomReallocFn,
    VmMmCustomShutdownFn,
};
use crate::config::SystemSettings;
use crate::module_globals;
use crate::profiling::Profiler;
use crate::{RefCellExt, REQUEST_LOCALS};
use core::cell::Cell;
use core::ffi::c_int;
use core::ptr;
use libc::{c_char, size_t};
use log::{debug, trace};
use rand_distr::{Distribution, Poisson};
use std::ffi::c_void;
use std::ffi::CStr;
use std::num::{NonZero, NonZeroU32, NonZeroU64};
use std::sync::atomic::{AtomicU64, Ordering};

use rand::rngs::ThreadRng;

use crate::universal;

pub mod allocation_ge84;
pub mod allocation_le83;

/// Unified ZendMMState that wraps either the PHP 8.4+ or PHP 8.3- variant.
/// The correct variant is selected at runtime based on whether
/// `zend_mm_set_custom_handlers_ex` is available.
#[derive(Copy, Clone)]
pub enum ZendMMState {
    Ge84(allocation_ge84::ZendMMState),
    Le83(allocation_le83::ZendMMState),
}

impl ZendMMState {
    #[allow(clippy::new_without_default)]
    pub fn new() -> Self {
        if universal::has_zend_mm_set_custom_handlers_ex() {
            ZendMMState::Ge84(allocation_ge84::ZendMMState::new())
        } else {
            ZendMMState::Le83(allocation_le83::ZendMMState::new())
        }
    }

    pub const fn le83_default() -> Self {
        ZendMMState::Le83(allocation_le83::ZendMMState::new())
    }
}

/// Gets a pointer to the Cell<ZendMMState> from PHP globals.
///
/// # Safety
///
/// The pointer returned must not be used beyond GSHUTDOWN.
#[inline]
pub(crate) unsafe fn get_zend_mm_state(php_thread: crate::OnPhpThread) -> *mut Cell<ZendMMState> {
    let globals = module_globals::get_profiler_globals(php_thread);
    ptr::addr_of_mut!((*globals).zend_mm_state)
}

/// Macros for accessing the ge84 variant of ZendMMState from PHP globals.
/// Must only be called when running on PHP 8.4+ (has_zend_mm_set_custom_handlers_ex).
#[macro_export]
macro_rules! tls_zend_mm_state_copy_ge84 {
    ($php_thread:expr) => {
        match unsafe { (*$crate::allocation::get_zend_mm_state($php_thread)).get() } {
            $crate::allocation::ZendMMState::Ge84(s) => s,
            _ => unreachable!("tls_zend_mm_state_copy_ge84 called on non-ge84 state"),
        }
    };
}

#[macro_export]
macro_rules! tls_zend_mm_state_get_ge84 {
    ($php_thread:expr, $x:ident) => {
        match unsafe { (*$crate::allocation::get_zend_mm_state($php_thread)).get() } {
            $crate::allocation::ZendMMState::Ge84(s) => s.$x,
            _ => unreachable!("tls_zend_mm_state_get_ge84 called on non-ge84 state"),
        }
    };
}

#[macro_export]
macro_rules! tls_zend_mm_state_set_ge84 {
    ($php_thread:expr, $x:expr) => {{
        let value = $x;
        unsafe {
            (*$crate::allocation::get_zend_mm_state($php_thread))
                .set($crate::allocation::ZendMMState::Ge84(value));
        }
    }};
}

/// Macros for accessing the le83 variant of ZendMMState from PHP globals.
/// Must only be called when running on PHP 8.3- (!has_zend_mm_set_custom_handlers_ex).
#[macro_export]
macro_rules! tls_zend_mm_state_copy_le83 {
    ($php_thread:expr) => {
        match unsafe { (*$crate::allocation::get_zend_mm_state($php_thread)).get() } {
            $crate::allocation::ZendMMState::Le83(s) => s,
            _ => unreachable!("tls_zend_mm_state_copy_le83 called on non-le83 state"),
        }
    };
}

#[macro_export]
macro_rules! tls_zend_mm_state_get_le83 {
    ($php_thread:expr, $x:ident) => {
        match unsafe { (*$crate::allocation::get_zend_mm_state($php_thread)).get() } {
            $crate::allocation::ZendMMState::Le83(s) => s.$x,
            _ => unreachable!("tls_zend_mm_state_get_le83 called on non-le83 state"),
        }
    };
}

#[macro_export]
macro_rules! tls_zend_mm_state_set_le83 {
    ($php_thread:expr, $x:expr) => {{
        let value = $x;
        unsafe {
            (*$crate::allocation::get_zend_mm_state($php_thread))
                .set($crate::allocation::ZendMMState::Le83(value));
        }
    }};
}

/// Default sampling interval in bytes (4 MiB).
// SAFETY: value is > 0.
pub const DEFAULT_ALLOCATION_SAMPLING_INTERVAL: NonZeroU32 =
    unsafe { NonZero::new_unchecked(1024 * 4096) };

/// Sampling distance feed into poison sampling algo. This must be > 0.
pub static ALLOCATION_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(DEFAULT_ALLOCATION_SAMPLING_INTERVAL.get() as u64);

/// This will store the count of allocations (including reallocations) during
/// a profiling period. This will overflow when doing more than u64::MAX
/// allocations, which seems big enough to ignore.
#[cfg(feature = "debug_stats")]
pub static ALLOCATION_PROFILING_COUNT: AtomicU64 = AtomicU64::new(0);

/// This will store the accumulated size of all allocations in bytes during the
/// profiling period. This will overflow when allocating more than 18 exabyte
/// of memory (u64::MAX) which might not happen, so we can ignore this.
#[cfg(feature = "debug_stats")]
pub static ALLOCATION_PROFILING_SIZE: AtomicU64 = AtomicU64::new(0);

pub struct AllocationProfilingStats {
    /// number of bytes until next sample collection
    next_sample: i64,
    poisson: Poisson<f64>,
    rng: ThreadRng,
}

impl AllocationProfilingStats {
    fn new(sampling_distance: NonZeroU64) -> AllocationProfilingStats {
        // SAFETY: this will only error if lambda <= 0, and it's NonZeroU64.
        let poisson = unsafe { Poisson::new(sampling_distance.get() as f64).unwrap_unchecked() };
        let mut stats = AllocationProfilingStats {
            next_sample: 0,
            poisson,
            rng: rand::thread_rng(),
        };
        stats.next_sampling_interval();
        stats
    }

    fn next_sampling_interval(&mut self) {
        self.next_sample = self.poisson.sample(&mut self.rng) as i64;
    }

    fn should_collect_allocation(&mut self, len: size_t) -> bool {
        self.next_sample -= len as i64;

        if self.next_sample > 0 {
            return false;
        }

        self.next_sampling_interval();

        true
    }
}

#[cold]
pub fn collect_allocation(len: size_t) {
    if let Some(profiler) = Profiler::get() {
        // Check if there's a pending time interrupt that we can handle now
        // instead of waiting for an interrupt handler. This is slightly more
        // accurate and efficient, win-win.
        let interrupt_count = REQUEST_LOCALS
            .try_with_borrow(|locals| locals.interrupt_count.swap(0, Ordering::SeqCst))
            .unwrap_or(0);

        // SAFETY: collect_allocation is called from an allocation hook on a PHP thread.
        let php_thread = unsafe { crate::OnPhpThread::new() };
        let execute_data = crate::universal::profiling_current_execute_data(php_thread);
        profiler.collect_allocations(
            execute_data,
            1_i64,
            len as i64,
            (interrupt_count > 0).then_some(interrupt_count),
            php_thread,
        );
    }
}

pub fn alloc_prof_startup() {
    if !universal::has_zend_mm_set_custom_handlers_ex() {
        allocation_le83::alloc_prof_startup();
    }
}

pub fn first_rinit(settings: &SystemSettings) {
    if !settings.profiling_allocation_enabled {
        return;
    }

    let sampling_distance = settings.profiling_allocation_sampling_distance;
    ALLOCATION_PROFILING_INTERVAL.store(sampling_distance.get() as u64, Ordering::Relaxed);

    trace!("Memory allocation profiling initialized with a sampling distance of {sampling_distance} bytes.");
}

/// # Safety
///
/// Must be called exactly once per extension minit.
pub unsafe fn minit(settings: &SystemSettings) {
    if !settings.profiling_allocation_enabled {
        return;
    }

    let sampling_distance = settings.profiling_allocation_sampling_distance;
    ALLOCATION_PROFILING_INTERVAL.store(sampling_distance.get() as u64, Ordering::Relaxed);

    // SAFETY: called in minit.
    unsafe { profiling_stats::minit(sampling_distance.into()) };

    trace!("Memory allocation profiling initialized with a sampling distance of {sampling_distance} bytes.");
}

/// # Safety
/// Must be called exactly once per PHP module request init (RINIT) on a PHP
/// thread within the GINIT–GSHUTDOWN window.
pub unsafe fn rinit(php_thread: crate::OnPhpThread) {
    let allocation_enabled = REQUEST_LOCALS
        .try_with_borrow(|locals| locals.system_settings().profiling_allocation_enabled)
        .unwrap_or_else(|err| {
            // Debug rather than error because this is every request, could
            // be very spammy.
            debug!("Allocation profiling rinit failed because it failed to borrow the request locals. Please report this to Datadog: {err}");
            false
        });

    if !allocation_enabled {
        return;
    }

    if universal::has_zend_mm_set_custom_handlers_ex() {
        allocation_ge84::alloc_prof_rinit(php_thread);
    } else {
        allocation_le83::alloc_prof_rinit(php_thread);
    }
}

/// # Safety
/// Must be called exactly once per PHP module request shutdown (RSHUTDOWN) on a PHP
/// thread within the GINIT–GSHUTDOWN window.
pub unsafe fn alloc_prof_rshutdown(php_thread: crate::OnPhpThread) {
    let allocation_enabled = REQUEST_LOCALS
        .try_with_borrow(|locals| locals.system_settings().profiling_allocation_enabled)
        .unwrap_or_else(|err| {
            // Debug rather than error because this is every request, could
            // be very spammy.
            debug!("Allocation profiling rshutdown failed because it failed to borrow the request locals. Please report this to Datadog: {err}");
            false
        });

    if !allocation_enabled {
        return;
    }

    if universal::has_zend_mm_set_custom_handlers_ex() {
        allocation_ge84::alloc_prof_rshutdown(php_thread);
    } else {
        allocation_le83::alloc_prof_rshutdown(php_thread);
    }
}

#[track_caller]
fn initialization_panic() -> ! {
    panic!("Allocation profiler was not initialized properly. Please fill an issue stating the PHP version and the backtrace from this panic.");
}

unsafe fn alloc_prof_panic_alloc(_len: size_t) -> *mut c_void {
    initialization_panic();
}

unsafe fn alloc_prof_panic_realloc(_prev_ptr: *mut c_void, _len: size_t) -> *mut c_void {
    initialization_panic();
}

unsafe fn alloc_prof_panic_free(_ptr: *mut c_void) {
    initialization_panic();
}

// --- JIT / opcache -----------------------------------------------------------

/// Returns the OPcache module's DL handle, or null if OPcache is not loaded.
fn opcache_handle() -> *mut c_void {
    let module = crate::find_module_entry(c"Zend OPcache");
    if module.is_null() {
        return ptr::null_mut();
    }
    // SAFETY: find_module_entry returns a valid module entry or null.
    unsafe { (*module).handle }
}

/// Returns the current value of a PHP INI setting as a raw `*const c_char`.
///
/// Returns null if the setting does not exist or `zend_ini_string` is
/// unavailable. The pointer is only valid while the PHP request is active.
unsafe fn ini_string(name: &CStr) -> *const c_char {
    type ZendIniStringFn = unsafe extern "C" fn(*const c_char, usize, c_int) -> *const c_char;
    let sym = universal::runtime::symbol_addr("zend_ini_string");
    if sym.is_null() {
        return ptr::null();
    }
    let f: ZendIniStringFn = core::mem::transmute(sym);
    f(name.as_ptr(), name.to_bytes().len(), 0)
}

/// Returns `true` for INI bool values PHP treats as truthy:
/// `"1"`, `"true"`, `"on"`, `"yes"` (case-insensitive).
fn parse_ini_bool(val: &str) -> bool {
    let lower = val.to_ascii_lowercase();
    matches!(lower.as_str(), "1" | "true" | "on" | "yes")
}

/// Returns `true` if PHP JIT is currently enabled.
///
/// JIT requires PHP 8.0+, a loaded OPcache extension, and the right INI
/// settings. Uses INI-based detection; `zend_jit_status()` is skipped
/// because it has known bugs in several released PHP versions and the
/// upstream fix has not yet shipped in all maintained branches.
pub(crate) fn jit_enabled() -> bool {
    use std::sync::atomic::Ordering::Relaxed;

    // JIT was introduced in PHP 8.0.
    if crate::RUNTIME_PHP_VERSION_ID.load(Relaxed) < 80000 {
        return false;
    }

    // No OPcache loaded → no JIT.
    if opcache_handle().is_null() {
        return false;
    }

    // From here we call into PHP's INI API.
    unsafe {
        // If opcache.enable is explicitly falsy, JIT is off.
        let enable = ini_string(c"opcache.enable");
        if !enable.is_null() {
            let s = CStr::from_ptr(enable).to_string_lossy();
            if !parse_ini_bool(&s) {
                return false;
            }
        }

        // For CLI SAPI, also require opcache.enable_cli to be truthy.
        let sapi_name_ptr = (*ptr::addr_of!(crate::bindings::sapi_module)).name;
        if !sapi_name_ptr.is_null() {
            let sapi_name = CStr::from_ptr(sapi_name_ptr).to_string_lossy();
            if sapi_name == "cli" {
                let enable_cli = ini_string(c"opcache.enable_cli");
                // NULL (not registered) or falsy → JIT disabled for CLI.
                if enable_cli.is_null() {
                    return false;
                }
                let s = CStr::from_ptr(enable_cli).to_string_lossy();
                if !parse_ini_bool(&s) {
                    return false;
                }
            }
        }

        // No buffer_size or buffer_size ≤ 0 → JIT is off.
        let buf_size = ini_string(c"opcache.jit_buffer_size");
        if buf_size.is_null() {
            return false;
        }
        let s = CStr::from_ptr(buf_size).to_string_lossy();
        // Parse the leading decimal digits; suffixes like K/M/G are ignored
        // for the sign check — any positive value means a buffer was set.
        let digits: String = s.chars().take_while(|c| c.is_ascii_digit()).collect();
        let num: i64 = digits.parse().unwrap_or(0);
        if num <= 0 {
            return false;
        }

        // "disable", "off", or "0" → JIT explicitly turned off.
        let jit = ini_string(c"opcache.jit");
        if jit.is_null() {
            return false;
        }

        // We only need to see if it matches certain preset values, we don't
        // need to allocate a string for this. Make a short buffer that's
        // longer than our longest string (so we don't truncate accidentally
        // and match a truncation).
        let mut buf = [0u8; 8];
        let s = CStr::from_ptr(jit).to_bytes();
        let len = s.len().max(buf.len());
        let slice = &mut buf[..len];
        slice.copy_from_slice(&s[..len]);
        !matches!(&*slice, b"" | b"disable" | b"off" | b"0")
    }
}

// --- zend_mm custom handler wrappers ----------------------------------------

use crate::universal::runtime;

type ZendMmSetCustomHandlersFn = unsafe extern "C" fn(
    *mut zend_mm_heap,
    Option<unsafe extern "C" fn(usize) -> *mut c_void>,
    Option<unsafe extern "C" fn(*mut c_void)>,
    Option<unsafe extern "C" fn(*mut c_void, usize) -> *mut c_void>,
);

/// Wrapper for PHP's zend_mm_set_custom_handlers (pre-8.4 API).
#[no_mangle]
pub extern "C" fn ddog_php_prof_zend_mm_set_custom_handlers(
    heap: *mut zend_mm_heap,
    malloc: Option<unsafe extern "C" fn(usize) -> *mut c_void>,
    free: Option<unsafe extern "C" fn(*mut c_void)>,
    realloc: Option<unsafe extern "C" fn(*mut c_void, usize) -> *mut c_void>,
) {
    let sym = runtime::symbol_addr("zend_mm_set_custom_handlers");
    if sym.is_null() {
        return;
    }
    let f: ZendMmSetCustomHandlersFn = unsafe { core::mem::transmute(sym) };
    unsafe { f(heap, malloc, free, realloc) }
}

type ZendMmSetCustomHandlersExFn = unsafe extern "C" fn(
    *mut zend_mm_heap,
    Option<VmMmCustomAllocFn>,
    Option<VmMmCustomFreeFn>,
    Option<VmMmCustomReallocFn>,
    Option<VmMmCustomGcFn>,
    Option<VmMmCustomShutdownFn>,
);

/// Wrapper for PHP's zend_mm_set_custom_handlers_ex (PHP 8.4+ API).
/// Resolved at runtime to avoid a hard ELF dependency on older PHP versions.
#[no_mangle]
pub unsafe extern "C" fn ddog_php_prof_zend_mm_set_custom_handlers_ex(
    heap: *mut zend_mm_heap,
    malloc: Option<VmMmCustomAllocFn>,
    free: Option<VmMmCustomFreeFn>,
    realloc: Option<VmMmCustomReallocFn>,
    gc: Option<VmMmCustomGcFn>,
    shutdown: Option<VmMmCustomShutdownFn>,
) {
    let sym = runtime::symbol_addr("zend_mm_set_custom_handlers_ex");
    if sym.is_null() {
        return;
    }
    let f: ZendMmSetCustomHandlersExFn = core::mem::transmute(sym);
    f(heap, malloc, free, realloc, gc, shutdown)
}

type ZendMmGetCustomHandlersExFn = unsafe extern "C" fn(
    *mut zend_mm_heap,
    *mut Option<VmMmCustomAllocFn>,
    *mut Option<VmMmCustomFreeFn>,
    *mut Option<VmMmCustomReallocFn>,
    *mut Option<VmMmCustomGcFn>,
    *mut Option<VmMmCustomShutdownFn>,
);

/// Wrapper for PHP's zend_mm_get_custom_handlers_ex (PHP 8.4+ API).
/// Resolved at runtime to avoid a hard ELF dependency on older PHP versions.
#[no_mangle]
pub unsafe extern "C" fn ddog_php_prof_zend_mm_get_custom_handlers_ex(
    heap: *mut zend_mm_heap,
    malloc: *mut Option<VmMmCustomAllocFn>,
    free: *mut Option<VmMmCustomFreeFn>,
    realloc: *mut Option<VmMmCustomReallocFn>,
    gc: *mut Option<VmMmCustomGcFn>,
    shutdown: *mut Option<VmMmCustomShutdownFn>,
) {
    let sym = runtime::symbol_addr("zend_mm_get_custom_handlers_ex");
    if sym.is_null() {
        return;
    }
    let f: ZendMmGetCustomHandlersExFn = core::mem::transmute(sym);
    f(heap, malloc, free, realloc, gc, shutdown)
}
