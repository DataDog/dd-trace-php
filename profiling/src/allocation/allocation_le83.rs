use crate::allocation::{allocation_profiling_stats_should_collect, collect_allocation};
use crate::bindings::{
    self as zend, datadog_php_zif_handler, ddog_php_prof_copy_long_into_zval, install_handler,
};
use crate::{tls_zend_mm_state_copy_le83, tls_zend_mm_state_get_le83, tls_zend_mm_state_set_le83};
use crate::{RefCellExt, PROFILER_NAME, REQUEST_LOCALS};
use core::ptr;
use lazy_static::lazy_static;
use libc::{c_char, c_int, c_void, size_t};
use log::{debug, trace, warn};
use std::sync::atomic::Ordering::Relaxed;

#[cfg(feature = "debug_stats")]
use crate::allocation::{ALLOCATION_PROFILING_COUNT, ALLOCATION_PROFILING_SIZE};

static mut GC_MEM_CACHES_HANDLER: zend::InternalFunctionHandler = None;

type ZendHeapPrepareFn = unsafe fn(heap: *mut zend::_zend_mm_heap) -> c_int;
type ZendHeapRestoreFn = unsafe fn(heap: *mut zend::_zend_mm_heap, custom_heap: c_int);

#[derive(Copy, Clone)]
pub struct ZendMMState {
    /// The heap installed in ZendMM at the time we install our custom
    /// handlers, this is also the heap our custom handlers are installed in.
    /// We need this in case there is no custom handlers installed prior to us,
    /// in order to forward our allocation calls to this heap.
    heap: Option<*mut zend::zend_mm_heap>,
    /// The engine's previous custom allocation function, if there is one.
    prev_custom_mm_alloc: Option<zend::VmMmCustomAllocFn>,
    /// The engine's previous custom reallocation function, if there is one.
    prev_custom_mm_realloc: Option<zend::VmMmCustomReallocFn>,
    /// The engine's previous custom free function, if there is one.
    prev_custom_mm_free: Option<zend::VmMmCustomFreeFn>,
    prepare_restore_zend_heap: (ZendHeapPrepareFn, ZendHeapRestoreFn),
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
}

impl ZendMMState {
    #[allow(clippy::new_without_default)]
    pub const fn new() -> ZendMMState {
        ZendMMState {
            heap: None,
            prev_custom_mm_alloc: None,
            prev_custom_mm_realloc: None,
            prev_custom_mm_free: None,
            prepare_restore_zend_heap: (prepare_zend_heap, restore_zend_heap),
            alloc: super::alloc_prof_panic_alloc,
            realloc: super::alloc_prof_panic_realloc,
            free: super::alloc_prof_panic_free,
        }
    }
}

fn alloc_prof_needs_disabled_for_jit(version: u32) -> bool {
    // see https://github.com/php/php-src/pull/11380
    (80000..80121).contains(&version)
        || (80200..80208).contains(&version)
        || (80400..80407).contains(&version)
}

lazy_static! {
    static ref JIT_ENABLED: bool = super::jit_enabled();
}

pub fn alloc_prof_ginit() {}

pub fn first_rinit_should_disable_due_to_jit() -> bool {
    alloc_prof_needs_disabled_for_jit(crate::RUNTIME_PHP_VERSION_ID.load(Relaxed)) && *JIT_ENABLED
}

/// # Safety
/// Must be called exactly once per PHP module request init (RINIT).
pub unsafe fn alloc_prof_rinit(php_thread: crate::OnPhpThread) {
    let zend_mm_state_init = |mut zend_mm_state: ZendMMState| -> ZendMMState {
        // Safety: `zend_mm_get_heap()` always returns a non-null pointer to a valid heap structure
        let heap = unsafe { zend::zend_mm_get_heap() };

        zend_mm_state.heap = Some(heap);

        if !is_zend_mm() {
            // Neighboring custom memory handlers found
            debug!("Found another extension using the ZendMM custom handler hook");
            unsafe {
                let mut malloc_ptr: *mut c_void = ptr::null_mut();
                let mut free_ptr: *mut c_void = ptr::null_mut();
                let mut realloc_ptr: *mut c_void = ptr::null_mut();
                zend::zend_mm_get_custom_handlers(
                    heap,
                    &mut malloc_ptr,
                    &mut free_ptr,
                    &mut realloc_ptr,
                );
                zend_mm_state.prev_custom_mm_alloc = if malloc_ptr.is_null() {
                    None
                } else {
                    Some(std::mem::transmute(malloc_ptr))
                };
                zend_mm_state.prev_custom_mm_free = if free_ptr.is_null() {
                    None
                } else {
                    Some(std::mem::transmute(free_ptr))
                };
                zend_mm_state.prev_custom_mm_realloc = if realloc_ptr.is_null() {
                    None
                } else {
                    Some(std::mem::transmute(realloc_ptr))
                };
            }
            zend_mm_state.alloc = alloc_prof_prev_alloc;
            zend_mm_state.free = alloc_prof_prev_free;
            zend_mm_state.realloc = alloc_prof_prev_realloc;
            zend_mm_state.prepare_restore_zend_heap =
                (prepare_zend_heap_none, restore_zend_heap_none);
        } else {
            zend_mm_state.alloc = alloc_prof_orig_alloc;
            zend_mm_state.free = alloc_prof_orig_free;
            zend_mm_state.realloc = alloc_prof_orig_realloc;
            zend_mm_state.prepare_restore_zend_heap = (prepare_zend_heap, restore_zend_heap);

            // Reset previous handlers to None. There might be a chaotic neighbor that
            // registered custom handlers in an earlier request, but it doesn't do so for this
            // request. In that case we would restore the neighbouring extensions custom
            // handlers to the ZendMM in RSHUTDOWN which would lead to a crash!
            zend_mm_state.prev_custom_mm_alloc = None;
            zend_mm_state.prev_custom_mm_free = None;
            zend_mm_state.prev_custom_mm_realloc = None;
        }

        // install our custom handler to ZendMM
        zend::ddog_php_prof_zend_mm_set_custom_handlers(
            heap,
            Some(alloc_prof_malloc),
            Some(alloc_prof_free),
            Some(alloc_prof_realloc),
        );
        zend_mm_state
    };

    let mm_state = tls_zend_mm_state_copy_le83!(php_thread);
    tls_zend_mm_state_set_le83!(php_thread, zend_mm_state_init(mm_state));

    // `is_zend_mm()` should be false now, as we installed our custom handlers
    if is_zend_mm() {
        // Can't proceed with it being disabled, because that's a system-wide
        // setting, not per-request.
        panic!("Memory allocation profiling could not be enabled. Please feel free to fill an issue stating the PHP version and installed modules. Most likely the reason is your PHP binary was compiled with `ZEND_MM_CUSTOM` being disabled.");
    }
    trace!("Memory allocation profiling enabled.")
}

#[allow(unknown_lints, unpredictable_function_pointer_comparisons)]
/// # Safety
/// Must be called exactly once per PHP module request shutdown (RSHUTDOWN).
pub unsafe fn alloc_prof_rshutdown(php_thread: crate::OnPhpThread) {
    // If `is_zend_mm()` is true, the custom handlers have already been reset
    // to `None`. This is unexpected, therefore we will not touch the ZendMM
    // handlers anymore as resetting to prev handlers might result in segfaults
    // and other undefined behavior.
    if is_zend_mm() {
        return;
    }

    let zend_mm_state_shutdown = |mut zend_mm_state: ZendMMState| -> ZendMMState {
        // SAFETY: UnsafeCell::get() ensures non-null, and the object should
        // be valid for reads during rshutdown.
        let Some(heap) = zend_mm_state.heap else {
            // The heap can be None if a fork happens outside the request.
            return zend_mm_state;
        };

        let mut malloc_ptr: *mut c_void = ptr::null_mut();
        let mut free_ptr: *mut c_void = ptr::null_mut();
        let mut realloc_ptr: *mut c_void = ptr::null_mut();
        unsafe {
            zend::zend_mm_get_custom_handlers(
                heap,
                &mut malloc_ptr,
                &mut free_ptr,
                &mut realloc_ptr,
            );
        }
        let custom_mm_malloc = if malloc_ptr.is_null() {
            None
        } else {
            Some(unsafe { std::mem::transmute(malloc_ptr) })
        };
        let custom_mm_free = if free_ptr.is_null() {
            None
        } else {
            Some(unsafe { std::mem::transmute(free_ptr) })
        };
        let custom_mm_realloc = if realloc_ptr.is_null() {
            None
        } else {
            Some(unsafe { std::mem::transmute(realloc_ptr) })
        };
        let is_our_malloc = custom_mm_malloc.map_or(false, |f: zend::VmMmCustomAllocFn| {
            core::ptr::eq(f as *const (), alloc_prof_malloc as *const ())
        });
        let is_our_free = custom_mm_free.map_or(false, |f: zend::VmMmCustomFreeFn| {
            core::ptr::eq(f as *const (), alloc_prof_free as *const ())
        });
        let is_our_realloc = custom_mm_realloc.map_or(false, |f: zend::VmMmCustomReallocFn| {
            core::ptr::eq(f as *const (), alloc_prof_realloc as *const ())
        });
        if !is_our_free || !is_our_malloc || !is_our_realloc {
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
            // This is the happy path. Restore previously installed custom handlers or
            // NULL-pointers to the ZendMM. In case all pointers are NULL, the ZendMM will reset
            // the `use_custom_heap` flag to `None`, in case we restore a neighbouring extension
            // custom handlers, ZendMM will call those for future allocations. In either way, we
            // have unregistered and we'll not receive any allocation calls anymore.
            zend::ddog_php_prof_zend_mm_set_custom_handlers(
                heap,
                zend_mm_state.prev_custom_mm_alloc,
                zend_mm_state.prev_custom_mm_free,
                zend_mm_state.prev_custom_mm_realloc,
            );
            trace!("Memory allocation profiling shutdown gracefully.");
        }
        zend_mm_state.heap = None;
        zend_mm_state
    };

    let mm_state = tls_zend_mm_state_copy_le83!(php_thread);
    tls_zend_mm_state_set_le83!(php_thread, zend_mm_state_shutdown(mm_state));
}

pub fn alloc_prof_startup() {
    let handle = datadog_php_zif_handler::new(
        c"gc_mem_caches",
        ptr::addr_of_mut!(GC_MEM_CACHES_HANDLER),
        Some(alloc_prof_gc_mem_caches),
    );
    install_handler(handle);
}

/// Overrides the ZendMM heap's `use_custom_heap` flag with the default
/// `ZEND_MM_CUSTOM_HEAP_NONE` (currently a `u32: 0`). This needs to be done,
/// as the `zend_mm_gc()` and `zend_mm_shutdown()` functions alter behavior
/// in case custom handlers are installed.
///
/// - `zend_mm_gc()` will not do anything anymore.
/// - `zend_mm_shutdown()` won't clean up chunks anymore (leaks memory)
///
/// The `_zend_mm_heap`-struct itself is private, but we are lucky, as the
/// `use_custom_heap` flag is the first element and thus the first 4 bytes.
/// Take care and call `restore_zend_heap()` afterward!
unsafe fn prepare_zend_heap(heap: *mut zend::_zend_mm_heap) -> c_int {
    let custom_heap: c_int = ptr::read(heap as *const c_int);
    ptr::write(heap as *mut c_int, zend::ZEND_MM_CUSTOM_HEAP_NONE as c_int);
    custom_heap
}

fn prepare_zend_heap_none(_heap: *mut zend::_zend_mm_heap) -> c_int {
    0
}

/// Restore the ZendMM heap's `use_custom_heap` flag, see `prepare_zend_heap` for details
unsafe fn restore_zend_heap(heap: *mut zend::_zend_mm_heap, custom_heap: c_int) {
    ptr::write(heap as *mut c_int, custom_heap);
}

fn restore_zend_heap_none(_heap: *mut zend::_zend_mm_heap, _custom_heap: c_int) {}

/// The PHP userland function `gc_mem_caches()` directly calls the `zend_mm_gc()` function which
/// does nothing in case custom handlers are installed on the heap used. So we prepare the heap for
/// this operation, call the original function and restore the heap again
unsafe extern "C" fn alloc_prof_gc_mem_caches(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    // Not logging here to avoid potentially overwhelming logs.
    let allocation_profiling: bool = REQUEST_LOCALS
        .borrow_or_false(|locals| locals.system_settings().profiling_allocation_enabled);

    if let Some(func) = GC_MEM_CACHES_HANDLER {
        if allocation_profiling {
            let heap = zend::zend_mm_get_heap();
            // SAFETY: GC mem-caches handler is called on a PHP thread within GINIT–GSHUTDOWN.
            let php_thread = unsafe { crate::OnPhpThread::new() };
            let (prepare, restore) =
                tls_zend_mm_state_get_le83!(php_thread, prepare_restore_zend_heap);
            let custom_heap = prepare(heap);
            func(execute_data, return_value);
            restore(heap, custom_heap);
        } else {
            func(execute_data, return_value);
        }
    } else {
        ddog_php_prof_copy_long_into_zval(return_value, 0);
    }
}

unsafe extern "C" fn alloc_prof_malloc(len: size_t) -> *mut c_void {
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_COUNT.fetch_add(1, Relaxed);
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, Relaxed);

    // SAFETY: alloc hook runs on a PHP thread within GINIT–GSHUTDOWN.
    let php_thread = crate::OnPhpThread::new();
    let ptr = tls_zend_mm_state_get_le83!(php_thread, alloc)(len);

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if crate::universal::profiling_current_execute_data(php_thread).is_null() {
        return ptr;
    }

    if allocation_profiling_stats_should_collect(len) {
        collect_allocation(len);
    }

    ptr
}

unsafe fn alloc_prof_prev_alloc(len: size_t) -> *mut c_void {
    // Safety: `ZEND_MM_STATE.prev_custom_mm_alloc` will be initialised in
    // `alloc_prof_rinit()` and only point to this function when
    // `prev_custom_mm_alloc` is also initialised
    // SAFETY: alloc hook runs on a PHP thread within GINIT–GSHUTDOWN.
    let php_thread = crate::OnPhpThread::new();
    let alloc = tls_zend_mm_state_get_le83!(php_thread, prev_custom_mm_alloc).unwrap();
    alloc(len)
}

unsafe fn alloc_prof_orig_alloc(len: size_t) -> *mut c_void {
    // Safety: `ZEND_MM_STATE.heap` will be initialised in `alloc_prof_rinit()` and custom ZendMM
    // handlers are only installed and pointing to this function if initialization was succesful.
    // SAFETY: alloc hook runs on a PHP thread within GINIT–GSHUTDOWN.
    let php_thread = crate::OnPhpThread::new();
    let heap = tls_zend_mm_state_get_le83!(php_thread, heap).unwrap_unchecked();
    let (prepare, restore) = tls_zend_mm_state_get_le83!(php_thread, prepare_restore_zend_heap);
    let custom_heap = prepare(heap);
    let ptr: *mut c_void = zend::_zend_mm_alloc(heap, len);
    restore(heap, custom_heap);
    ptr
}

/// This function exists because when calling `zend_mm_set_custom_handlers()`,
/// you need to pass a pointer to a `free()` function as well, otherwise your
/// custom handlers won't be installed. We cannot just point to the original
/// `zend::_zend_mm_free()` as the function definitions differ.
unsafe extern "C" fn alloc_prof_free(ptr: *mut c_void) {
    // SAFETY: free hook runs on a PHP thread within GINIT–GSHUTDOWN.
    let php_thread = crate::OnPhpThread::new();
    tls_zend_mm_state_get_le83!(php_thread, free)(ptr);
}

unsafe fn alloc_prof_prev_free(ptr: *mut c_void) {
    // Safety: `ZEND_MM_STATE.prev_custom_mm_free` will be initialised in
    // `alloc_prof_rinit()` and only point to this function when
    // `prev_custom_mm_free` is also initialised
    // SAFETY: free hook runs on a PHP thread within GINIT–GSHUTDOWN.
    let php_thread = crate::OnPhpThread::new();
    let free = tls_zend_mm_state_get_le83!(php_thread, prev_custom_mm_free).unwrap();
    free(ptr)
}

unsafe fn alloc_prof_orig_free(ptr: *mut c_void) {
    // Safety: `ZEND_MM_STATE.heap` will be initialised in `alloc_prof_rinit()` and custom ZendMM
    // handlers are only installed and pointing to this function if initialization was succesful.
    // SAFETY: free hook runs on a PHP thread within GINIT–GSHUTDOWN.
    let php_thread = crate::OnPhpThread::new();
    let heap = tls_zend_mm_state_get_le83!(php_thread, heap).unwrap_unchecked();
    zend::_zend_mm_free(heap, ptr);
}

unsafe extern "C" fn alloc_prof_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_COUNT.fetch_add(1, Relaxed);
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, Relaxed);

    // SAFETY: realloc hook runs on a PHP thread within GINIT–GSHUTDOWN.
    let php_thread = crate::OnPhpThread::new();
    let ptr = tls_zend_mm_state_get_le83!(php_thread, realloc)(prev_ptr, len);

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if crate::universal::profiling_current_execute_data(php_thread).is_null()
        || ptr::eq(ptr, prev_ptr)
    {
        return ptr;
    }

    if allocation_profiling_stats_should_collect(len) {
        collect_allocation(len);
    }

    ptr
}

unsafe fn alloc_prof_prev_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    // Safety: `ZEND_MM_STATE.prev_custom_mm_realloc` will be initialised in
    // `alloc_prof_rinit()` and only point to this function when
    // `prev_custom_mm_realloc` is also initialised
    // SAFETY: realloc hook runs on a PHP thread within GINIT–GSHUTDOWN.
    let php_thread = crate::OnPhpThread::new();
    let realloc = tls_zend_mm_state_get_le83!(php_thread, prev_custom_mm_realloc).unwrap();
    realloc(prev_ptr, len)
}

unsafe fn alloc_prof_orig_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    // Safety: `ZEND_MM_STATE.heap` will be initialised in `alloc_prof_rinit()` and custom ZendMM
    // handlers are only installed and pointing to this function if initialization was succesful.
    // SAFETY: realloc hook runs on a PHP thread within GINIT–GSHUTDOWN.
    let php_thread = crate::OnPhpThread::new();
    let heap = tls_zend_mm_state_get_le83!(php_thread, heap).unwrap_unchecked();
    let (prepare, restore) = tls_zend_mm_state_get_le83!(php_thread, prepare_restore_zend_heap);
    let custom_heap = prepare(heap);
    let ptr: *mut c_void = zend::_zend_mm_realloc(heap, prev_ptr, len);
    restore(heap, custom_heap);
    ptr
}

/// safe wrapper for `zend::is_zend_mm()`.
/// `true` means the internal ZendMM is being used, `false` means that a custom memory manager is
/// installed. Upstream returns a `c_bool` as of PHP 8.0. PHP 7 returns a `c_int`
fn is_zend_mm() -> bool {
    unsafe { zend::is_zend_mm() != 0 }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn check_versions_that_allocation_profiling_needs_disabled_with_active_jit() {
        // versions that need disabled allocation profiling with active jit
        assert!(alloc_prof_needs_disabled_for_jit(80000));
        assert!(alloc_prof_needs_disabled_for_jit(80100));
        assert!(alloc_prof_needs_disabled_for_jit(80120));
        assert!(alloc_prof_needs_disabled_for_jit(80200));
        assert!(alloc_prof_needs_disabled_for_jit(80207));

        // versions that DO NOT need disabled allocation profiling with active jit
        assert!(!alloc_prof_needs_disabled_for_jit(70421));
        assert!(!alloc_prof_needs_disabled_for_jit(80121));
        assert!(!alloc_prof_needs_disabled_for_jit(80122));
        assert!(!alloc_prof_needs_disabled_for_jit(80208));
        assert!(!alloc_prof_needs_disabled_for_jit(80209));
        assert!(!alloc_prof_needs_disabled_for_jit(80300));
    }
}
