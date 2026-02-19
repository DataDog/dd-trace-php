mod profiling_stats;

pub use profiling_stats::*;

use crate::bindings::{self as zend};
use crate::config::SystemSettings;
use crate::module_globals;
use crate::profiling::Profiler;
use crate::{RefCellExt, REQUEST_LOCALS};
use core::cell::Cell;
use core::ptr;
use libc::size_t;
use log::{debug, trace};
use rand_distr::{Distribution, Poisson};
use std::ffi::c_void;
use std::num::{NonZeroU32, NonZeroU64};
use std::sync::atomic::{AtomicU64, Ordering};

#[cfg(not(php_zts))]
use rand::rngs::StdRng;
#[cfg(php_zts)]
use rand::rngs::ThreadRng;
#[cfg(not(php_zts))]
use rand::SeedableRng;

#[cfg(php_zend_mm_set_custom_handlers_ex)]
use crate::allocation::allocation_ge84::ZendMMState;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
use crate::allocation::allocation_le83::ZendMMState;

/// Gets a pointer to the Cell<ZendMMState> from PHP globals.
///
/// # Safety
///
/// Must uphold safety conditions of [`module_globals::get_profiler_globals`].
#[inline]
pub(crate) unsafe fn get_zend_mm_state() -> *mut Cell<ZendMMState> {
    let globals = module_globals::get_profiler_globals();
    ptr::addr_of_mut!((*globals).zend_mm_state)
}

/// Macros for accessing ZendMMState from PHP globals.
/// These are shared between PHP 8.3- and 8.4+ implementations.
/// They are exported at the crate root and can be used in submodules.
#[macro_export]
macro_rules! tls_zend_mm_state_copy {
    () => {
        unsafe { (*$crate::allocation::get_zend_mm_state()).get() }
    };
}

#[macro_export]
macro_rules! tls_zend_mm_state_get {
    ($x:ident) => {
        unsafe { (*$crate::allocation::get_zend_mm_state()).get().$x }
    };
}

#[macro_export]
macro_rules! tls_zend_mm_state_set {
    ($x:expr) => {{
        let value = $x;
        unsafe {
            (*$crate::allocation::get_zend_mm_state()).set(value);
        }
    }};
}

#[cfg(php_zend_mm_set_custom_handlers_ex)]
pub mod allocation_ge84;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
pub mod allocation_le83;

/// Default sampling interval in bytes (4 MiB).
pub const DEFAULT_ALLOCATION_SAMPLING_INTERVAL: NonZeroU32 = NonZeroU32::new(1024 * 4096).unwrap();

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
    #[cfg(php_zts)]
    rng: ThreadRng,
    #[cfg(not(php_zts))]
    rng: StdRng,
}

impl AllocationProfilingStats {
    fn new(sampling_distance: NonZeroU64) -> AllocationProfilingStats {
        // SAFETY: this will only error if lambda <= 0, and it's NonZeroU64.
        let poisson = unsafe { Poisson::new(sampling_distance.get() as f64).unwrap_unchecked() };
        let mut stats = AllocationProfilingStats {
            next_sample: 0,
            poisson,
            #[cfg(php_zts)]
            rng: rand::thread_rng(),
            #[cfg(not(php_zts))]
            rng: StdRng::from_entropy(),
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

        // SAFETY: execute_data was provided by the engine, and the profiler
        // doesn't mutate it.
        unsafe {
            profiler.collect_allocations(
                zend::ddog_php_prof_get_current_execute_data(),
                1_i64,
                len as i64,
                (interrupt_count > 0).then_some(interrupt_count),
            )
        };
    }
}

#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
pub fn alloc_prof_startup() {
    allocation_le83::alloc_prof_startup();
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

pub fn rinit() {
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

    #[cfg(not(php_zend_mm_set_custom_handlers_ex))]
    allocation_le83::alloc_prof_rinit();
    #[cfg(php_zend_mm_set_custom_handlers_ex)]
    allocation_ge84::alloc_prof_rinit();
}

pub fn alloc_prof_rshutdown() {
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

    #[cfg(not(php_zend_mm_set_custom_handlers_ex))]
    allocation_le83::alloc_prof_rshutdown();
    #[cfg(php_zend_mm_set_custom_handlers_ex)]
    allocation_ge84::alloc_prof_rshutdown();
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
