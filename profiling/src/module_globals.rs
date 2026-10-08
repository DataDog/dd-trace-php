use crate::profiling::{allocation, RequestLocals};
use core::cell::{Cell, OnceCell, RefCell, UnsafeCell};
use core::ffi::c_void;
use core::mem::MaybeUninit;
use core::ptr;
use core::sync::atomic::AtomicU32;

#[cfg(target_os = "linux")]
use crate::profiling::process_context::ProcessContextCache;
#[cfg(php_run_time_cache)]
use crate::profiling::string_set::StringSet;

#[cfg(php_zend_mm_set_custom_handlers_ex)]
use crate::profiling::allocation::allocation_ge84::ZendMMState;
#[cfg(not(php_zend_mm_set_custom_handlers_ex))]
use crate::profiling::allocation::allocation_le83::ZendMMState;

/// Profiler state stored in PHP module globals: process-wide in NTS,
/// per PHP thread in ZTS.
///
/// Intentionally not native thread-local in NTS. Although NTS normally
/// executes PHP on one thread, ext/grpc also executes PHP callbacks on
/// native threads, which we consider a bug in ext/grpc.
#[repr(C)]
pub struct ProfilerGlobals {
    /// Wrapped in `Cell` to prevent torn reads/writes when allocation hooks
    /// are called re-entrantly during `rinit()`/`rshutdown()`.
    pub zend_mm_state: Cell<ZendMMState>,

    /// Number of profiler time interrupts pending for this PHP thread.
    ///
    /// The profiler timer thread updates this through a pointer registered by
    /// the PHP thread, so the value must remain atomic despite living in
    /// thread-local PHP module globals.
    pub interrupt_count: AtomicU32,

    /// The owning thread's name, cached for this globals allocation's lifetime.
    pub(crate) thread_name: OnceCell<String>,

    /// The owning thread's ID, captured on its first RINIT for off-thread cleanup.
    pub(crate) thread_id: OnceCell<i64>,

    pub(crate) request_locals: RefCell<RequestLocals>,

    #[cfg(target_os = "linux")]
    pub(crate) process_context: RefCell<ProcessContextCache>,

    /// Per-thread allocation sampling state. Kept in PHP globals so allocator
    /// hooks can reuse an already-resolved TSRM cache instead of accessing Rust TLS.
    pub allocation_profiling_stats: UnsafeCell<MaybeUninit<allocation::AllocationProfilingStats>>,

    /// Whether this globals allocation has reported its thread-start event.
    #[cfg(php_zts)]
    pub(crate) thread_started: Cell<bool>,

    /// Owns the strings referenced by PHP's runtime cache slots.
    #[cfg(php_run_time_cache)]
    pub cached_strings: UnsafeCell<MaybeUninit<RefCell<StringSet>>>,
}

/// Identifies the profiler's module globals in TSRM. GINIT and GSHUTDOWN
/// manage each allocation's lifetime; GSHUTDOWN can run on another thread
/// during module teardown. The `globals_id_ptr` in `zend_module_entry`
/// points here so TSRM can store the resource id; see:
/// <https://github.com/php/php-src/blob/5ce36453d66143548485cb57fb19bf4157ab60c2/Zend/zend_API.h#L253>
#[cfg(php_zts)]
pub static mut GLOBALS_ID: i32 = 0;

/// Storage for NTS module globals, initialized by GINIT and destroyed by GSHUTDOWN.
#[cfg(not(php_zts))]
pub static mut GLOBALS: MaybeUninit<ProfilerGlobals> = MaybeUninit::uninit();

#[cfg(php_zts)]
mod zts {
    use core::ffi::c_void;

    extern "C" {
        fn tsrm_get_ls_cache() -> *mut c_void;
    }

    #[inline]
    pub unsafe fn get_ls_cache() -> *mut c_void {
        tsrm_get_ls_cache()
    }

    #[inline]
    pub unsafe fn tsrmg_bulk(ls_cache: *mut c_void, id: i32) -> *mut c_void {
        let storage = *(ls_cache as *mut *mut *mut c_void); // void** storage

        // TSRM_UNSHUFFLE_RSRC_ID(id) is just `id - 1`.
        let idx = (id - 1) as usize;
        let slot = storage.add(idx);
        *slot
    }
}

#[cfg(php_zts)]
#[inline]
pub unsafe fn get_tsrm_ls_cache() -> *mut c_void {
    zts::get_ls_cache()
}

#[cfg(php_zts)]
#[inline]
pub unsafe fn get_tsrm_resource_from_cache(ls_cache: *mut c_void, id: i32) -> *mut c_void {
    zts::tsrmg_bulk(ls_cache, id)
}

#[cfg(php_zts)]
#[inline]
pub unsafe fn get_profiler_globals_from_cache(ls_cache: *mut c_void) -> *mut ProfilerGlobals {
    // SAFETY: As long as this is called during the times documented by
    // get_profiler_globals(), GLOBALS_ID will be set by PHP.
    let id = ptr::addr_of!(GLOBALS_ID).read();
    get_tsrm_resource_from_cache(ls_cache, id).cast()
}

/// Returns a pointer to the profiler globals for the current thread.
///
/// # Safety
/// - `GINIT` must have initialized the current thread's globals, and PHP's
///   current-thread accessor must be available.
/// - The current thread's globals must not have been destroyed. GSHUTDOWN
///   must use its supplied pointer: the allocation being destroyed can belong
///   to another thread, and the current thread's globals may already be freed.
#[inline]
pub unsafe fn get_profiler_globals() -> *mut ProfilerGlobals {
    #[cfg(test)]
    {
        test_symbols::get_profiler_globals()
    }

    #[cfg(all(php_zts, not(test)))]
    {
        get_profiler_globals_from_cache(get_tsrm_ls_cache())
    }

    #[cfg(all(not(php_zts), not(test)))]
    {
        ptr::addr_of_mut!(GLOBALS).cast()
    }
}

/// Initializes the module globals. Called by PHP during thread initialization (GINIT).
///
/// # Safety
/// - Must be called by PHP's module initialization system.
#[export_name = "ddog_php_prof_ginit"]
pub unsafe extern "C" fn ginit(globals_ptr: *mut c_void) {
    let globals = globals_ptr.cast::<ProfilerGlobals>();

    ptr::addr_of_mut!((*globals).thread_name).write(OnceCell::new());
    ptr::addr_of_mut!((*globals).thread_id).write(OnceCell::new());
    ptr::addr_of_mut!((*globals).request_locals).write(RefCell::new(RequestLocals::default()));

    #[cfg(php_zts)]
    crate::profiling::timeline::timeline_ginit(globals);

    ptr::addr_of_mut!((*globals).zend_mm_state).write(Cell::new(ZendMMState::new()));
    ptr::addr_of_mut!((*globals).interrupt_count).write(AtomicU32::new(0));
    #[cfg(target_os = "linux")]
    ptr::addr_of_mut!((*globals).process_context).write(RefCell::new(ProcessContextCache::new()));
    ptr::addr_of_mut!((*globals).allocation_profiling_stats)
        .write(UnsafeCell::new(MaybeUninit::uninit()));

    #[cfg(php_run_time_cache)]
    ptr::addr_of_mut!((*globals).cached_strings).write(UnsafeCell::new(MaybeUninit::new(
        RefCell::new(StringSet::new()),
    )));

    // SAFETY: PHP supplied the storage to initialize in GINIT.
    allocation::ginit(globals);
}

/// Destroys the supplied module globals during thread or module shutdown.
///
/// # Safety
/// - Must be called by PHP's module shutdown system, after users of this
///   allocation have stopped accessing it. The owning thread need not be
///   the calling thread, and may have already exited.
#[export_name = "ddog_php_prof_gshutdown"]
pub unsafe extern "C" fn gshutdown(globals_ptr: *mut c_void) {
    let globals = globals_ptr.cast::<ProfilerGlobals>();

    if let Some(profiler) = crate::profiling::profiler::Profiler::get() {
        // SAFETY: PHP supplied live globals with exclusive lifecycle access.
        // Remove any registration left by an aborted request before freeing them.
        let globals = unsafe { &*globals };
        profiler.remove_interrupt(crate::profiling::profiler::VmInterrupt {
            interrupt_count_ptr: ptr::from_ref(&globals.interrupt_count),
            engine_ptr: globals.request_locals.borrow().vm_interrupt_addr,
        });
    }

    #[cfg(php_zts)]
    crate::profiling::timeline::timeline_gshutdown(&*globals);

    #[cfg(target_os = "linux")]
    ptr::drop_in_place(ptr::addr_of_mut!((*globals).process_context));

    // SAFETY: PHP supplied the initialized allocation to destroy in GSHUTDOWN.
    allocation::gshutdown(globals);

    #[cfg(php_run_time_cache)]
    (*(*globals).cached_strings.get()).assume_init_drop();

    ptr::drop_in_place(ptr::addr_of_mut!((*globals).request_locals));
    ptr::drop_in_place(ptr::addr_of_mut!((*globals).thread_name));
}

// Unit tests are not loaded by PHP, so provide the PHP globals and TSRM symbol
// needed to link code retained in the test executable.
#[cfg(test)]
mod test_symbols {
    use super::*;

    struct TestGlobals(UnsafeCell<MaybeUninit<ProfilerGlobals>>);

    impl TestGlobals {
        fn new() -> Self {
            let mut globals = MaybeUninit::<ProfilerGlobals>::uninit();
            // SAFETY: Unit tests supply storage in place of PHP's GINIT callback.
            unsafe { ginit(globals.as_mut_ptr().cast()) };
            Self(UnsafeCell::new(globals))
        }
    }

    impl Drop for TestGlobals {
        fn drop(&mut self) {
            // SAFETY: The test thread has finished using this initialized storage.
            unsafe { gshutdown(self.0.get().cast()) };
        }
    }

    pub(super) fn get_profiler_globals() -> *mut ProfilerGlobals {
        thread_local! {
            static GLOBALS: TestGlobals = TestGlobals::new();
        }
        GLOBALS.with(|globals| globals.0.get().cast())
    }

    #[cfg(not(php_zts))]
    #[export_name = "compiler_globals"]
    static mut TEST_COMPILER_GLOBALS: core::mem::MaybeUninit<
        crate::profiling::zend::zend_compiler_globals,
    > = core::mem::MaybeUninit::zeroed();

    #[cfg(php_zts)]
    #[no_mangle]
    unsafe extern "C" fn tsrm_get_ls_cache() -> *mut core::ffi::c_void {
        core::ptr::null_mut()
    }
}
