use crate::allocation::{
    allocation_profiling_stats_should_collect, collect_allocation, untrack_allocation,
};
use crate::bindings as zend;
use crate::profiling::Profiler;
use core::ptr;
use libc::{c_int, c_void, size_t};
use log::{debug, trace};
use std::sync::atomic::Ordering::Relaxed;
use std::sync::LazyLock;

#[cfg(php_debug)]
use libc::{c_char, c_uint};

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
    /// A secondary ZendMM heap containing heap-live sample candidates.
    tracked_heap: Option<*mut zend::zend_mm_heap>,
    uses_zend_mm: bool,
    allocation_enabled: bool,
    heap_live_enabled: bool,
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
            alloc: super::alloc_prof_panic_alloc,
            realloc: super::alloc_prof_panic_realloc,
            free: super::alloc_prof_panic_free,
            gc: alloc_prof_panic_gc,
            shutdown: alloc_prof_panic_shutdown,
            tracked_heap: None,
            uses_zend_mm: false,
            allocation_enabled: false,
            heap_live_enabled: false,
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

pub fn alloc_prof_rinit(allocation_enabled: bool, heap_live_enabled: bool) {
    let mut state = tls_zend_mm_state_copy!();

    if allocation_enabled && state.heap.is_none() {
        // Safety: `zend_mm_get_heap()` returns the current thread's heap.
        let heap = unsafe { zend::zend_mm_get_heap() };
        state.heap = Some(heap);
        state.uses_zend_mm = unsafe { zend::is_zend_mm() };

        if state.uses_zend_mm {
            state.alloc = alloc_prof_orig_alloc;
            state.free = alloc_prof_orig_free;
            state.realloc = alloc_prof_orig_realloc;
            state.gc = alloc_prof_orig_gc;
            state.shutdown = alloc_prof_orig_shutdown;
        } else {
            debug!("Found another extension using the ZendMM custom handler hook");
            unsafe {
                zend::zend_mm_get_custom_handlers_ex(
                    heap,
                    ptr::addr_of_mut!(state.prev_custom_mm_alloc),
                    ptr::addr_of_mut!(state.prev_custom_mm_free),
                    ptr::addr_of_mut!(state.prev_custom_mm_realloc),
                    ptr::addr_of_mut!(state.prev_custom_mm_gc),
                    ptr::addr_of_mut!(state.prev_custom_mm_shutdown),
                );
            }
            state.alloc = alloc_prof_prev_alloc;
            state.free = alloc_prof_prev_free;
            state.realloc = alloc_prof_prev_realloc;
            state.gc = alloc_prof_prev_gc;
            state.shutdown = alloc_prof_prev_shutdown;
        }

        unsafe {
            zend::zend_mm_set_custom_handlers_ex(
                heap,
                Some(alloc_prof_malloc),
                Some(alloc_prof_free),
                Some(alloc_prof_realloc),
                Some(alloc_prof_gc),
                Some(alloc_prof_shutdown),
            );
        }
        trace!("Memory allocation profiling enabled.");
    }

    state.allocation_enabled = allocation_enabled;
    state.heap_live_enabled = heap_live_enabled;
    tls_zend_mm_state_set!(state);
}

pub fn alloc_prof_rshutdown() {
    let mut state = tls_zend_mm_state_copy!();
    state.allocation_enabled = false;
    state.heap_live_enabled = false;
    tls_zend_mm_state_set!(state);
}

pub unsafe fn alloc_prof_gshutdown() {
    let mut state = tls_zend_mm_state_copy!();
    if let Some(tracked_heap) = state.tracked_heap.take() {
        discard_tracked_heap_samples(tracked_heap);
        zend::ddog_php_prof_zend_mm_tracked_heap_shutdown(tracked_heap);
    }
    if let Some(heap) = state.heap.take() {
        zend::zend_mm_set_custom_handlers_ex(
            heap,
            state.prev_custom_mm_alloc,
            state.prev_custom_mm_free,
            state.prev_custom_mm_realloc,
            state.prev_custom_mm_gc,
            state.prev_custom_mm_shutdown,
        );
    }
    tls_zend_mm_state_set!(state);
}

pub fn post_fork_child() {
    if let Some(tracked_heap) = tls_zend_mm_state_get!(tracked_heap) {
        unsafe { zend::ddog_php_prof_zend_mm_tracked_heap_refresh_after_fork(tracked_heap) };
    }
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

unsafe fn ensure_tracked_heap() -> Option<*mut zend::zend_mm_heap> {
    let mut state = tls_zend_mm_state_copy!();
    if !state.uses_zend_mm
        || !state.heap_live_enabled
        || !Profiler::get().is_some_and(Profiler::live_heap_tracker_has_capacity)
    {
        return None;
    }
    if state.tracked_heap.is_none() {
        let heap = zend::ddog_php_prof_zend_mm_tracked_heap_startup(state.heap?);
        state.tracked_heap = (!heap.is_null()).then_some(heap);
        tls_zend_mm_state_set!(state);
    }
    state.tracked_heap
}

unsafe fn is_tracked_heap_ptr(ptr: *mut c_void) -> bool {
    tls_zend_mm_state_get!(tracked_heap)
        .is_some_and(|heap| zend::ddog_php_prof_zend_mm_heap_contains(heap, ptr))
}

unsafe fn discard_tracked_heap_samples(heap: *mut zend::zend_mm_heap) {
    if let Some(profiler) = Profiler::get() {
        for ptr in profiler.live_heap_allocation_pointers() {
            if zend::ddog_php_prof_zend_mm_heap_contains_slow(heap, ptr as *const c_void) {
                profiler.untrack_allocation(ptr);
            }
        }
    }
}

unsafe fn heap_alloc(heap: *mut zend::zend_mm_heap, len: size_t) -> *mut c_void {
    #[cfg(php_debug)]
    return zend::_zend_mm_alloc(heap, len, ptr::null(), 0, ptr::null(), 0);
    #[cfg(not(php_debug))]
    zend::_zend_mm_alloc(heap, len)
}

unsafe fn heap_free(heap: *mut zend::zend_mm_heap, ptr: *mut c_void) {
    #[cfg(php_debug)]
    return zend::_zend_mm_free(heap, ptr, core::ptr::null(), 0, core::ptr::null(), 0);
    #[cfg(not(php_debug))]
    zend::_zend_mm_free(heap, ptr)
}

unsafe fn heap_realloc(
    heap: *mut zend::zend_mm_heap,
    ptr: *mut c_void,
    len: size_t,
) -> *mut c_void {
    #[cfg(php_debug)]
    return zend::_zend_mm_realloc(heap, ptr, len, ptr::null(), 0, ptr::null(), 0);
    #[cfg(not(php_debug))]
    zend::_zend_mm_realloc(heap, ptr, len)
}

#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_malloc(len: size_t) -> *mut c_void {
    alloc_prof_malloc_impl(len)
}

#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_malloc(
    len: size_t,
    _file: *const c_char,
    _line: c_uint,
    _orig_file: *const c_char,
    _orig_line: c_uint,
) -> *mut c_void {
    alloc_prof_malloc_impl(len)
}

#[inline(always)]
unsafe fn alloc_prof_malloc_impl(len: size_t) -> *mut c_void {
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_COUNT.fetch_add(1, Relaxed);
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, Relaxed);

    let should_collect = tls_zend_mm_state_get!(allocation_enabled)
        && !zend::ddog_php_prof_get_current_execute_data().is_null()
        && allocation_profiling_stats_should_collect(len);

    let ptr = if should_collect {
        match ensure_tracked_heap() {
            Some(heap) => heap_alloc(heap, len),
            None => tls_zend_mm_state_get!(alloc)(len),
        }
    } else {
        tls_zend_mm_state_get!(alloc)(len)
    };

    if should_collect {
        collect_allocation(ptr, len);
    }

    ptr
}

unsafe fn alloc_prof_prev_alloc(len: size_t) -> *mut c_void {
    // Safety: `ZEND_MM_STATE.prev_custom_mm_alloc` will be initialised in
    // `alloc_prof_rinit()` and only point to this function when
    // `prev_custom_mm_alloc` is also initialised.
    // Note: We use `.unwrap()` instead of `.unwrap_unchecked()` here because a
    // neighboring extension could misbehave. If that happens, we want a proper
    // panic with backtrace for debugging rather than undefined behavior.
    let alloc = tls_zend_mm_state_get!(prev_custom_mm_alloc).unwrap();
    #[cfg(php_debug)]
    {
        alloc(len, ptr::null(), 0, ptr::null(), 0)
    }
    #[cfg(not(php_debug))]
    alloc(len)
}

unsafe fn alloc_prof_orig_alloc(len: size_t) -> *mut c_void {
    // Safety: `ZEND_MM_STATE.heap` will be initialised in `alloc_prof_rinit()` and custom ZendMM
    // handlers only point to this function after successful init. Using `unwrap_unchecked()` is
    // safe here as we have full control over ZendMM with no neighboring extensions.
    let heap = tls_zend_mm_state_get!(heap).unwrap_unchecked();
    #[cfg(php_debug)]
    return zend::_zend_mm_alloc(heap, len, ptr::null(), 0, ptr::null(), 0);
    #[cfg(not(php_debug))]
    zend::_zend_mm_alloc(heap, len)
}

/// This function exists because when calling `zend_mm_set_custom_handlers()`,
/// you need to pass a pointer to a `free()` function as well, otherwise your
/// custom handlers won't be installed. We cannot just point to the original
/// `zend::_zend_mm_free()` as the function definitions differ.
#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_free(ptr: *mut c_void) {
    alloc_prof_free_impl(ptr);
}

#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_free(
    ptr: *mut c_void,
    _file: *const c_char,
    _line: c_uint,
    _orig_file: *const c_char,
    _orig_line: c_uint,
) {
    alloc_prof_free_impl(ptr);
}

#[inline(always)]
unsafe fn alloc_prof_free_impl(ptr: *mut c_void) {
    if ptr.is_null() {
        return tls_zend_mm_state_get!(free)(ptr);
    }

    let state = tls_zend_mm_state_copy!();
    if state.uses_zend_mm && state.heap.is_some_and(|heap| heap.cast() == ptr) {
        // Full ZendMM shutdown passes the primary heap to the custom free hook.
        return;
    }

    if is_tracked_heap_ptr(ptr) {
        untrack_allocation(ptr);
        return heap_free(state.tracked_heap.unwrap_unchecked(), ptr);
    }

    if !state.uses_zend_mm && state.heap_live_enabled {
        untrack_allocation(ptr);
    }
    (state.free)(ptr);
}

unsafe fn alloc_prof_prev_free(ptr: *mut c_void) {
    // Safety: `ZEND_MM_STATE.prev_custom_mm_free` will be initialised in
    // `alloc_prof_rinit()` and only point to this function when
    // `prev_custom_mm_free` is also initialised.
    // Note: We use `.unwrap()` instead of `.unwrap_unchecked()` here because a
    // neighboring extension could misbehave. If that happens, we want a proper
    // panic with backtrace for debugging rather than undefined behavior.
    let free = tls_zend_mm_state_get!(prev_custom_mm_free).unwrap();
    #[cfg(php_debug)]
    {
        free(ptr, core::ptr::null(), 0, core::ptr::null(), 0)
    }
    #[cfg(not(php_debug))]
    free(ptr)
}

unsafe fn alloc_prof_orig_free(ptr: *mut c_void) {
    // Safety: `ZEND_MM_STATE.heap` will be initialised in `alloc_prof_rinit()` and custom ZendMM
    // handlers only point to this function after successful init. Using `unwrap_unchecked()` is
    // safe here as we have full control over ZendMM with no neighboring extensions.
    let heap = tls_zend_mm_state_get!(heap).unwrap_unchecked();
    #[cfg(php_debug)]
    return zend::_zend_mm_free(heap, ptr, core::ptr::null(), 0, core::ptr::null(), 0);
    #[cfg(not(php_debug))]
    zend::_zend_mm_free(heap, ptr);
}

#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    alloc_prof_realloc_impl(prev_ptr, len)
}

#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_realloc(
    prev_ptr: *mut c_void,
    len: size_t,
    _file: *const c_char,
    _line: c_uint,
    _orig_file: *const c_char,
    _orig_line: c_uint,
) -> *mut c_void {
    alloc_prof_realloc_impl(prev_ptr, len)
}

#[inline(always)]
unsafe fn alloc_prof_realloc_impl(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_COUNT.fetch_add(1, Relaxed);
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, Relaxed);

    let should_collect = tls_zend_mm_state_get!(allocation_enabled)
        && !zend::ddog_php_prof_get_current_execute_data().is_null()
        && allocation_profiling_stats_should_collect(len);
    let previous_was_tracked = !prev_ptr.is_null() && is_tracked_heap_ptr(prev_ptr);

    let ptr = if previous_was_tracked {
        heap_realloc(
            tls_zend_mm_state_get!(tracked_heap).unwrap_unchecked(),
            prev_ptr,
            len,
        )
    } else if should_collect {
        match ensure_tracked_heap() {
            Some(heap) if prev_ptr.is_null() => heap_alloc(heap, len),
            Some(heap) => zend::ddog_php_prof_zend_mm_move(
                tls_zend_mm_state_get!(heap).unwrap_unchecked(),
                heap,
                prev_ptr,
                len,
            ),
            None => tls_zend_mm_state_get!(realloc)(prev_ptr, len),
        }
    } else {
        tls_zend_mm_state_get!(realloc)(prev_ptr, len)
    };

    if previous_was_tracked {
        untrack_allocation(prev_ptr);
    }
    if should_collect {
        collect_allocation(ptr, len);
    }
    ptr
}

unsafe fn alloc_prof_prev_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    // Safety: `ZEND_MM_STATE.prev_custom_mm_realloc` will be initialised in
    // `alloc_prof_rinit()` and only point to this function when
    // `prev_custom_mm_realloc` is also initialised.
    // Note: We use `.unwrap()` instead of `.unwrap_unchecked()` here because a
    // neighboring extension could misbehave. If that happens, we want a proper
    // panic with backtrace for debugging rather than undefined behavior.
    let realloc = tls_zend_mm_state_get!(prev_custom_mm_realloc).unwrap();
    #[cfg(php_debug)]
    {
        realloc(prev_ptr, len, ptr::null(), 0, ptr::null(), 0)
    }
    #[cfg(not(php_debug))]
    realloc(prev_ptr, len)
}

unsafe fn alloc_prof_orig_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    // Safety: `ZEND_MM_STATE.heap` will be initialised in `alloc_prof_rinit()` and custom ZendMM
    // handlers only point to this function after successful init. Using `unwrap_unchecked()` is
    // safe here as we have full control over ZendMM with no neighboring extensions.
    let heap = tls_zend_mm_state_get!(heap).unwrap_unchecked();
    #[cfg(php_debug)]
    return zend::_zend_mm_realloc(heap, prev_ptr, len, ptr::null(), 0, ptr::null(), 0);
    #[cfg(not(php_debug))]
    zend::_zend_mm_realloc(heap, prev_ptr, len)
}

unsafe extern "C" fn alloc_prof_gc() -> size_t {
    let mut size = tls_zend_mm_state_get!(gc)();
    if let Some(heap) = tls_zend_mm_state_get!(tracked_heap) {
        size += zend::zend_mm_gc(heap);
    }
    size
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
    let mut state = tls_zend_mm_state_copy!();
    if let Some(tracked_heap) = state.tracked_heap.take() {
        discard_tracked_heap_samples(tracked_heap);
        zend::ddog_php_prof_zend_mm_tracked_heap_shutdown(tracked_heap);
    }
    tls_zend_mm_state_set!(state);

    (state.shutdown)(full, silent);

    if full {
        state.heap = None;
        tls_zend_mm_state_set!(state);
    }
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
    if !full {
        restore_zend_heap(heap, custom_heap);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

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
