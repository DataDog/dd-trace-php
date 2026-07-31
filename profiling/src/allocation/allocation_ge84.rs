use crate::allocation::{collect_allocation, untrack_allocation};
use crate::bindings as zend;
use crate::module_globals::{self, ProfilerGlobals};
use crate::PROFILER_NAME;
use core::ptr;
use libc::{c_char, c_int, c_void, size_t};
use log::{debug, trace, warn};
use std::sync::atomic::Ordering::Relaxed;
use std::sync::LazyLock;

#[cfg(php_zts)]
use crate::allocation::current_execute_data_from_cache;

#[cfg(php_debug)]
use libc::c_uint;

#[cfg(feature = "debug_stats")]
use crate::allocation::{ALLOCATION_PROFILING_COUNT, ALLOCATION_PROFILING_SIZE};

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
    /// The engine's previous custom gc function, if there is one.
    prev_custom_mm_gc: Option<zend::VmMmCustomGcFn>,
    /// The engine's previous custom shutdown function, if there is one.
    prev_custom_mm_shutdown: Option<zend::VmMmCustomShutdownFn>,
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

unsafe fn alloc_prof_panic_gc() -> size_t {
    super::initialization_panic();
}

unsafe fn alloc_prof_panic_shutdown(_full: bool, _silent: bool) {
    super::initialization_panic();
}

impl ZendMMState {
    #[allow(clippy::new_without_default)]
    pub const fn new() -> ZendMMState {
        ZendMMState {
            heap: None,
            prev_custom_mm_alloc: None,
            prev_custom_mm_realloc: None,
            prev_custom_mm_free: None,
            prev_custom_mm_gc: None,
            prev_custom_mm_shutdown: None,
            gc: alloc_prof_panic_gc,
            shutdown: alloc_prof_panic_shutdown,
        }
    }
}

const NEEDS_RUN_TIME_CHECK_FOR_ENABLED_JIT: bool =
    zend::PHP_VERSION_ID >= 80400 && zend::PHP_VERSION_ID < 80500;

fn alloc_prof_needs_disabled_for_jit(version: u32) -> bool {
    // see https://github.com/php/php-src/pull/11380
    (80400..80407).contains(&version)
}

static JIT_ENABLED: LazyLock<bool> = LazyLock::new(|| unsafe { zend::ddog_php_jit_enabled() });

pub fn alloc_prof_ginit() {
    unsafe { zend::ddog_php_opcache_init_handle() };
}

pub fn first_rinit_should_disable_due_to_jit() -> bool {
    NEEDS_RUN_TIME_CHECK_FOR_ENABLED_JIT
        && alloc_prof_needs_disabled_for_jit(crate::RUNTIME_PHP_VERSION_ID.load(Relaxed))
        && *JIT_ENABLED
}

pub fn alloc_prof_rinit(heap_live_enabled: bool) {
    let zend_mm_state_init = |mut zend_mm_state: ZendMMState| -> ZendMMState {
        // Safety: `zend_mm_get_heap()` always returns a non-null pointer to a valid heap structure
        let heap = unsafe { zend::zend_mm_get_heap() };

        zend_mm_state.heap = Some(heap);

        if unsafe { !zend::is_zend_mm() } {
            // Neighboring custom memory handlers found
            debug!("Found another extension using the ZendMM custom handler hook");
            unsafe {
                zend::zend_mm_get_custom_handlers_ex(
                    heap,
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_alloc),
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_free),
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_realloc),
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_gc),
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_shutdown),
                );
            }
            zend_mm_state.gc = alloc_prof_prev_gc;
            zend_mm_state.shutdown = alloc_prof_prev_shutdown;
        } else {
            zend_mm_state.gc = alloc_prof_orig_gc;
            zend_mm_state.shutdown = alloc_prof_orig_shutdown;

            // Reset previous handlers to None. There might be a chaotic neighbor that
            // registered custom handlers in an earlier request, but it doesn't do so for this
            // request. In that case we would restore the neighbouring extensions custom
            // handlers to the ZendMM in RSHUTDOWN which would lead to a crash!
            zend_mm_state.prev_custom_mm_alloc = None;
            zend_mm_state.prev_custom_mm_free = None;
            zend_mm_state.prev_custom_mm_realloc = None;
            zend_mm_state.prev_custom_mm_gc = None;
            zend_mm_state.prev_custom_mm_shutdown = None;
        }

        let malloc_handler =
            alloc_prof_malloc_handler(zend_mm_state.prev_custom_mm_alloc.is_some());
        let free_handler = alloc_prof_free_handler(
            heap_live_enabled,
            zend_mm_state.prev_custom_mm_free.is_some(),
        );
        let realloc_handler = alloc_prof_realloc_handler(
            heap_live_enabled,
            zend_mm_state.prev_custom_mm_realloc.is_some(),
        );

        // install our custom handler to ZendMM
        unsafe {
            zend::zend_mm_set_custom_handlers_ex(
                heap,
                Some(malloc_handler),
                Some(free_handler),
                Some(realloc_handler),
                Some(alloc_prof_gc),
                Some(alloc_prof_shutdown),
            );
        }
        zend_mm_state
    };

    let mm_state = tls_zend_mm_state_copy!();
    tls_zend_mm_state_set!(zend_mm_state_init(mm_state));

    // `is_zend_mm()` should be false now, as we installed our custom handlers
    if unsafe { zend::is_zend_mm() } {
        // Can't proceed with it being disabled, because that's a system-wide
        // setting, not per-request.
        panic!("Memory allocation profiling could not be enabled. Please feel free to fill an issue stating the PHP version and installed modules. Most likely the reason is your PHP binary was compiled with `ZEND_MM_CUSTOM` being disabled.");
    }
    trace!("Memory allocation profiling enabled.")
}

#[allow(unknown_lints, unpredictable_function_pointer_comparisons)]
pub fn alloc_prof_rshutdown(heap_live_enabled: bool) {
    // If `is_zend_mm()` is true, the custom handlers have already been reset
    // to `None`. This is unexpected, therefore we will not touch the ZendMM
    // handlers anymore as resetting to prev handlers might result in segfaults
    // and other undefined behavior.
    if unsafe { zend::is_zend_mm() } {
        return;
    }

    let zend_mm_state_shutdown = |mut zend_mm_state: ZendMMState| -> ZendMMState {
        let mut custom_mm_malloc: Option<zend::VmMmCustomAllocFn> = None;
        let mut custom_mm_free: Option<zend::VmMmCustomFreeFn> = None;
        let mut custom_mm_realloc: Option<zend::VmMmCustomReallocFn> = None;
        let mut custom_mm_gc: Option<zend::VmMmCustomGcFn> = None;
        let mut custom_mm_shutdown: Option<zend::VmMmCustomShutdownFn> = None;

        // SAFETY: UnsafeCell::get() ensures non-null, and the object should
        // be valid for reads during rshutdown.
        let Some(heap) = zend_mm_state.heap else {
            // The heap can be None if a fork happens outside the request.
            return zend_mm_state;
        };

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
        let malloc_handler =
            alloc_prof_malloc_handler(zend_mm_state.prev_custom_mm_alloc.is_some());
        let free_handler = alloc_prof_free_handler(
            heap_live_enabled,
            zend_mm_state.prev_custom_mm_free.is_some(),
        );
        let realloc_handler = alloc_prof_realloc_handler(
            heap_live_enabled,
            zend_mm_state.prev_custom_mm_realloc.is_some(),
        );
        if custom_mm_free != Some(free_handler)
            || custom_mm_malloc != Some(malloc_handler)
            || custom_mm_realloc != Some(realloc_handler)
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
            // This is the happy path. Restore previously installed custom handlers or
            // NULL-pointers to the ZendMM. In case all pointers are NULL, the ZendMM will reset
            // the `use_custom_heap` flag to `None`, in case we restore a neighbouring extension
            // custom handlers, ZendMM will call those for future allocations. In either way, we
            // have unregistered and we'll not receive any allocation calls anymore.
            unsafe {
                zend::zend_mm_set_custom_handlers_ex(
                    heap,
                    zend_mm_state.prev_custom_mm_alloc,
                    zend_mm_state.prev_custom_mm_free,
                    zend_mm_state.prev_custom_mm_realloc,
                    zend_mm_state.prev_custom_mm_gc,
                    zend_mm_state.prev_custom_mm_shutdown,
                );
            }
            trace!("Memory allocation profiling shutdown gracefully.");
        }
        zend_mm_state.heap = None;
        zend_mm_state
    };

    let mm_state = tls_zend_mm_state_copy!();
    tls_zend_mm_state_set!(zend_mm_state_shutdown(mm_state));
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

/// Restore the ZendMM heap's `use_custom_heap` flag, see `prepare_zend_heap` for details
unsafe fn restore_zend_heap(heap: *mut zend::_zend_mm_heap, custom_heap: c_int) {
    ptr::write(heap as *mut c_int, custom_heap);
}

fn alloc_prof_malloc_handler(has_previous_allocator: bool) -> zend::VmMmCustomAllocFn {
    if has_previous_allocator {
        alloc_prof_malloc_custom
    } else {
        alloc_prof_malloc
    }
}

#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_malloc(len: size_t) -> *mut c_void {
    alloc_prof_malloc_impl::<false>(len)
}

#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_malloc(
    len: size_t,
    _file: *const c_char,
    _line: c_uint,
    _orig_file: *const c_char,
    _orig_line: c_uint,
) -> *mut c_void {
    alloc_prof_malloc_impl::<false>(len)
}

// Compatibility path for another extension's previously installed custom allocator.
#[cold]
#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_malloc_custom(len: size_t) -> *mut c_void {
    alloc_prof_malloc_impl::<true>(len)
}

#[cold]
#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_malloc_custom(
    len: size_t,
    _file: *const c_char,
    _line: c_uint,
    _orig_file: *const c_char,
    _orig_line: c_uint,
) -> *mut c_void {
    alloc_prof_malloc_impl::<true>(len)
}

#[inline(always)]
unsafe fn alloc_prof_malloc_impl<const CUSTOM: bool>(len: size_t) -> *mut c_void {
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_COUNT.fetch_add(1, Relaxed);
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, Relaxed);

    #[cfg(php_zts)]
    let ls_cache = module_globals::get_tsrm_ls_cache();
    #[cfg(php_zts)]
    let globals = module_globals::get_profiler_globals_from_cache(ls_cache);
    #[cfg(not(php_zts))]
    let globals = module_globals::get_profiler_globals();
    let state = (*globals).zend_mm_state.get();

    let ptr = if CUSTOM {
        let alloc = state.prev_custom_mm_alloc.unwrap();
        #[cfg(php_debug)]
        let ptr = alloc(len, ptr::null(), 0, ptr::null(), 0);
        #[cfg(not(php_debug))]
        let ptr = alloc(len);
        ptr
    } else {
        // SAFETY: this callback is only invoked after rinit stores the heap and
        // before rshutdown clears it.
        let heap = state.heap.unwrap_unchecked();
        #[cfg(php_debug)]
        let ptr = zend::_zend_mm_alloc(heap, len, ptr::null(), 0, ptr::null(), 0);
        #[cfg(not(php_debug))]
        let ptr = zend::_zend_mm_alloc(heap, len);
        ptr
    };

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    #[cfg(php_zts)]
    let execute_data = current_execute_data_from_cache(ls_cache);
    #[cfg(not(php_zts))]
    let execute_data = ptr::addr_of!(zend::executor_globals.current_execute_data).read();
    if execute_data.is_null() {
        return ptr;
    }

    if ProfilerGlobals::should_collect(globals, len) {
        collect_allocation(
            unsafe { &(*globals).interrupt_count },
            execute_data,
            ptr,
            len,
        );
    }

    ptr
}

/// This function exists because when calling `zend_mm_set_custom_handlers()`,
/// you need to pass a pointer to a `free()` function as well, otherwise your
/// custom handlers won't be installed. We cannot just point to the original
/// `zend::_zend_mm_free()` as the function definitions differ.
#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_free<const TRACK: bool>(ptr: *mut c_void) {
    alloc_prof_free_impl::<TRACK, false>(ptr);
}

#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_free<const TRACK: bool>(
    ptr: *mut c_void,
    _file: *const c_char,
    _line: c_uint,
    _orig_file: *const c_char,
    _orig_line: c_uint,
) {
    alloc_prof_free_impl::<TRACK, false>(ptr);
}

// Compatibility path for another extension's previously installed custom allocator.
#[cold]
#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_free_custom<const TRACK: bool>(ptr: *mut c_void) {
    alloc_prof_free_impl::<TRACK, true>(ptr);
}

#[cold]
#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_free_custom<const TRACK: bool>(
    ptr: *mut c_void,
    _file: *const c_char,
    _line: c_uint,
    _orig_file: *const c_char,
    _orig_line: c_uint,
) {
    alloc_prof_free_impl::<TRACK, true>(ptr);
}

fn alloc_prof_free_handler(
    heap_live_enabled: bool,
    has_previous_allocator: bool,
) -> zend::VmMmCustomFreeFn {
    match (heap_live_enabled, has_previous_allocator) {
        (true, false) => alloc_prof_free::<true>,
        (false, false) => alloc_prof_free::<false>,
        (true, true) => alloc_prof_free_custom::<true>,
        (false, true) => alloc_prof_free_custom::<false>,
    }
}

#[inline(always)]
unsafe fn alloc_prof_free_impl<const TRACK: bool, const CUSTOM: bool>(ptr: *mut c_void) {
    if TRACK && !ptr.is_null() {
        untrack_allocation(ptr);
    }

    let state = tls_zend_mm_state_copy!();
    if CUSTOM {
        let free = state.prev_custom_mm_free.unwrap();
        #[cfg(php_debug)]
        free(ptr, core::ptr::null(), 0, core::ptr::null(), 0);
        #[cfg(not(php_debug))]
        free(ptr);
    } else {
        // SAFETY: this callback is only invoked after rinit stores the heap and
        // before rshutdown clears it.
        let heap = state.heap.unwrap_unchecked();
        #[cfg(php_debug)]
        zend::_zend_mm_free(heap, ptr, core::ptr::null(), 0, core::ptr::null(), 0);
        #[cfg(not(php_debug))]
        zend::_zend_mm_free(heap, ptr);
    }
}

fn alloc_prof_realloc_handler(
    heap_live_enabled: bool,
    has_previous_allocator: bool,
) -> zend::VmMmCustomReallocFn {
    match (heap_live_enabled, has_previous_allocator) {
        (true, false) => alloc_prof_realloc::<true>,
        (false, false) => alloc_prof_realloc::<false>,
        (true, true) => alloc_prof_realloc_custom::<true>,
        (false, true) => alloc_prof_realloc_custom::<false>,
    }
}

#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_realloc<const UNTRACK: bool>(
    prev_ptr: *mut c_void,
    len: size_t,
) -> *mut c_void {
    alloc_prof_realloc_impl::<UNTRACK, false>(prev_ptr, len)
}

#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_realloc<const UNTRACK: bool>(
    prev_ptr: *mut c_void,
    len: size_t,
    _file: *const c_char,
    _line: c_uint,
    _orig_file: *const c_char,
    _orig_line: c_uint,
) -> *mut c_void {
    alloc_prof_realloc_impl::<UNTRACK, false>(prev_ptr, len)
}

// Compatibility path for another extension's previously installed custom allocator.
#[cold]
#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_realloc_custom<const UNTRACK: bool>(
    prev_ptr: *mut c_void,
    len: size_t,
) -> *mut c_void {
    alloc_prof_realloc_impl::<UNTRACK, true>(prev_ptr, len)
}

#[cold]
#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_realloc_custom<const UNTRACK: bool>(
    prev_ptr: *mut c_void,
    len: size_t,
    _file: *const c_char,
    _line: c_uint,
    _orig_file: *const c_char,
    _orig_line: c_uint,
) -> *mut c_void {
    alloc_prof_realloc_impl::<UNTRACK, true>(prev_ptr, len)
}

#[inline(always)]
unsafe fn alloc_prof_realloc_impl<const UNTRACK: bool, const CUSTOM: bool>(
    prev_ptr: *mut c_void,
    len: size_t,
) -> *mut c_void {
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_COUNT.fetch_add(1, Relaxed);
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, Relaxed);

    #[cfg(php_zts)]
    let ls_cache = module_globals::get_tsrm_ls_cache();
    #[cfg(php_zts)]
    let globals = module_globals::get_profiler_globals_from_cache(ls_cache);
    #[cfg(not(php_zts))]
    let globals = module_globals::get_profiler_globals();
    let state = (*globals).zend_mm_state.get();

    let ptr = if CUSTOM {
        let realloc = state.prev_custom_mm_realloc.unwrap();
        #[cfg(php_debug)]
        let ptr = realloc(prev_ptr, len, ptr::null(), 0, ptr::null(), 0);
        #[cfg(not(php_debug))]
        let ptr = realloc(prev_ptr, len);
        ptr
    } else {
        // SAFETY: this callback is only invoked after rinit stores the heap and
        // before rshutdown clears it.
        let heap = state.heap.unwrap_unchecked();
        #[cfg(php_debug)]
        let ptr = zend::_zend_mm_realloc(heap, prev_ptr, len, ptr::null(), 0, ptr::null(), 0);
        #[cfg(not(php_debug))]
        let ptr = zend::_zend_mm_realloc(heap, prev_ptr, len);
        ptr
    };

    // ZendMM allocation failures raise a fatal error and bail out instead of
    // returning NULL. If realloc returns, prev_ptr has been consumed: untrack it
    // before any userland-only early return, then let the new allocation be
    // re-sampled at the reported size.
    if UNTRACK && !prev_ptr.is_null() {
        untrack_allocation(prev_ptr);
    }

    #[cfg(php_zts)]
    let execute_data = current_execute_data_from_cache(ls_cache);
    #[cfg(not(php_zts))]
    let execute_data = ptr::addr_of!(zend::executor_globals.current_execute_data).read();

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if execute_data.is_null() || ptr.is_null() {
        return ptr;
    }

    if ProfilerGlobals::should_collect(globals, len) {
        collect_allocation(
            unsafe { &(*globals).interrupt_count },
            execute_data,
            ptr,
            len,
        );
    }

    ptr
}

unsafe extern "C" fn alloc_prof_gc() -> size_t {
    tls_zend_mm_state_get!(gc)()
}

unsafe fn alloc_prof_prev_gc() -> size_t {
    match tls_zend_mm_state_get!(prev_custom_mm_gc) {
        Some(gc) => gc(),
        None => 0,
    }
}

unsafe fn alloc_prof_orig_gc() -> size_t {
    // Safety: `ZEND_MM_STATE.heap` will be initialised in `alloc_prof_rinit()` and custom ZendMM
    // handlers only point to this function after successful init. Using `unwrap_unchecked()` is
    // safe here as we have full control over ZendMM with no neighboring extensions.
    let heap = tls_zend_mm_state_get!(heap).unwrap_unchecked();
    let custom_heap = prepare_zend_heap(heap);
    let size = zend::zend_mm_gc(heap);
    restore_zend_heap(heap, custom_heap);
    size
}

unsafe extern "C" fn alloc_prof_shutdown(full: bool, silent: bool) {
    tls_zend_mm_state_get!(shutdown)(full, silent)
}

unsafe fn alloc_prof_prev_shutdown(full: bool, silent: bool) {
    if let Some(shutdown) = tls_zend_mm_state_get!(prev_custom_mm_shutdown) {
        shutdown(full, silent)
    }
}

unsafe fn alloc_prof_orig_shutdown(full: bool, silent: bool) {
    // Safety: `ZEND_MM_STATE.heap` will be initialised in `alloc_prof_rinit()` and custom ZendMM
    // handlers only point to this function after successful init. Using `unwrap_unchecked()` is
    // safe here as we have full control over ZendMM with no neighboring extensions.
    let heap = tls_zend_mm_state_get!(heap).unwrap_unchecked();
    let custom_heap = prepare_zend_heap(heap);
    zend::zend_mm_shutdown(heap, full, silent);
    restore_zend_heap(heap, custom_heap);
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn handlers_are_selected_at_rinit() {
        assert_eq!(
            alloc_prof_free_handler(true, false) as usize,
            alloc_prof_free::<true> as zend::VmMmCustomFreeFn as usize
        );
        assert_eq!(
            alloc_prof_free_handler(false, false) as usize,
            alloc_prof_free::<false> as zend::VmMmCustomFreeFn as usize
        );
        assert_eq!(
            alloc_prof_free_handler(true, true) as usize,
            alloc_prof_free_custom::<true> as zend::VmMmCustomFreeFn as usize
        );
        assert_eq!(
            alloc_prof_free_handler(false, true) as usize,
            alloc_prof_free_custom::<false> as zend::VmMmCustomFreeFn as usize
        );

        assert_eq!(
            alloc_prof_realloc_handler(true, false) as usize,
            alloc_prof_realloc::<true> as zend::VmMmCustomReallocFn as usize
        );
        assert_eq!(
            alloc_prof_realloc_handler(false, false) as usize,
            alloc_prof_realloc::<false> as zend::VmMmCustomReallocFn as usize
        );
        assert_eq!(
            alloc_prof_realloc_handler(true, true) as usize,
            alloc_prof_realloc_custom::<true> as zend::VmMmCustomReallocFn as usize
        );
        assert_eq!(
            alloc_prof_realloc_handler(false, true) as usize,
            alloc_prof_realloc_custom::<false> as zend::VmMmCustomReallocFn as usize
        );
    }

    #[test]
    fn check_versions_that_allocation_profiling_needs_disabled_with_active_jit() {
        // versions that need disabled allocation profiling with active jit
        assert!(alloc_prof_needs_disabled_for_jit(80400));
        assert!(alloc_prof_needs_disabled_for_jit(80406));

        // versions that DO NOT need disabled allocation profiling with active jit
        assert!(!alloc_prof_needs_disabled_for_jit(80407));
        assert!(!alloc_prof_needs_disabled_for_jit(80501));
    }
}
