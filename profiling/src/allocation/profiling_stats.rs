//! The per-thread allocation profiling stats are held in `ProfilerGlobals`,
//! which is accessed via `module_globals::get_profiler_globals()`. In ZTS,
//! TSRM gives each PHP thread its own `ProfilerGlobals` slot; in NTS there is
//! a single global. This replaces the previous thread_local!/static mut split.

use super::{AllocationProfilingStats, ALLOCATION_PROFILING_INTERVAL};
use crate::module_globals;
use libc::size_t;
use std::mem::MaybeUninit;
use std::num::NonZeroU64;
use std::sync::atomic::Ordering;

use crate::universal;

/// Accesses the per-thread [`AllocationProfilingStats`] stored in
/// `ProfilerGlobals`, passing a mutable reference to the contained
/// `MaybeUninit` to `F`.
///
/// # Safety
///
///  1. There should not be any active borrows to the stats when this is called.
///  2. Function `F` should not cause a new borrow on the stats.
///  3. Must not be called before ginit or after gshutdown.
unsafe fn allocation_profiling_stats_mut<F, R>(f: F) -> R
where
    F: FnOnce(&mut MaybeUninit<AllocationProfilingStats>) -> R,
{
    let globals = module_globals::get_profiler_globals(crate::OnPhpThread::new());
    let uninit = &mut *(*globals).allocation_profiling_stats.get();
    f(uninit)
}

/// Given the provided allocation length `len`, return whether the allocation
/// should be collected. This is a mutable operation, as the per-thread
/// variable will be modified to reduce the distance until the next sample.
pub fn allocation_profiling_stats_should_collect(len: size_t) -> bool {
    let f = |maybe_uninit: &mut MaybeUninit<AllocationProfilingStats>| {
        // SAFETY: ALLOCATION_PROFILING_STATS was initialized in GINIT.
        let stats = unsafe { maybe_uninit.assume_init_mut() };
        stats.should_collect_allocation(len)
    };

    // SAFETY:
    //  1. This function doesn't expose any way for the caller to keep a
    //     borrow alive, so there cannot be any existing borrows alive.
    //  2. This closure will not cause any new borrows.
    //  3. This function isn't called before ginit or after gshutdown.
    unsafe { allocation_profiling_stats_mut(f) }
}

/// Initializes the allocation profiler's globals.
///
/// # Safety
///
/// Must be called once per PHP thread ginit.
pub unsafe fn ginit() {
    // SAFETY:
    //  1. During ginit, there will not be any other borrows to stats.
    //  2. This closure will not make new borrows to stats.
    unsafe {
        allocation_profiling_stats_mut(|uninit| {
            let interval = ALLOCATION_PROFILING_INTERVAL.load(Ordering::Relaxed);
            // SAFETY: ALLOCATION_PROFILING_INTERVAL must always be > 0.
            let nonzero = NonZeroU64::new_unchecked(interval);
            uninit.write(AllocationProfilingStats::new(nonzero));
        })
    };

    if universal::has_zend_mm_set_custom_handlers_ex() {
        crate::allocation::allocation_ge84::alloc_prof_ginit();
    } else {
        crate::allocation::allocation_le83::alloc_prof_ginit();
    }
}

/// Initializes the allocation profiler's globals with the provided sampling
/// distance.
///
/// # Safety
///
/// Must be called once per PHP thread minit, unless the allocation profiling
/// is disabled, in which case it can be skipped.
pub unsafe fn minit(sampling_distance: NonZeroU64) {
    // SAFETY:
    //  1. During minit, there will not be any other borrows.
    //  2. This closure will not make new borrows.
    unsafe {
        allocation_profiling_stats_mut(|uninit| {
            // SAFETY: previously initialized in ginit, we're just
            // re-initializing it because we now have config
            *uninit.assume_init_mut() = AllocationProfilingStats::new(sampling_distance);
        })
    };
}

/// Shuts down the allocation profiler's globals.
///
/// # Safety
///
/// Must be called once per PHP thread gshutdown.
pub unsafe fn gshutdown() {
    // SAFETY:
    //  1. During gshutdown, there will not be any other borrows.
    //  2. This closure will not make new borrows.
    unsafe { allocation_profiling_stats_mut(|maybe_uninit| maybe_uninit.assume_init_drop()) }
}
