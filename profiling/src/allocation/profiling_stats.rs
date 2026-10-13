//! Per-thread allocation profiling stats stored in PHP module globals.
//! The stats are used on the allocation hot path, so callers should thread
//! through an already-resolved [`ProfilerGlobals`] pointer whenever possible.

use super::{AllocationProfilingStats, ALLOCATION_PROFILING_INTERVAL};
use crate::profiling::module_globals::{self, ProfilerGlobals};
use libc::size_t;
use std::num::NonZeroU64;
use std::sync::atomic::Ordering;

impl ProfilerGlobals {
    /// Updates the allocation sampling state from the PHP globals.
    ///
    /// # Safety
    /// `globals` must point to initialized module globals for the current
    /// thread, and no other access to its allocation profiling state may be
    /// active.
    #[inline(always)]
    pub unsafe fn should_collect(globals: *mut ProfilerGlobals, len: size_t) -> bool {
        // SAFETY: GINIT initializes this state before sampling runs on the
        // owning PHP thread. This borrow ends before stack collection begins,
        // so reentrant allocation hooks cannot overlap it.
        let stats = unsafe { (*(*globals).allocation_profiling_stats.get()).assume_init_mut() };
        stats.should_collect_allocation(len)
    }
}

/// Initializes the allocation profiler's globals.
///
/// # Safety
/// `globals` must be the pointer supplied by PHP to GINIT. Call this once
/// per globals allocation, before allocation hooks access the sampling state.
pub unsafe fn ginit(globals: *mut ProfilerGlobals) {
    let interval = ALLOCATION_PROFILING_INTERVAL.load(Ordering::Relaxed);
    // SAFETY: ALLOCATION_PROFILING_INTERVAL is always greater than zero.
    let sampling_distance = unsafe { NonZeroU64::new_unchecked(interval) };
    // SAFETY: GINIT supplies the storage to initialize before allocator hooks run.
    unsafe {
        (*(*globals).allocation_profiling_stats.get())
            .write(AllocationProfilingStats::new(sampling_distance));
    }
}

/// Reinitializes allocation sampling with the configured distance.
///
/// # Safety
/// Must be called during MINIT, before allocation hooks can access the state.
pub unsafe fn minit(sampling_distance: NonZeroU64) {
    // SAFETY: GINIT has initialized the current thread's globals, and
    // allocation hooks have not started accessing the sampling state.
    let globals = unsafe { module_globals::get_profiler_globals() };
    let stats = unsafe { (*(*globals).allocation_profiling_stats.get()).assume_init_mut() };
    *stats = AllocationProfilingStats::new(sampling_distance);
}

/// Drops the allocation sampling state.
///
/// # Safety
/// `globals` must be the pointer supplied by PHP to GSHUTDOWN, with its
/// sampling state still initialized. Call this once per globals allocation,
/// after allocation hooks have stopped accessing the sampling state.
pub unsafe fn gshutdown(globals: *mut ProfilerGlobals) {
    // GSHUTDOWN may run on a different thread from the one that owns these
    // globals. Use the supplied pointer; do not look up current-thread globals
    // or access native TLS.
    unsafe { (*(*globals).allocation_profiling_stats.get()).assume_init_drop() };
}
