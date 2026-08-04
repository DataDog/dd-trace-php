//! Per-thread allocation profiling stats stored in PHP module globals.
//! The stats are used on the allocation hot path, so callers should thread
//! through an already-resolved [`ProfilerGlobals`] pointer whenever possible.

use super::{AllocationProfilingStats, ALLOCATION_PROFILING_INTERVAL};
use crate::module_globals::{self, ProfilerGlobals};
use libc::size_t;
use std::num::NonZeroU64;
use std::sync::atomic::Ordering;

#[cfg(php_zend_mm_set_custom_handlers_ex)]
use super::allocation_ge84;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
use super::allocation_le83;

impl ProfilerGlobals {
    /// Updates the allocation sampling state from the PHP globals.
    ///
    /// # Safety
    /// `globals` must point to initialized module globals for the current
    /// thread, and no mutable access to its allocation profiling state may be
    /// active.
    #[inline(always)]
    pub unsafe fn should_collect(globals: *mut ProfilerGlobals, len: size_t) -> bool {
        // SAFETY: the state is initialized in GINIT and all accesses occur on the
        // owning PHP thread. Allocator reentrancy cannot overlap this borrow because
        // sampling state is released before stack collection begins.
        let stats = unsafe { (*(*globals).allocation_profiling_stats.get()).assume_init_mut() };
        stats.should_collect_allocation(len)
    }
}

/// Initializes the allocation profiler's globals.
///
/// # Safety
/// Must be called once per PHP thread GINIT.
pub unsafe fn ginit() {
    let interval = ALLOCATION_PROFILING_INTERVAL.load(Ordering::Relaxed);
    // SAFETY: ALLOCATION_PROFILING_INTERVAL is always greater than zero.
    let sampling_distance = unsafe { NonZeroU64::new_unchecked(interval) };
    // SAFETY: GINIT runs with allocated module globals and before allocator hooks.
    let globals = unsafe { module_globals::get_profiler_globals() };
    unsafe {
        (*(*globals).allocation_profiling_stats.get())
            .write(AllocationProfilingStats::new(sampling_distance));
    }

    #[cfg(not(php_zend_mm_set_custom_handlers_ex))]
    allocation_le83::alloc_prof_ginit();
    #[cfg(php_zend_mm_set_custom_handlers_ex)]
    allocation_ge84::alloc_prof_ginit();
}

/// Reinitializes allocation sampling with the configured distance.
///
/// # Safety
/// Must be called once per PHP thread MINIT, unless allocation profiling is disabled.
pub unsafe fn minit(sampling_distance: NonZeroU64) {
    // SAFETY: GINIT initialized this state, and MINIT has exclusive lifecycle access.
    let globals = unsafe { module_globals::get_profiler_globals() };
    let stats = unsafe { (*(*globals).allocation_profiling_stats.get()).assume_init_mut() };
    *stats = AllocationProfilingStats::new(sampling_distance);
}

/// Drops the allocation sampling state.
///
/// # Safety
/// Must be called once per PHP thread GSHUTDOWN after allocator hooks are removed.
pub unsafe fn gshutdown() {
    // SAFETY: GINIT initialized this state, and GSHUTDOWN has exclusive lifecycle access.
    let globals = unsafe { module_globals::get_profiler_globals() };
    unsafe { (*(*globals).allocation_profiling_stats.get()).assume_init_drop() };
}
