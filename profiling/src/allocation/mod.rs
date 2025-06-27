use crate::bindings::{self as zend};
use crate::profiling::Profiler;
use crate::REQUEST_LOCALS;
use libc::size_t;
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

        // Queue the allocation for safe-point collection
        REQUEST_LOCALS.with_borrow_mut(|locals| {
            locals
                .allocation_interrupt_count
                .fetch_add(1, Ordering::SeqCst);
        });

        PENDING_ALLOCATION_SIZE.with_borrow_mut(|pending_size| {
            // Trigger interrupt only when transitioning from zero to non-zero
            if *pending_size == 0 {
                *pending_size += len as u64;
                if let Some(profiler) = Profiler::get() {
                    // TODO: If this thread needs to take an allocation sample, calling
                    // `profiler.trigger_interrupt()` will not only trigger this threads interrupt,
                    // but all other PHP ZTS threads interrupts as well. The interrupt handler is
                    // pretty slim, and does not collect a stack trace if there is nothing pending,
                    // yet we should only trigger an interrupt in the "current" thread.
                    profiler.trigger_interrupt();
                }
            } else {
                *pending_size += len as u64;
            }
        });
    }
}

thread_local! {
    static ALLOCATION_PROFILING_STATS: RefCell<AllocationProfilingStats> =
        RefCell::new(AllocationProfilingStats::new());
    static PENDING_ALLOCATION_SIZE: RefCell<u64> = const {RefCell::new(0)};
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

unsafe fn alloc_prof_panic_alloc(_len: size_t) -> *mut c_void {
    initialization_panic();
}

unsafe fn alloc_prof_panic_realloc(_prev_ptr: *mut c_void, _len: size_t) -> *mut c_void {
    initialization_panic();
}

unsafe fn alloc_prof_panic_free(_ptr: *mut c_void) {
    initialization_panic();
}

/// Collect any pending allocations at VM interrupt safe-point
/// This should be called from the VM interrupt handler
pub fn collect_pending_allocations(execute_data: *mut zend::zend_execute_data, count: i64) {
    let pending_size = PENDING_ALLOCATION_SIZE.with_borrow_mut(|size| std::mem::take(size));

    if let Some(profiler) = Profiler::get() {
        profiler.collect_allocations(execute_data, count, pending_size as i64);
    }
}
