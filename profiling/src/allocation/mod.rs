mod tls_allocation_profiling_stats;

#[cfg(php_zend_mm_set_custom_handlers_ex)]
pub mod allocation_ge84;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
pub mod allocation_le83;

pub use tls_allocation_profiling_stats::*;

use crate::bindings::{self as zend};
use crate::profiling::Profiler;
use crate::{RefCellExt, REQUEST_LOCALS};
use libc::size_t;
use log::{debug, error, trace};
use rand_distr::{Distribution, Poisson};
use std::ffi::c_void;
use std::sync::atomic::{AtomicU64, Ordering};

#[cfg(not(php_zts))]
use rand::rngs::StdRng;
#[cfg(php_zts)]
use rand::rngs::ThreadRng;
#[cfg(not(php_zts))]
use rand::SeedableRng;

/// Default sampling interval in bytes (4MB)
pub const DEFAULT_ALLOCATION_SAMPLING_INTERVAL: u64 = 1024 * 4096;

/// Sampling distance feed into poison sampling algo
pub static ALLOCATION_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(DEFAULT_ALLOCATION_SAMPLING_INTERVAL);

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
    fn new() -> AllocationProfilingStats {
        // Safety: this will only error if lambda <= 0
        let poisson =
            Poisson::new(ALLOCATION_PROFILING_INTERVAL.load(Ordering::Relaxed) as f64).unwrap();
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
        // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
        unsafe {
            profiler.collect_allocations(
                zend::ddog_php_prof_get_current_execute_data(),
                1_i64,
                len as i64,
            )
        };
    }
}

#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
pub fn alloc_prof_startup() {
    allocation_le83::alloc_prof_startup();
}

pub fn alloc_prof_first_rinit() {
    let (allocation_enabled, sampling_distance) = REQUEST_LOCALS
        .try_with_borrow(|locals| {
            let settings = locals.system_settings();
            (settings.profiling_allocation_enabled, settings.profiling_allocation_sampling_distance)
        })
        .unwrap_or_else(|err| {
            error!("Allocation profiling first rinit failed because it failed to borrow the request locals. Please report this to Datadog: {err}");
            (false, DEFAULT_ALLOCATION_SAMPLING_INTERVAL as u32)
        });

    if !allocation_enabled {
        return;
    }

    ALLOCATION_PROFILING_INTERVAL.store(sampling_distance as u64, Ordering::Relaxed);

    trace!(
        "Memory allocation profiling initialized with a sampling distance of {} bytes.",
        ALLOCATION_PROFILING_INTERVAL.load(Ordering::Relaxed)
    );
}

pub fn alloc_prof_rinit() {
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
