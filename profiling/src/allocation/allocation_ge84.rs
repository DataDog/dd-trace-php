use crate::allocation::ALLOCATION_PROFILING_COUNT;
use crate::allocation::ALLOCATION_PROFILING_SIZE;
use crate::allocation::ALLOCATION_PROFILING_STATS;
use crate::bindings::{self as zend};
use crate::PROFILER_NAME;
use libc::{c_char, c_void, size_t};
use log::{debug, trace, warn};
use std::cell::UnsafeCell;
use std::ptr;
use std::sync::atomic::Ordering::SeqCst;

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
            alloc: alloc_prof_orig_alloc,
            realloc: alloc_prof_orig_realloc,
            free: alloc_prof_orig_free,
            gc: alloc_prof_orig_gc,
            shutdown: alloc_prof_orig_shutdown,
        }
    }
}

impl ZendMMState {}

thread_local! {
    /// Using an `UnsafeCell` here should be okay. There might not be any
    /// synchronisation issues, as it is used in as thread local and only
    /// mutated in RINIT and RSHUTDOWN.
    static ZEND_MM_STATE: UnsafeCell<ZendMMState> = const {
        UnsafeCell::new(ZendMMState::new())
    };
}

macro_rules! tls_zend_mm_state {
    ($x:ident) => {
        ZEND_MM_STATE.with(|cell| {
            let zend_mm_state = cell.get();
            (*zend_mm_state).$x
        })
    };
}

/// This initializes the thread locale variable `ZEND_MM_STATE` with respect to the currently
/// installed `zend_mm_heap` in ZendMM. It guarantees compliance with the safety guarantees
/// described in the `ZendMMState` structure, specifically for `ZendMMState::alloc`,
/// `ZendMMState::realloc`, `ZendMMState::free`, `ZendMMState::gc` and `ZendMMState::shutdown`.
/// This function may panic if called out of order!
pub fn alloc_prof_ginit() {
    ZEND_MM_STATE.with(|cell| {
        let zend_mm_state = cell.get();

        // Only need to create an observed heap once per thread. When we have it, we can just
        // install the observed heap via `zend::zend_mm_set_heap()`
        if unsafe { !(*zend_mm_state).heap.is_null() } {
            // This can only happen if either MINIT or GINIT is being called out of order.
            panic!("MINIT/GINIT was called with an already initialized allocation profiler. Most likely the SAPI did this without going through MSHUTDOWN/GSHUTDOWN before.");
        }

        // Safety: `zend_mm_get_heap()` always returns a non-null pointer to a valid heap structure
        let prev_heap = unsafe { zend::zend_mm_get_heap() };
        unsafe { ptr::addr_of_mut!((*zend_mm_state).prev_heap).write(prev_heap) };

        if !is_zend_mm() {
            // Neighboring custom memory handlers found in the currently used ZendMM heap
            debug!("Found another extension using the ZendMM custom handler hook");
            unsafe {
                zend::zend_mm_get_custom_handlers_ex(
                    prev_heap,
                    ptr::addr_of_mut!((*zend_mm_state).prev_custom_mm_alloc),
                    ptr::addr_of_mut!((*zend_mm_state).prev_custom_mm_free),
                    ptr::addr_of_mut!((*zend_mm_state).prev_custom_mm_realloc),
                    ptr::addr_of_mut!((*zend_mm_state).prev_custom_mm_gc),
                    ptr::addr_of_mut!((*zend_mm_state).prev_custom_mm_shutdown),
                );
                ptr::addr_of_mut!((*zend_mm_state).alloc).write(alloc_prof_prev_alloc);
                ptr::addr_of_mut!((*zend_mm_state).free).write(alloc_prof_prev_free);
                ptr::addr_of_mut!((*zend_mm_state).realloc).write(alloc_prof_prev_realloc);
                // `gc` handler can be NULL
                if (*zend_mm_state).prev_custom_mm_gc.is_none() {
                    ptr::addr_of_mut!((*zend_mm_state).gc).write(alloc_prof_orig_gc);
                } else {
                    ptr::addr_of_mut!((*zend_mm_state).gc).write(alloc_prof_prev_gc);
                }
                // `shutdown` handler can be NULL
                if (*zend_mm_state).prev_custom_mm_shutdown.is_none() {
                    ptr::addr_of_mut!((*zend_mm_state).shutdown).write(alloc_prof_orig_shutdown);
                } else {
                    ptr::addr_of_mut!((*zend_mm_state).shutdown).write(alloc_prof_prev_shutdown);
                }
            }
        }

        // Create a new (to be observed) heap and prepare custom handlers
        let heap = unsafe { zend::zend_mm_startup() };
        unsafe { ptr::addr_of_mut!((*zend_mm_state).heap).write(heap) };

        // install our custom handler to ZendMM
        unsafe {
            zend::zend_mm_set_custom_handlers_ex(
                (*zend_mm_state).heap,
                Some(alloc_prof_malloc),
                Some(alloc_prof_free),
                Some(alloc_prof_realloc),
                Some(alloc_prof_gc),
                Some(alloc_prof_shutdown),
            );
        }
        debug!("New observed heap created");
    });
}

/// This resets the thread locale variable `ZEND_MM_STATE` and frees allocated memory. It
/// guarantees compliance with the safety guarantees described in the `ZendMMState` structure,
/// specifically for `ZendMMState::alloc`, `ZendMMState::realloc`, `ZendMMState::free`,
/// `ZendMMState::gc` and `ZendMMState::shutdown`.
pub fn alloc_prof_gshutdown() {
    ZEND_MM_STATE.with(|cell| {
        let zend_mm_state = cell.get();
        unsafe {
            // Remove custom handlers to allow for ZendMM internal shutdown
            zend::zend_mm_set_custom_handlers_ex(
                (*zend_mm_state).heap,
                None,
                None,
                None,
                None,
                None,
            );

            // Reset ZEND_MM_STATE to defaults, now that the pointer are not know to the observed
            // heap anymore.
            ptr::addr_of_mut!((*zend_mm_state).alloc).write(alloc_prof_orig_alloc);
            ptr::addr_of_mut!((*zend_mm_state).free).write(alloc_prof_orig_free);
            ptr::addr_of_mut!((*zend_mm_state).realloc).write(alloc_prof_orig_realloc);
            ptr::addr_of_mut!((*zend_mm_state).gc).write(alloc_prof_orig_gc);
            ptr::addr_of_mut!((*zend_mm_state).shutdown).write(alloc_prof_orig_shutdown);
            ptr::addr_of_mut!((*zend_mm_state).prev_custom_mm_alloc).write(None);
            ptr::addr_of_mut!((*zend_mm_state).prev_custom_mm_free).write(None);
            ptr::addr_of_mut!((*zend_mm_state).prev_custom_mm_realloc).write(None);
            ptr::addr_of_mut!((*zend_mm_state).prev_custom_mm_gc).write(None);
            ptr::addr_of_mut!((*zend_mm_state).prev_custom_mm_shutdown).write(None);

            // This shutdown call will free the observed heap we created in `alloc_prof_custom_heap_init`
            zend::zend_mm_shutdown((*zend_mm_state).heap, true, true);

            // Now that the heap is gone, we need to NULL the pointer
            ptr::addr_of_mut!((*zend_mm_state).heap).write(ptr::null_mut());
            ptr::addr_of_mut!((*zend_mm_state).prev_heap).write(ptr::null_mut());
        }
        trace!("Observed heap was freed and `zend_mm_state` reset");
    });
}

pub fn alloc_prof_rinit() {
    ZEND_MM_STATE.with(|cell| {
        let zend_mm_state = cell.get();
        // Safety: `zend_mm_state.heap` got initialized in `MINIT` and is guaranteed to
        // be a non null pointer to a valid `zend::zend_mm_heap` struct
        unsafe {
            // Install our observed heap into ZendMM
            zend::zend_mm_set_heap((*zend_mm_state).heap);
        }
    });

    // `is_zend_mm()` should be false now, as we installed our custom handlers
    if is_zend_mm() {
        // Can't proceed with it being disabled, because that's a system-wide
        // setting, not per-request.
        panic!("Memory allocation profiling could not be enabled. Please feel free to fill an issue stating the PHP version and installed modules. Most likely the reason is your PHP binary was compiled with `ZEND_MM_CUSTOM` being disabled.");
    }
    trace!("Memory allocation profiling enabled.")
}

pub fn alloc_prof_rshutdown() {
    // If `is_zend_mm()` is true, the custom handlers have been reset to `None` or our observed
    // heap has been uninstalled. This is unexpected, therefore we will not touch the ZendMM
    // handlers anymore as resetting to prev handlers might result in segfaults and other undefined
    // behaviour.
    if is_zend_mm() {
        return;
    }

    ZEND_MM_STATE.with(|cell| {
        let zend_mm_state = cell.get();

        // Do a sanity check and see if something played with our heap
        let mut custom_mm_malloc: Option<zend::VmMmCustomAllocFn> = None;
        let mut custom_mm_free: Option<zend::VmMmCustomFreeFn> = None;
        let mut custom_mm_realloc: Option<zend::VmMmCustomReallocFn> = None;
        let mut custom_mm_gc: Option<zend::VmMmCustomGcFn> = None;
        let mut custom_mm_shutdown: Option<zend::VmMmCustomShutdownFn> = None;

        let heap = unsafe { (*zend_mm_state).heap };
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
            // Custom handlers are installed, but it's not us. Someone, somewhere might have
            // function pointers to our custom handlers. Best bet to avoid segfaults is to not
            // touch custom handlers in ZendMM and make sure our extension will not be
            // `dlclose()`-ed so the pointers stay valid
            let zend_extension =
                unsafe { zend::zend_get_extension(PROFILER_NAME.as_ptr() as *const c_char) };
            if !zend_extension.is_null() {
                // Safety: Checked for null pointer above.
                unsafe { ptr::addr_of_mut!((*zend_extension).handle).write(ptr::null_mut()) };
            }
            warn!("Found another extension using the custom heap which is unexpected at this point, so the extension handle was `null`'ed to avoid being `dlclose()`'ed.");
        } else {
            // This is the happy path. Restore previous heap.
            unsafe {
                zend::zend_mm_set_heap(
                    (*zend_mm_state).prev_heap
                );
            }
            trace!("Memory allocation profiling shutdown gracefully.");
        }
    });
}

unsafe extern "C" fn alloc_prof_malloc(len: size_t) -> *mut c_void {
    ALLOCATION_PROFILING_COUNT.fetch_add(1, SeqCst);
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, SeqCst);

    let ptr = tls_zend_mm_state!(alloc)(len);

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if zend::ddog_php_prof_get_current_execute_data().is_null() {
        return ptr;
    }

    ALLOCATION_PROFILING_STATS.with(|cell| {
        let mut allocations = cell.borrow_mut();
        allocations.track_allocation(len)
    });

    ptr
}

unsafe fn alloc_prof_prev_alloc(len: size_t) -> *mut c_void {
    ZEND_MM_STATE.with(|cell| {
        let zend_mm_state = cell.get();
        // Safety: `ZEND_MM_STATE.prev_heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap((*zend_mm_state).prev_heap);
        // Safety: `ZEND_MM_STATE.prev_custom_mm_alloc` will be initialised in
        // `alloc_prof_rinit()` and only point to this function when
        // `prev_custom_mm_alloc` is also initialised
        let ptr = ((*zend_mm_state).prev_custom_mm_alloc.unwrap_unchecked())(len);
        // Safety: `ZEND_MM_STATE.heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap((*zend_mm_state).heap);
        ptr
    })
}

unsafe fn alloc_prof_orig_alloc(len: size_t) -> *mut c_void {
    let ptr: *mut c_void = zend::_zend_mm_alloc(tls_zend_mm_state!(prev_heap), len);
    ptr
}

/// This function exists because when calling `zend_mm_set_custom_handlers()`,
/// you need to pass a pointer to a `free()` function as well, otherwise your
/// custom handlers won't be installed. We can not just point to the original
/// `zend::_zend_mm_free()` as the function definitions differ.
unsafe extern "C" fn alloc_prof_free(ptr: *mut c_void) {
    tls_zend_mm_state!(free)(ptr);
}

unsafe fn alloc_prof_prev_free(ptr: *mut c_void) {
    ZEND_MM_STATE.with(|cell| {
        let zend_mm_state = cell.get();
        // Safety: `ZEND_MM_STATE.prev_heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap((*zend_mm_state).prev_heap);
        // Safety: `ZEND_MM_STATE.prev_custom_mm_free` will be initialised in
        // `alloc_prof_rinit()` and only point to this function when
        // `prev_custom_mm_free` is also initialised
        ((*zend_mm_state).prev_custom_mm_free.unwrap_unchecked())(ptr);
        // Safety: `ZEND_MM_STATE.heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap((*zend_mm_state).heap);
    })
}

unsafe fn alloc_prof_orig_free(ptr: *mut c_void) {
    zend::_zend_mm_free(tls_zend_mm_state!(prev_heap), ptr);
}

unsafe extern "C" fn alloc_prof_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    ALLOCATION_PROFILING_COUNT.fetch_add(1, SeqCst);
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, SeqCst);

    let ptr = tls_zend_mm_state!(realloc)(prev_ptr, len);

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if zend::ddog_php_prof_get_current_execute_data().is_null() || ptr == prev_ptr {
        return ptr;
    }

    ALLOCATION_PROFILING_STATS.with(|cell| {
        let mut allocations = cell.borrow_mut();
        allocations.track_allocation(len)
    });

    ptr
}

unsafe fn alloc_prof_prev_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    ZEND_MM_STATE.with(|cell| {
        let zend_mm_state = cell.get();
        // Safety: `ZEND_MM_STATE.prev_heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap((*zend_mm_state).prev_heap);
        // Safety: `ZEND_MM_STATE.prev_custom_mm_realloc` will be initialised in
        // `alloc_prof_rinit()` and only point to this function when
        // `prev_custom_mm_realloc` is also initialised
        let ptr = ((*zend_mm_state).prev_custom_mm_realloc.unwrap_unchecked())(prev_ptr, len);
        // Safety: `ZEND_MM_STATE.heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap((*zend_mm_state).heap);
        ptr
    })
}

unsafe fn alloc_prof_orig_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    zend::_zend_mm_realloc(tls_zend_mm_state!(prev_heap), prev_ptr, len)
}

unsafe extern "C" fn alloc_prof_gc() -> size_t {
    tls_zend_mm_state!(gc)()
}

unsafe fn alloc_prof_prev_gc() -> size_t {
    ZEND_MM_STATE.with(|cell| {
        let zend_mm_state = cell.get();
        // Safety: `ZEND_MM_STATE.prev_heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap((*zend_mm_state).prev_heap);
        // Safety: `ZEND_MM_STATE.prev_custom_mm_gc` will be initialised in
        // `alloc_prof_rinit()` and only point to this function when
        // `prev_custom_mm_gc` is also initialised
        let freed = ((*zend_mm_state).prev_custom_mm_gc.unwrap_unchecked())();
        // Safety: `ZEND_MM_STATE.heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap((*zend_mm_state).heap);
        freed
    })
}

unsafe fn alloc_prof_orig_gc() -> size_t {
    zend::zend_mm_gc(tls_zend_mm_state!(prev_heap))
}

unsafe extern "C" fn alloc_prof_shutdown(full: bool, silent: bool) {
    tls_zend_mm_state!(shutdown)(full, silent);
}

unsafe fn alloc_prof_prev_shutdown(full: bool, silent: bool) {
    ZEND_MM_STATE.with(|cell| {
        let zend_mm_state = cell.get();
        // Safety: `ZEND_MM_STATE.prev_heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap((*zend_mm_state).prev_heap);
        // Safety: `ZEND_MM_STATE.prev_custom_mm_shutdown` will be initialised in
        // `alloc_prof_rinit()` and only point to this function when
        // `prev_custom_mm_shutdown` is also initialised
        ((*zend_mm_state).prev_custom_mm_shutdown.unwrap_unchecked())(full, silent);
        // Safety: `ZEND_MM_STATE.heap` got initialised in `alloc_prof_rinit()`
        zend::zend_mm_set_heap((*zend_mm_state).heap);
    })
}

unsafe fn alloc_prof_orig_shutdown(full: bool, silent: bool) {
    zend::zend_mm_shutdown(tls_zend_mm_state!(prev_heap), full, silent)
}

/// safe wrapper for `zend::is_zend_mm()`.
/// `true` means the internal ZendMM is being used, `false` means that a custom memory manager is
/// installed
fn is_zend_mm() -> bool {
    unsafe { zend::is_zend_mm() }
}
