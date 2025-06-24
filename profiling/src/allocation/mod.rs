use crate::bindings::{self as zend};
use crate::profiling::Profiler;
use crate::REQUEST_LOCALS;
use libc::{c_char, size_t};
use log::{error, trace};
use rand::rngs::ThreadRng;
use rand_distr::{Distribution, Poisson};
use std::cell::RefCell;
use std::ffi::c_void;
use std::sync::atomic::{AtomicU64, Ordering};

#[cfg(php_zend_mm_set_custom_handlers_ex)]
pub mod allocation_ge84;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
pub mod allocation_le83;

/// Default sampling interval in bytes (4MB)
pub const DEFAULT_ALLOCATION_SAMPLING_INTERVAL: u64 = 1024 * 4096;

/// Sampling distance feed into poison sampling algo
pub static ALLOCATION_PROFILING_INTERVAL: AtomicU64 =
    AtomicU64::new(DEFAULT_ALLOCATION_SAMPLING_INTERVAL);

/// This will store the count of allocations (including reallocations) during
/// a profiling period. This will overflow when doing more than u64::MAX
/// allocations, which seems big enough to ignore.
pub static ALLOCATION_PROFILING_COUNT: AtomicU64 = AtomicU64::new(0);

/// This will store the accumulated size of all allocations in bytes during the
/// profiling period. This will overflow when allocating more than 18 exabyte
/// of memory (u64::MAX) which might not happen, so we can ignore this.
pub static ALLOCATION_PROFILING_SIZE: AtomicU64 = AtomicU64::new(0);

pub struct AllocationProfilingStats {
    /// number of bytes until next sample collection
    next_sample: i64,
    poisson: Poisson<f64>,
    rng: ThreadRng,
}

impl AllocationProfilingStats {
    fn new() -> AllocationProfilingStats {
        // Safety: this will only error if lambda <= 0
        let poisson =
            Poisson::new(ALLOCATION_PROFILING_INTERVAL.load(Ordering::SeqCst) as f64).unwrap();
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

    fn track_allocation(&mut self, len: size_t) {
        self.next_sample -= len as i64;

        if self.next_sample > 0 {
            return;
        }

        self.next_sampling_interval();

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
}

thread_local! {
    static ALLOCATION_PROFILING_STATS: RefCell<AllocationProfilingStats> =
        RefCell::new(AllocationProfilingStats::new());
}

pub fn alloc_prof_ginit() {
    #[cfg(not(php_zend_mm_set_custom_handlers_ex))]
    allocation_le83::alloc_prof_ginit();
    #[cfg(php_zend_mm_set_custom_handlers_ex)]
    allocation_ge84::alloc_prof_ginit();
}

pub fn alloc_prof_gshutdown() {
    #[cfg(php_zend_mm_set_custom_handlers_ex)]
    allocation_ge84::alloc_prof_gshutdown();
}

#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
pub fn alloc_prof_startup() {
    allocation_le83::alloc_prof_startup();
}

pub fn alloc_prof_first_rinit() {
    let allocation_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_allocation_enabled)
            .unwrap_or(false)
    });

    if !allocation_profiling {
        return;
    }

    let sampling_distance = REQUEST_LOCALS.with(|cell| {
        match cell.try_borrow() {
            Ok(locals) => locals.system_settings().profiling_allocation_sampling_distance,
            Err(_err) => {
                error!("Allocation profiling was not initialized correctly due to a borrow error. Please report this to Datadog.");
                DEFAULT_ALLOCATION_SAMPLING_INTERVAL as u32
            }
        }
    });

    ALLOCATION_PROFILING_INTERVAL.store(sampling_distance as u64, Ordering::SeqCst);

    trace!(
        "Memory allocation profiling initialized with a sampling distance of {} bytes.",
        ALLOCATION_PROFILING_INTERVAL.load(Ordering::SeqCst)
    );
}

pub fn alloc_prof_rinit() {
    let allocation_profiling: bool = REQUEST_LOCALS.with(|cell| {
        match cell.try_borrow() {
            Ok(locals) => {
                let system_settings = locals.system_settings();
                system_settings.profiling_allocation_enabled
            },
            Err(_err) => {
                error!("Memory allocation was not initialized correctly due to a borrow error. Please report this to Datadog.");
                false
            }
        }
    });

    if !allocation_profiling {
        return;
    }

    #[cfg(not(php_zend_mm_set_custom_handlers_ex))]
    allocation_le83::alloc_prof_rinit();
    #[cfg(php_zend_mm_set_custom_handlers_ex)]
    allocation_ge84::alloc_prof_rinit();
}

pub fn alloc_prof_rshutdown() {
    let allocation_profiling = REQUEST_LOCALS.with(|cell| {
        cell.try_borrow()
            .map(|locals| locals.system_settings().profiling_allocation_enabled)
            .unwrap_or(false)
    });

    if !allocation_profiling {
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

unsafe fn alloc_prof_panic_alloc(
    _len: size_t,
    #[cfg(php_debug)] _filename: *const c_char,
    #[cfg(php_debug)] _lineno: u32,
    #[cfg(php_debug)] _orig_filename: *const c_char,
    #[cfg(php_debug)] _orig_lineno: u32,
) -> *mut c_void {
    initialization_panic();
}

unsafe fn alloc_prof_panic_realloc(
    _prev_ptr: *mut c_void,
    _len: size_t,
    #[cfg(php_debug)] _filename: *const c_char,
    #[cfg(php_debug)] _lineno: u32,
    #[cfg(php_debug)] _orig_filename: *const c_char,
    #[cfg(php_debug)] _orig_lineno: u32,
) -> *mut c_void {
    initialization_panic();
}

unsafe fn alloc_prof_panic_free(
    _ptr: *mut c_void,
    #[cfg(php_debug)] _filename: *const c_char,
    #[cfg(php_debug)] _lineno: u32,
    #[cfg(php_debug)] _orig_filename: *const c_char,
    #[cfg(php_debug)] _orig_lineno: u32,
) {
    initialization_panic();
}
