mod profiling_stats;

pub use profiling_stats::*;

use crate::profiling::bindings::{self as zend};
use crate::profiling::config::SystemSettings;
use crate::profiling::module_globals;
use crate::profiling::profiler::Profiler;
use crate::profiling::{RefCellExt, REQUEST_LOCALS};
use core::cell::Cell;
use core::ptr;
use libc::size_t;
use log::{debug, trace};
use rand::Rng;
use std::ffi::c_void;
use std::num::{NonZero, NonZeroU32, NonZeroU64};
use std::sync::atomic::{AtomicU32, AtomicU64, Ordering};

#[cfg(not(php_zts))]
use rand::rngs::StdRng;
#[cfg(php_zts)]
use rand::rngs::ThreadRng;
#[cfg(not(php_zts))]
use rand::SeedableRng;

#[cfg(php_zend_mm_set_custom_handlers_ex)]
use crate::profiling::allocation::allocation_ge84::ZendMMState;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
use crate::profiling::allocation::allocation_le83::ZendMMState;

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

#[cfg(php_zts)]
#[inline(always)]
pub(crate) unsafe fn current_execute_data_from_cache(
    ls_cache: *mut c_void,
) -> *mut zend::zend_execute_data {
    // PHP 7.4 introduced fast globals offsets. Older versions use the TSRM resource ID.
    #[cfg(php_zts_fast_globals)]
    let globals = {
        let offset = ptr::addr_of!(zend::executor_globals_offset).read();
        ls_cache
            .byte_add(offset)
            .cast::<zend::zend_executor_globals>()
    };
    #[cfg(not(php_zts_fast_globals))]
    let globals = {
        let id = ptr::addr_of!(zend::executor_globals_id).read();
        module_globals::get_tsrm_resource_from_cache(ls_cache, id)
            .cast::<zend::zend_executor_globals>()
    };
    ptr::addr_of!((*globals).current_execute_data).read()
}

/// Macros for accessing ZendMMState from PHP globals.
/// These are shared between PHP 8.3- and 8.4+ implementations.
/// They are exported at the crate root and can be used in submodules.
#[macro_export]
macro_rules! tls_zend_mm_state_copy {
    () => {
        unsafe { (*$crate::profiling::allocation::get_zend_mm_state()).get() }
    };
}

#[macro_export]
macro_rules! tls_zend_mm_state_get {
    ($x:ident) => {
        unsafe {
            (*$crate::profiling::allocation::get_zend_mm_state())
                .get()
                .$x
        }
    };
}

#[macro_export]
macro_rules! tls_zend_mm_state_set {
    ($x:expr) => {{
        let value = $x;
        unsafe {
            (*$crate::profiling::allocation::get_zend_mm_state()).set(value);
        }
    }};
}

#[cfg(php_zend_mm_set_custom_handlers_ex)]
pub mod allocation_ge84;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
pub mod allocation_le83;

// Handler-selection tests retain callbacks in a binary that is not loaded by PHP.
#[cfg(all(test, not(php_zts)))]
#[export_name = "executor_globals"]
static mut TEST_EXECUTOR_GLOBALS: core::mem::MaybeUninit<zend::zend_executor_globals> =
    core::mem::MaybeUninit::zeroed();

#[cfg(all(test, php_zts, php_zts_fast_globals))]
#[export_name = "executor_globals_offset"]
static mut TEST_EXECUTOR_GLOBALS_OFFSET: usize = 0;

#[cfg(all(test, not(php_debug)))]
#[no_mangle]
unsafe extern "C" fn _zend_mm_free(_heap: *mut zend::_zend_mm_heap, _ptr: *mut c_void) {}

#[cfg(all(test, php_debug))]
#[no_mangle]
unsafe extern "C" fn _zend_mm_free(
    _heap: *mut zend::_zend_mm_heap,
    _ptr: *mut c_void,
    _file: *const libc::c_char,
    _line: libc::c_uint,
    _orig_file: *const libc::c_char,
    _orig_line: libc::c_uint,
) {
}

#[cfg(all(test, not(php_debug)))]
#[no_mangle]
unsafe extern "C" fn _zend_mm_realloc(
    _heap: *mut zend::_zend_mm_heap,
    _ptr: *mut c_void,
    _len: size_t,
) -> *mut c_void {
    ptr::null_mut()
}

#[cfg(all(test, php_debug))]
#[no_mangle]
unsafe extern "C" fn _zend_mm_realloc(
    _heap: *mut zend::_zend_mm_heap,
    _ptr: *mut c_void,
    _len: size_t,
    _file: *const libc::c_char,
    _line: libc::c_uint,
    _orig_file: *const libc::c_char,
    _orig_line: libc::c_uint,
) -> *mut c_void {
    ptr::null_mut()
}

/// Default sampling interval in bytes (4 MiB).
pub const DEFAULT_ALLOCATION_SAMPLING_INTERVAL: NonZeroU32 = NonZero::new(1024 * 4096).unwrap();

/// Mean distance between allocation samples in bytes. This must be > 0.
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
    /// Number of bytes remaining until the next sample collection.
    next_sample: i64,
    mean: f64,
    #[cfg(php_zts)]
    rng: ThreadRng,
    #[cfg(not(php_zts))]
    rng: StdRng,
}

impl AllocationProfilingStats {
    fn new(sampling_distance: NonZeroU64) -> AllocationProfilingStats {
        let mut stats = AllocationProfilingStats {
            next_sample: 0,
            mean: sampling_distance.get() as f64,
            #[cfg(php_zts)]
            rng: rand::rng(),
            #[cfg(not(php_zts))]
            rng: StdRng::from_os_rng(),
        };
        stats.next_sampling_interval();
        stats
    }

    fn next_sampling_interval(&mut self) {
        // Exponential distances give the upscaler's probability: 1 - exp(-size / mean).
        let u: f64 = self.rng.random();
        let u = if u <= 0.0 { 1e-10 } else { u };
        let v = -u.ln() * self.mean;
        // Clamp to [8, 20 * mean], matching the libdatadog sampler.
        self.next_sample = v.clamp(8.0, 20.0 * self.mean) as i64;
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

/// Collect an allocation sample and optionally track it for live heap profiling.
///
/// # Safety
/// `execute_data` must be null or a valid pointer provided by the engine. The
/// profiler may walk the execution frames reachable through it.
///
/// # Arguments
/// * `ptr` - The pointer returned by the allocator (used for live heap tracking)
/// * `len` - The size of the allocation in bytes
#[cold]
pub unsafe fn collect_allocation(
    interrupt_count: &AtomicU32,
    execute_data: *mut zend::zend_execute_data,
    ptr: *mut c_void,
    len: size_t,
) {
    if let Some(profiler) = Profiler::get() {
        // Check if there's a pending time interrupt that we can handle now
        // instead of waiting for an interrupt handler. This is slightly more
        // accurate and efficient, win-win.
        let pending_interrupts = interrupt_count.swap(0, Ordering::Relaxed);

        // SAFETY: execute_data was provided by the engine, and the profiler
        // only reads the execution frames reachable through it.
        unsafe {
            profiler.collect_allocations(
                execute_data,
                ptr,
                1_i64,
                len as i64,
                (pending_interrupts > 0).then_some(pending_interrupts),
            )
        };
    }
}

/// Called when an allocation is no longer live, such as `free` or successful
/// `realloc`. If this pointer was tracked for live heap, remove it from the
/// live set.
#[inline]
pub fn untrack_allocation(ptr: *mut c_void) {
    if let Some(profiler) = Profiler::get() {
        if let Some(sample) = profiler.untrack_allocation(ptr as usize) {
            trace!(
                "Untracked allocation at {:#x} ({} bytes)",
                ptr as usize,
                sample.allocation_size
            );
        }
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
    let (allocation_enabled, heap_live_enabled) = REQUEST_LOCALS
        .try_with_borrow(|locals| {
            (
                locals.system_settings().profiling_allocation_enabled,
                locals.profiling_experimental_heap_live_enabled,
            )
        })
        .unwrap_or_else(|err| {
            // Debug rather than error because this is every request, could
            // be very spammy.
            debug!("Allocation profiling rinit failed because it failed to borrow the request locals. Please report this to Datadog: {err}");
            (false, false)
        });

    if !allocation_enabled {
        return;
    }

    #[cfg(not(php_zend_mm_set_custom_handlers_ex))]
    allocation_le83::alloc_prof_rinit(heap_live_enabled);
    #[cfg(php_zend_mm_set_custom_handlers_ex)]
    allocation_ge84::alloc_prof_rinit(heap_live_enabled);
}

pub fn alloc_prof_rshutdown() {
    let (allocation_enabled, heap_live_enabled) = REQUEST_LOCALS
        .try_with_borrow(|locals| {
            (
                locals.system_settings().profiling_allocation_enabled,
                locals.profiling_experimental_heap_live_enabled,
            )
        })
        .unwrap_or_else(|err| {
            // Debug rather than error because this is every request, could
            // be very spammy.
            debug!("Allocation profiling rshutdown failed because it failed to borrow the request locals. Please report this to Datadog: {err}");
            (false, false)
        });

    if !allocation_enabled {
        return;
    }

    #[cfg(not(php_zend_mm_set_custom_handlers_ex))]
    allocation_le83::alloc_prof_rshutdown(heap_live_enabled);
    #[cfg(php_zend_mm_set_custom_handlers_ex)]
    allocation_ge84::alloc_prof_rshutdown(heap_live_enabled);
}

#[cfg(all(test, not(php_zts)))]
mod tests {
    use super::*;

    #[test]
    fn allocation_sampling_matches_upscaling_probability() {
        let mean = DEFAULT_ALLOCATION_SAMPLING_INTERVAL.get() as f64;
        let trials = 100_000;
        for ratio in [0.1, 1.1, 3.0] {
            let size = (ratio * mean) as usize;
            let mut stats =
                AllocationProfilingStats::new(DEFAULT_ALLOCATION_SAMPLING_INTERVAL.into());
            stats.rng = StdRng::seed_from_u64(42);
            stats.next_sampling_interval();
            let sampled = (0..trials)
                .filter(|_| stats.should_collect_allocation(size))
                .count();
            let probability = 1.0 - (-(size as f64) / mean).exp();
            let expected = trials as f64 * probability;
            let sigma = (expected * (1.0 - probability)).sqrt();
            assert!(
                (sampled as f64 - expected).abs() < 8.0 * sigma,
                "size={size}: sampled {sampled}, expected {expected}"
            );
        }
    }
}

#[cfg(php_zend_mm_set_custom_handlers_ex)]
#[track_caller]
fn initialization_panic() -> ! {
    panic!("Allocation profiler was not initialized properly. Please fill an issue stating the PHP version and the backtrace from this panic.");
}
