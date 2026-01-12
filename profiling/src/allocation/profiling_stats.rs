//! The thread-local allocation profiling stats are held in this module.
//! The stats are used on the hot-path of allocation, so this code is
//! performance sensitive. It is encapsulated so that some unsafe techniques
//! can be used but expose a relatively safe API.

use super::AllocationProfilingStats;
use libc::size_t;
use std::mem::MaybeUninit;

#[cfg(php_zts)]
use std::cell::UnsafeCell;

#[cfg(php_zend_mm_set_custom_handlers_ex)]
use super::allocation_ge84;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
use super::allocation_le83;

#[cfg(php_zts)]
thread_local! {
    /// This is initialized in ginit, before any memory allocator hooks are
    /// installed. During a request, all accesses will be initialized.
    ///
    /// This is not pub so that unsafe code can be contained to this module.
    static ALLOCATION_PROFILING_STATS: UnsafeCell<MaybeUninit<AllocationProfilingStats>> =
        const { UnsafeCell::new(MaybeUninit::uninit()) };
}

#[cfg(not(php_zts))]
static mut ALLOCATION_PROFILING_STATS: MaybeUninit<AllocationProfilingStats> =
    const { MaybeUninit::uninit() };

/// Accesses the thread-local [`AllocationProfilingStats`], passing a mutable
/// reference to the contained `MaybeUninit` to `F`.
///
/// # Safety
///
///  1. There should not be any active borrows to the thread-local variable
///     [`AllocationProfilingStats`] when this function is called.
///  2. Function `F` should not do anything which causes a new borrow on
///     [`AllocationProfilingStats`].
///  3. Do not call this function in ALLOCATION_PROFILING_STATS's destructor,
///     as it assumes that [`std::thread::LocalKey::try_with`] cannot fail.
///
/// This is not pub to limit caller's ability to violate these conditions.
unsafe fn allocation_profiling_stats_mut<F, R>(f: F) -> R
where
    F: FnOnce(&mut MaybeUninit<AllocationProfilingStats>) -> R,
{
    #[cfg(php_zts)]
    {
        let result = ALLOCATION_PROFILING_STATS.try_with(|cell| {
            let ptr: *mut MaybeUninit<AllocationProfilingStats> = cell.get();
            // SAFETY: the cell is statically initialized to [`MaybeUninit::uninit`] so the
            // _cell_ is valid and initialized memory. As required by this own
            // function's safety requirements, there should not be any active borrows
            // to [`ALLOCATION_PROFILING_STATS`], so this mutable dereference is sound.
            let uninit = unsafe { &mut *ptr };
            f(uninit)
        });
        // SAFETY: this function is not called in a destructor, therefore it
        // cannot return an AccessError:
        // > If the key has been destroyed (which may happen if this is called
        // > in a destructor), this function will return an AccessError.
        unsafe { result.unwrap_unchecked() }
    }

    #[cfg(not(php_zts))]
    {
        // SAFETY: For non-ZTS builds, ALLOCATION_PROFILING_STATS is a static variable.
        // As required by this function's safety requirements, there should not be any
        // active borrows to ALLOCATION_PROFILING_STATS, so this mutable reference is sound.
        let uninit = unsafe {
            let ptr: *mut MaybeUninit<AllocationProfilingStats> =
                std::ptr::addr_of_mut!(ALLOCATION_PROFILING_STATS);
            &mut *ptr
        };
        f(uninit)
    }
}

/// Given the provided allocation length `len`, return whether the allocation
/// should be collected. This is a mutable operation, as the thread-local
/// variable will be modified to reduce the distance until the next sample.
pub fn allocation_profiling_stats_should_collect(len: size_t) -> bool {
    let f = |maybe_uninit: &mut MaybeUninit<AllocationProfilingStats>| {
        // SAFETY: ALLOCATION_PROFILING_STATS was initialized in GINIT.
        let stats = unsafe { maybe_uninit.assume_init_mut() };
        stats.should_collect_allocation(len)
    };

    // SAFETY:
    //  1. This function doesn't expose any way for the caller to keep a
    // borrow alive, nor do the other public functions, so there cannot be
    // any existing borrows alive.
    //  2. This closure will not cause any new borrows.
    //  3. This function isn't called during ALLOCATION_PROFILING_STATS's dtor,
    // as MaybeUninit's destructor does nothing, you have to specifically drop
    // it. Even if the destructor were called, AllocationProfilingStats's dtor
    // doesn't access the TLS variable (it can't, it doesn't have access).
    unsafe { allocation_profiling_stats_mut(f) }
}

/// Initializes the allocation profiler's globals.
///
/// # Safety
///
/// Must be called once per PHP thread ginit.
pub unsafe fn ginit() {
    // SAFETY:
    //  1. During ginit, there will not be any other borrows.
    //  2. This closure will not make new borrows.
    //  3. This is not during the thread-local destructor.
    unsafe {
        allocation_profiling_stats_mut(|uninit| {
            uninit.write(AllocationProfilingStats::new());
        })
    };

    #[cfg(not(php_zend_mm_set_custom_handlers_ex))]
    allocation_le83::alloc_prof_ginit();
    #[cfg(php_zend_mm_set_custom_handlers_ex)]
    allocation_ge84::alloc_prof_ginit();
}

/// Shuts down the allocation profiler's globals.
///
/// # Safety
///
/// Must be called once per PHP thread gshutdown.
pub unsafe fn gshutdown() {
    #[cfg(php_zend_mm_set_custom_handlers_ex)]
    allocation_ge84::alloc_prof_gshutdown();

    // SAFETY:
    //  1. During gshutdown, there will not be any other borrows.
    //  2. This closure will not make new borrows.
    //  3. This is not during the thread-local destructor.
    unsafe { allocation_profiling_stats_mut(|maybe_uninit| maybe_uninit.assume_init_drop()) }
}
