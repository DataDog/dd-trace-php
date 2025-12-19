use crate::allocation::{collect_allocation, ALLOCATION_PROFILING_STATS};
use crate::bindings::{self as zend};
use crate::{RefCellExt, PROFILER_NAME};
use core::{cell::Cell, ptr};
use lazy_static::lazy_static;
use libc::{c_char, c_void, size_t};
use log::{debug, error, trace, warn};
use std::sync::atomic::Ordering::Relaxed;

#[cfg(feature = "debug_stats")]
use crate::allocation::{ALLOCATION_PROFILING_COUNT, ALLOCATION_PROFILING_SIZE};

#[derive(Copy, Clone)]
struct ZendMMState {
    /// The heap we create and set as the current heap in ZendMM
    heap: *mut zend::zend_mm_heap,
    /// The heap installed in ZendMM at the time we install our custom handlers
    prev_heap: *mut zend::zend_mm_heap,
    /// The engine's previous custom allocation function, if there is one.
    prev_custom_mm_alloc: Option<zend::VmMmCustomAllocFn>,
    /// The engine's previous custom reallocation function, if there is one.
    prev_custom_mm_realloc: Option<zend::VmMmCustomReallocFn>,
    /// The engine's previous custom free function, if there is one.
    prev_custom_mm_free: Option<zend::VmMmCustomFreeFn>,
    /// The engine's previous custom gc function, if there is one.
    prev_custom_mm_gc: Option<zend::VmMmCustomGcFn>,
    /// The engine's previous custom shutdown function, if there is one.
    prev_custom_mm_shutdown: Option<zend::VmMmCustomShutdownFn>,
    /// Safety: this function pointer is only allowed to point to
    /// `alloc_prof_prev_alloc()` when at the same time the
    /// `ZEND_MM_STATE.prev_custom_mm_alloc` is initialised to a valid function
    /// pointer, otherwise there will be dragons.
    alloc: unsafe fn(size_t) -> *mut c_void,
    /// Safety: this function pointer is only allowed to point to
    /// `alloc_prof_prev_realloc()` when at the same time the
    /// `ZEND_MM_STATE.prev_custom_mm_realloc` is initialised to a valid
    /// function pointer, otherwise there will be dragons.
    realloc: unsafe fn(*mut c_void, size_t) -> *mut c_void,
    /// Safety: this function pointer is only allowed to point to
    /// `alloc_prof_prev_free()` when at the same time the
    /// `ZEND_MM_STATE.prev_custom_mm_free` is initialised to a valid function
    /// pointer, otherwise there will be dragons.
    free: unsafe fn(*mut c_void),
    /// Safety: this function pointer is only allowed to point to
    /// `alloc_prof_prev_gc()` when at the same time the
    /// `ZEND_MM_STATE.prev_custom_mm_gc` is initialised to a valid function
    /// pointer, otherwise there will be dragons.
    gc: unsafe fn() -> size_t,
    /// Safety: this function pointer is only allowed to point to
    /// `alloc_prof_prev_shutdown()` when at the same time the
    /// `ZEND_MM_STATE.prev_custom_mm_shutdown` is initialised to a valid function
    /// pointer, otherwise there will be dragons.
    shutdown: unsafe fn(bool, bool),
}

#[track_caller]
fn initialization_panic() -> ! {
    panic!("Allocation profiler was not initialised properly. Please fill an issue stating the PHP version and the backtrace from this panic.");
}

unsafe fn alloc_prof_panic_gc() -> size_t {
    initialization_panic();
}

unsafe fn alloc_prof_panic_shutdown(_full: bool, _silent: bool) {
    initialization_panic();
}

impl ZendMMState {
    const fn new() -> ZendMMState {
        ZendMMState {
            // Safety: Using `ptr::null_mut()` might seem dangerous but actually it is okay in this
            // case. The `heap` and `prev_heap` fields will be initialized in the first call to
            // RINIT and only used after that. By using this "trick" we can get rid of all
            // `unwrap()` calls when using the `heap` or `prev_heap` field. Alternatively we could
            // use `unwrap_unchecked()` for the same performance characteristics.
            heap: ptr::null_mut(),
            prev_heap: ptr::null_mut(),
            prev_custom_mm_alloc: None,
            prev_custom_mm_realloc: None,
            prev_custom_mm_free: None,
            prev_custom_mm_gc: None,
            prev_custom_mm_shutdown: None,
            alloc: super::alloc_prof_panic_alloc,
            realloc: super::alloc_prof_panic_realloc,
            free: super::alloc_prof_panic_free,
            gc: alloc_prof_panic_gc,
            shutdown: alloc_prof_panic_shutdown,
        }
    }
}

#[cfg(php_zts)]
thread_local! {
    /// Using a `Cell` here should be okay. There might not be any
    /// synchronization issues, as it is used in as thread local and only
    /// mutated in RINIT and RSHUTDOWN.
    static ZEND_MM_STATE: Cell<ZendMMState> = const {
        Cell::new(ZendMMState::new())
    };
}

#[cfg(not(php_zts))]
/// Using a `Cell` here should be okay. There might not be any
/// synchronization issues, as it is only mutated in RINIT and RSHUTDOWN.
static mut ZEND_MM_STATE: Cell<ZendMMState> = Cell::new(ZendMMState::new());

#[cfg(php_zts)]
macro_rules! tls_zend_mm_state_copy {
    () => {
        ZEND_MM_STATE.get()
    };
}

#[cfg(not(php_zts))]
macro_rules! tls_zend_mm_state_copy {
    () => {
        unsafe { (*ptr::addr_of_mut!(ZEND_MM_STATE)).get() }
    };
}

macro_rules! tls_zend_mm_state_get {
    ($x:ident) => {
        tls_zend_mm_state_copy!().$x
    };
}

#[cfg(php_zts)]
macro_rules! tls_zend_mm_state_set {
    ($x:expr) => {
        ZEND_MM_STATE.set($x)
    };
}

#[cfg(not(php_zts))]
macro_rules! tls_zend_mm_state_set {
    ($x:expr) => {
        unsafe { (*ptr::addr_of!(ZEND_MM_STATE)).set($x) }
    };
}

lazy_static! {
    static ref JIT_ENABLED: bool = unsafe { zend::ddog_php_jit_enabled() };
}

pub fn first_rinit_should_disable_due_to_jit() -> bool {
    if *JIT_ENABLED
        && zend::PHP_VERSION_ID >= 80400
        && (80400..80406).contains(&crate::RUNTIME_PHP_VERSION_ID.load(Relaxed))
    {
        error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.4.7. See https://github.com/DataDog/dd-trace-php/pull/3199");
        true
    } else {
        false
    }
}

/// This initializes the thread locale variable `ZEND_MM_STATE` with respect to the currently
/// installed `zend_mm_heap` in ZendMM. It guarantees compliance with the safety guarantees
/// described in the `ZendMMState` structure, specifically for `ZendMMState::alloc`,
/// `ZendMMState::realloc`, `ZendMMState::free`, `ZendMMState::gc` and `ZendMMState::shutdown`.
/// This function may panic if called out of order!
pub fn alloc_prof_ginit() {
    unsafe { zend::ddog_php_opcache_init_handle() };
    let zend_mm_state_init = |mut zend_mm_state: ZendMMState| -> ZendMMState {
        // Only need to create an observed heap once per thread. When we have it, we can just
        // install the observed heap via `zend::zend_mm_set_heap()`
        if !zend_mm_state.heap.is_null() {
            // This can only happen if either MINIT or GINIT is being called out of order.
            panic!("MINIT/GINIT was called with an already initialized allocation profiler. Most likely the SAPI did this without going through MSHUTDOWN/GSHUTDOWN before.");
        }

        // Safety: `zend_mm_get_heap()` always returns a non-null pointer to a valid heap structure
        let prev_heap = unsafe { zend::zend_mm_get_heap() };
        zend_mm_state.prev_heap = prev_heap;

        if !is_zend_mm() {
            // Neighboring custom memory handlers found in the currently used ZendMM heap
            debug!("Found another extension using the ZendMM custom handler hook");
            unsafe {
                zend::zend_mm_get_custom_handlers_ex(
                    prev_heap,
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_alloc),
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_free),
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_realloc),
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_gc),
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_shutdown),
                );
                zend_mm_state.alloc = alloc_prof_prev_alloc;
                zend_mm_state.free = alloc_prof_prev_free;
                zend_mm_state.realloc = alloc_prof_prev_realloc;
                // `gc` handler can be NULL
                zend_mm_state.gc = if zend_mm_state.prev_custom_mm_gc.is_none() {
                    alloc_prof_orig_gc
                } else {
                    alloc_prof_prev_gc
                };
                // `shutdown` handler can be NULL
                zend_mm_state.shutdown = if zend_mm_state.prev_custom_mm_shutdown.is_none() {
                    alloc_prof_orig_shutdown
                } else {
                    alloc_prof_prev_shutdown
                }
            }
        } else {
            zend_mm_state.alloc = alloc_prof_orig_alloc;
            zend_mm_state.free = alloc_prof_orig_free;
            zend_mm_state.realloc = alloc_prof_orig_realloc;
            zend_mm_state.gc = alloc_prof_orig_gc;
            zend_mm_state.shutdown = alloc_prof_orig_shutdown;
        }

        // Create a new (to be observed) heap and prepare custom handlers
        let heap = unsafe { zend::zend_mm_startup() };
        zend_mm_state.heap = heap;

        // install our custom handler to ZendMM
        unsafe {
            zend::zend_mm_set_custom_handlers_ex(
                zend_mm_state.heap,
                Some(alloc_prof_malloc),
                Some(alloc_prof_free),
                Some(alloc_prof_realloc),
                Some(alloc_prof_gc),
                Some(alloc_prof_shutdown),
            );
        }
        debug!("New observed heap created");
        zend_mm_state
    };

    let mm_state = tls_zend_mm_state_copy!();
    tls_zend_mm_state_set!(zend_mm_state_init(mm_state));
}

/// This resets the thread locale variable `ZEND_MM_STATE` and frees allocated memory. It
/// guarantees compliance with the safety guarantees described in the `ZendMMState` structure,
/// specifically for `ZendMMState::alloc`, `ZendMMState::realloc`, `ZendMMState::free`,
/// `ZendMMState::gc` and `ZendMMState::shutdown`.
pub fn alloc_prof_gshutdown() {
    let zend_mm_state_shutdown = |mut zend_mm_state: ZendMMState| -> ZendMMState {
        unsafe {
            // Remove custom handlers to allow for ZendMM internal shutdown
            zend::zend_mm_set_custom_handlers_ex(zend_mm_state.heap, None, None, None, None, None);

            // Reset ZEND_MM_STATE to defaults, now that the pointer are not know to the observed
            // heap anymore.
            zend_mm_state.alloc = alloc_prof_orig_alloc;
            zend_mm_state.free = alloc_prof_orig_free;
            zend_mm_state.realloc = alloc_prof_orig_realloc;
            zend_mm_state.gc = alloc_prof_orig_gc;
            zend_mm_state.shutdown = alloc_prof_orig_shutdown;
            zend_mm_state.prev_custom_mm_alloc = None;
            zend_mm_state.prev_custom_mm_free = None;
            zend_mm_state.prev_custom_mm_realloc = None;
            zend_mm_state.prev_custom_mm_gc = None;
            zend_mm_state.prev_custom_mm_shutdown = None;

            // This shutdown call will free the observed heap we created in `alloc_prof_custom_heap_init`
            zend::zend_mm_shutdown(zend_mm_state.heap, true, true);

            // Now that the heap is gone, we need to NULL the pointer
            zend_mm_state.heap = ptr::null_mut();
            zend_mm_state.prev_heap = ptr::null_mut();
        }
        trace!("Observed heap was freed and `zend_mm_state` reset");
        zend_mm_state
    };

    let mm_state = tls_zend_mm_state_copy!();
    tls_zend_mm_state_set!(zend_mm_state_shutdown(mm_state));
}

pub fn alloc_prof_rinit() {
    let heap = tls_zend_mm_state_get!(heap);
    // Install our observed heap into ZendMM
    // Safety: `heap` got initialized in `MINIT` and is guaranteed to be a
    // non-null pointer to a valid `zend::zend_mm_heap` struct.
    unsafe { zend::zend_mm_set_heap(heap) };

    // `is_zend_mm()` should be false now, as we installed our custom handlers
    if is_zend_mm() {
        // Can't proceed with it being disabled, because that's a system-wide
        // setting, not per-request.
        panic!("Memory allocation profiling could not be enabled. Please feel free to fill an issue stating the PHP version and installed modules. Most likely the reason is your PHP binary was compiled with `ZEND_MM_CUSTOM` being disabled.");
    }
    trace!("Memory allocation profiling enabled.")
}

#[allow(unknown_lints, unpredictable_function_pointer_comparisons)]
pub fn alloc_prof_rshutdown() {
    // If `is_zend_mm()` is true, the custom handlers have been reset to `None` or our observed
    // heap has been uninstalled. This is unexpected, therefore we will not touch the ZendMM
    // handlers anymore as resetting to prev handlers might result in segfaults and other undefined
    // behaviour.
    if is_zend_mm() {
        return;
    }

    let zend_mm_state_shutdown = |zend_mm_state: ZendMMState| {
        // Do a sanity check and see if something played with our heap
        let mut custom_mm_malloc: Option<zend::VmMmCustomAllocFn> = None;
        let mut custom_mm_free: Option<zend::VmMmCustomFreeFn> = None;
        let mut custom_mm_realloc: Option<zend::VmMmCustomReallocFn> = None;
        let mut custom_mm_gc: Option<zend::VmMmCustomGcFn> = None;
        let mut custom_mm_shutdown: Option<zend::VmMmCustomShutdownFn> = None;

        let heap = zend_mm_state.heap;

        // The heap ptr can be null if a fork happens outside the request.
        if heap.is_null() {
            return;
        }

        unsafe {
            zend::zend_mm_get_custom_handlers_ex(
                heap,
                &mut custom_mm_malloc,
                &mut custom_mm_free,
                &mut custom_mm_realloc,
                &mut custom_mm_gc,
                &mut custom_mm_shutdown,
            );
        }
        if custom_mm_free != Some(alloc_prof_free)
            || custom_mm_malloc != Some(alloc_prof_malloc)
            || custom_mm_realloc != Some(alloc_prof_realloc)
            || custom_mm_gc != Some(alloc_prof_gc)
            || custom_mm_shutdown != Some(alloc_prof_shutdown)
        {
            // Custom handlers are installed, but it's not us. Someone,
            // somewhere might have function pointers to our custom handlers.
            // The best bet to avoid segfaults is to not touch custom handlers
            // in ZendMM and make sure our extension will not be `dlclose()`-ed
            // so the pointers stay valid.
            let zend_extension =
                unsafe { zend::zend_get_extension(PROFILER_NAME.as_ptr() as *const c_char) };
            if !zend_extension.is_null() {
                // Safety: Checked for a null pointer above.
                unsafe { ptr::addr_of_mut!((*zend_extension).handle).write(ptr::null_mut()) };
            }
            warn!("Found another extension using the custom heap which is unexpected at this point, so the extension handle was `null`'ed to avoid being `dlclose()`'ed.");
        } else {
            // This is the happy path. Restore the previous heap.
            unsafe {
                zend::zend_mm_set_heap(zend_mm_state.prev_heap);
            }
            trace!("Memory allocation profiling shutdown gracefully.");
        }
    };

    zend_mm_state_shutdown(tls_zend_mm_state_copy!());
}

unsafe extern "C" fn alloc_prof_malloc(len: size_t) -> *mut c_void {
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_COUNT.fetch_add(1, Relaxed);
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, Relaxed);

    let ptr = tls_zend_mm_state_get!(alloc)(len);

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if zend::ddog_php_prof_get_current_execute_data().is_null() {
        return ptr;
    }

    if ALLOCATION_PROFILING_STATS
        .borrow_mut_or_false(|allocations| allocations.should_collect_allocation(len))
    {
        collect_allocation(len);
    }

    ptr
}

unsafe fn alloc_prof_prev_alloc(len: size_t) -> *mut c_void {
    let alloc = |zend_mm_state: ZendMMState| {
        // Safety: `ZEND_MM_STATE.prev_heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap(zend_mm_state.prev_heap);
        // Safety: `ZEND_MM_STATE.prev_custom_mm_alloc` will be initialised in
        // `alloc_prof_rinit()` and only point to this function when
        // `prev_custom_mm_alloc` is also initialised
        let ptr = zend_mm_state.prev_custom_mm_alloc.unwrap_unchecked()(len);
        // Safety: `ZEND_MM_STATE.heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap(zend_mm_state.heap);
        ptr
    };

    alloc(tls_zend_mm_state_copy!())
}

unsafe fn alloc_prof_orig_alloc(len: size_t) -> *mut c_void {
    let ptr: *mut c_void = zend::_zend_mm_alloc(tls_zend_mm_state_get!(prev_heap), len);
    ptr
}

/// This function exists because when calling `zend_mm_set_custom_handlers()`,
/// you need to pass a pointer to a `free()` function as well, otherwise your
/// custom handlers won't be installed. We can not just point to the original
/// `zend::_zend_mm_free()` as the function definitions differ.
unsafe extern "C" fn alloc_prof_free(ptr: *mut c_void) {
    tls_zend_mm_state_get!(free)(ptr);
}

unsafe fn alloc_prof_prev_free(ptr: *mut c_void) {
    let free = |zend_mm_state: ZendMMState| {
        // Safety: `ZEND_MM_STATE.prev_heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap(zend_mm_state.prev_heap);
        // Safety: `ZEND_MM_STATE.prev_custom_mm_free` will be initialised in
        // `alloc_prof_rinit()` and only point to this function when
        // `prev_custom_mm_free` is also initialised
        (zend_mm_state.prev_custom_mm_free.unwrap_unchecked())(ptr);
        // Safety: `ZEND_MM_STATE.heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap(zend_mm_state.heap);
    };

    free(tls_zend_mm_state_copy!())
}

unsafe fn alloc_prof_orig_free(ptr: *mut c_void) {
    zend::_zend_mm_free(tls_zend_mm_state_get!(prev_heap), ptr);
}

unsafe extern "C" fn alloc_prof_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_COUNT.fetch_add(1, Relaxed);
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, Relaxed);

    let ptr = tls_zend_mm_state_get!(realloc)(prev_ptr, len);

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if zend::ddog_php_prof_get_current_execute_data().is_null() || ptr::eq(ptr, prev_ptr) {
        return ptr;
    }

    if ALLOCATION_PROFILING_STATS
        .borrow_mut_or_false(|allocations| allocations.should_collect_allocation(len))
    {
        collect_allocation(len);
    }

    ptr
}

unsafe fn alloc_prof_prev_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    let realloc = |zend_mm_state: ZendMMState| {
        // Safety: `ZEND_MM_STATE.prev_heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap(zend_mm_state.prev_heap);
        // Safety: `ZEND_MM_STATE.prev_custom_mm_realloc` will be initialised in
        // `alloc_prof_rinit()` and only point to this function when
        // `prev_custom_mm_realloc` is also initialised
        let ptr = zend_mm_state.prev_custom_mm_realloc.unwrap_unchecked()(prev_ptr, len);
        // Safety: `ZEND_MM_STATE.heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap(zend_mm_state.heap);
        ptr
    };

    realloc(tls_zend_mm_state_copy!())
}

unsafe fn alloc_prof_orig_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    zend::_zend_mm_realloc(tls_zend_mm_state_get!(prev_heap), prev_ptr, len)
}

unsafe extern "C" fn alloc_prof_gc() -> size_t {
    tls_zend_mm_state_get!(gc)()
}

unsafe fn alloc_prof_prev_gc() -> size_t {
    let gc = |zend_mm_state: ZendMMState| {
        // Safety: `ZEND_MM_STATE.prev_heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap(zend_mm_state.prev_heap);
        // Safety: `ZEND_MM_STATE.prev_custom_mm_gc` will be initialised in
        // `alloc_prof_rinit()` and only point to this function when
        // `prev_custom_mm_gc` is also initialised
        let freed = zend_mm_state.prev_custom_mm_gc.unwrap_unchecked()();
        // Safety: `ZEND_MM_STATE.heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap(zend_mm_state.heap);
        freed
    };

    gc(tls_zend_mm_state_copy!())
}

unsafe fn alloc_prof_orig_gc() -> size_t {
    zend::zend_mm_gc(tls_zend_mm_state_get!(prev_heap))
}

unsafe extern "C" fn alloc_prof_shutdown(full: bool, silent: bool) {
    tls_zend_mm_state_get!(shutdown)(full, silent);
}

unsafe fn alloc_prof_prev_shutdown(full: bool, silent: bool) {
    let shutdown = |zend_mm_state: ZendMMState| {
        // Safety: `ZEND_MM_STATE.prev_heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap(zend_mm_state.prev_heap);
        // Safety: `ZEND_MM_STATE.prev_custom_mm_shutdown` will be initialised in
        // `alloc_prof_rinit()` and only point to this function when
        // `prev_custom_mm_shutdown` is also initialised
        zend_mm_state.prev_custom_mm_shutdown.unwrap_unchecked()(full, silent);
        // Safety: `ZEND_MM_STATE.heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap(zend_mm_state.heap);
    };

    shutdown(tls_zend_mm_state_copy!())
}

unsafe fn alloc_prof_orig_shutdown(full: bool, silent: bool) {
    zend::zend_mm_shutdown(tls_zend_mm_state_get!(prev_heap), full, silent)
}

/// safe wrapper for `zend::is_zend_mm()`.
/// `true` means the internal ZendMM is being used, `false` means that a custom memory manager is
/// installed
fn is_zend_mm() -> bool {
    unsafe { zend::is_zend_mm() }
}
