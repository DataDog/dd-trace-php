use crate::allocation::{
    allocation_profiling_stats_should_collect, collect_allocation, untrack_allocation,
};
use crate::bindings as zend;
use core::ptr;
use libc::{c_int, c_void, size_t};
use log::{debug, trace};
use std::sync::atomic::Ordering::Relaxed;
use std::sync::LazyLock;

#[cfg(php_debug)]
use libc::{c_char, c_uint};

#[cfg(feature = "debug_stats")]
use crate::allocation::{ALLOCATION_PROFILING_COUNT, ALLOCATION_PROFILING_SIZE};

// Preserve the alignment guaranteed by the allocator we wrap.
const PREFIX_SIZE: usize = core::mem::align_of::<libc::max_align_t>();
const PREFIX_MAGIC: usize = 0xdd0f_cafe;

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
    /// Whether the original allocator is ZendMM and can provide a clean reset.
    uses_zend_mm: bool,
    /// Whether the startup heap reset has established a clean prefix epoch.
    prefix_allocations: bool,
    /// Request-local gates. Pointer translation remains active outside requests.
    allocation_profiling_enabled: bool,
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
            uses_zend_mm: false,
            prefix_allocations: false,
            allocation_profiling_enabled: false,
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
    alloc_prof_install();
}

pub fn first_rinit_should_disable_due_to_jit() -> bool {
    NEEDS_RUN_TIME_CHECK_FOR_ENABLED_JIT
        && alloc_prof_needs_disabled_for_jit(crate::RUNTIME_PHP_VERSION_ID.load(Relaxed))
        && *JIT_ENABLED
}

fn alloc_prof_install() {
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
            zend_mm_state.alloc = alloc_prof_prev_alloc;
            zend_mm_state.free = alloc_prof_prev_free;
            zend_mm_state.realloc = alloc_prof_prev_realloc;
            zend_mm_state.gc = alloc_prof_prev_gc;
            zend_mm_state.shutdown = alloc_prof_prev_shutdown;
        } else {
            zend_mm_state.uses_zend_mm = true;
            zend_mm_state.alloc = alloc_prof_orig_alloc;
            zend_mm_state.free = alloc_prof_orig_free;
            zend_mm_state.realloc = alloc_prof_orig_realloc;
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

        let free_handler = alloc_prof_free_handler(true);
        let realloc_handler = alloc_prof_realloc_handler(true);

        // install our custom handler to ZendMM
        unsafe {
            zend::zend_mm_set_custom_handlers_ex(
                heap,
                Some(alloc_prof_malloc),
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
    trace!("Memory allocation profiling hooks installed in passthrough mode.")
}

pub fn alloc_prof_rinit(allocation_enabled: bool, heap_live_enabled: bool) {
    let mut state = tls_zend_mm_state_copy!();
    state.allocation_profiling_enabled = allocation_enabled;
    state.heap_live_enabled = heap_live_enabled;
    tls_zend_mm_state_set!(state);
}

pub fn alloc_prof_rshutdown() {
    let mut state = tls_zend_mm_state_copy!();
    state.allocation_profiling_enabled = false;
    state.heap_live_enabled = false;
    tls_zend_mm_state_set!(state);
}

/// Restores the previous handlers before PHP destroys this thread's module
/// globals. Allocator globals are destroyed later and must not call back into
/// profiler globals after their GSHUTDOWN.
pub unsafe fn alloc_prof_gshutdown() {
    let state = tls_zend_mm_state_copy!();
    if let Some(heap) = state.heap {
        zend::zend_mm_set_custom_handlers_ex(
            heap,
            state.prev_custom_mm_alloc,
            state.prev_custom_mm_free,
            state.prev_custom_mm_realloc,
            state.prev_custom_mm_gc,
            state.prev_custom_mm_shutdown,
        );
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

#[inline(always)]
unsafe fn add_prefix(ptr: *mut c_void) -> *mut c_void {
    if ptr.is_null() {
        return ptr;
    }
    ptr.cast::<usize>().write(PREFIX_MAGIC);
    ptr.cast::<u8>().add(PREFIX_SIZE).cast()
}

#[inline(always)]
unsafe fn remove_prefix(ptr: *mut c_void) -> *mut c_void {
    if ptr.is_null() {
        return ptr;
    }
    let base = ptr.cast::<u8>().sub(PREFIX_SIZE).cast::<usize>();
    if base.read() != PREFIX_MAGIC {
        ddog_php_prof_prefix_header_missing();
    }
    base.cast()
}

#[no_mangle]
#[cold]
#[inline(never)]
unsafe extern "C" fn ddog_php_prof_prefix_header_missing() -> ! {
    const MESSAGE: &[u8] = b"datadog prefix PoC: pointer predates clean heap epoch\n";
    libc::write(libc::STDERR_FILENO, MESSAGE.as_ptr().cast(), MESSAGE.len());
    libc::abort();
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

    let prefix_allocations = tls_zend_mm_state_get!(prefix_allocations);
    let alloc_len = if prefix_allocations {
        len.checked_add(PREFIX_SIZE)
            .unwrap_or_else(|| libc::abort())
    } else {
        len
    };
    let ptr = tls_zend_mm_state_get!(alloc)(alloc_len);
    let ptr = if prefix_allocations {
        add_prefix(ptr)
    } else {
        ptr
    };

    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if zend::ddog_php_prof_get_current_execute_data().is_null() {
        return ptr;
    }

    if tls_zend_mm_state_get!(allocation_profiling_enabled)
        && allocation_profiling_stats_should_collect(len)
    {
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

fn alloc_prof_free_handler(heap_live_enabled: bool) -> zend::VmMmCustomFreeFn {
    if heap_live_enabled {
        alloc_prof_free
    } else {
        alloc_prof_free_noop
    }
}

#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_free_noop(ptr: *mut c_void) {
    alloc_prof_free_impl(ptr);
}

#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_free_noop(
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
    // Full custom-heap shutdown passes the heap itself to the free callback.
    if tls_zend_mm_state_get!(heap).is_some_and(|heap| heap.cast() == ptr) {
        return;
    }
    if !ptr.is_null() && tls_zend_mm_state_get!(heap_live_enabled) {
        untrack_allocation(ptr);
    }
    let ptr = if tls_zend_mm_state_get!(prefix_allocations) {
        remove_prefix(ptr)
    } else {
        ptr
    };
    tls_zend_mm_state_get!(free)(ptr);
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

fn alloc_prof_realloc_handler(heap_live_enabled: bool) -> zend::VmMmCustomReallocFn {
    if heap_live_enabled {
        alloc_prof_realloc
    } else {
        alloc_prof_realloc_no_untrack
    }
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

#[cfg(not(php_debug))]
unsafe extern "C" fn alloc_prof_realloc_no_untrack(
    prev_ptr: *mut c_void,
    len: size_t,
) -> *mut c_void {
    alloc_prof_realloc_no_untrack_impl(prev_ptr, len)
}

#[cfg(php_debug)]
unsafe extern "C" fn alloc_prof_realloc_no_untrack(
    prev_ptr: *mut c_void,
    len: size_t,
    _file: *const c_char,
    _line: c_uint,
    _orig_file: *const c_char,
    _orig_line: c_uint,
) -> *mut c_void {
    alloc_prof_realloc_no_untrack_impl(prev_ptr, len)
}

#[inline(always)]
unsafe fn alloc_prof_realloc_impl(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_COUNT.fetch_add(1, Relaxed);
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, Relaxed);

    let prefix_allocations = tls_zend_mm_state_get!(prefix_allocations);
    let alloc_len = if prefix_allocations {
        len.checked_add(PREFIX_SIZE)
            .unwrap_or_else(|| libc::abort())
    } else {
        len
    };
    let base = if prefix_allocations {
        remove_prefix(prev_ptr)
    } else {
        prev_ptr
    };
    let ptr = tls_zend_mm_state_get!(realloc)(base, alloc_len);
    let ptr = if prefix_allocations {
        add_prefix(ptr)
    } else {
        ptr
    };

    // ZendMM allocation failures raise a fatal error and bail out instead of
    // returning NULL. If realloc returns, prev_ptr has been consumed: untrack it
    // before any userland-only early return, then let the new allocation be
    // re-sampled at the reported size.
    if !prev_ptr.is_null() && tls_zend_mm_state_get!(heap_live_enabled) {
        untrack_allocation(prev_ptr);
    }

    alloc_prof_realloc_sample(ptr, len)
}

#[inline(always)]
unsafe fn alloc_prof_realloc_no_untrack_impl(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_COUNT.fetch_add(1, Relaxed);
    #[cfg(feature = "debug_stats")]
    ALLOCATION_PROFILING_SIZE.fetch_add(len as u64, Relaxed);

    let prefix_allocations = tls_zend_mm_state_get!(prefix_allocations);
    let alloc_len = if prefix_allocations {
        len.checked_add(PREFIX_SIZE)
            .unwrap_or_else(|| libc::abort())
    } else {
        len
    };
    let base = if prefix_allocations {
        remove_prefix(prev_ptr)
    } else {
        prev_ptr
    };
    let ptr = tls_zend_mm_state_get!(realloc)(base, alloc_len);
    let ptr = if prefix_allocations {
        add_prefix(ptr)
    } else {
        ptr
    };

    alloc_prof_realloc_sample(ptr, len)
}

#[inline(always)]
unsafe fn alloc_prof_realloc_sample(ptr: *mut c_void, len: size_t) -> *mut c_void {
    // during startup, minit, rinit, ... current_execute_data is null
    // we are only interested in allocations during userland operations
    if zend::ddog_php_prof_get_current_execute_data().is_null() {
        return ptr;
    }

    if ptr.is_null() {
        return ptr;
    }

    if tls_zend_mm_state_get!(allocation_profiling_enabled)
        && allocation_profiling_stats_should_collect(len)
    {
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

#[cfg(php_zts)]
pub unsafe fn new_thread_end_should_reset() -> bool {
    tls_zend_mm_state_get!(uses_zend_mm) && !tls_zend_mm_state_get!(prefix_allocations)
}

unsafe extern "C" fn alloc_prof_shutdown(full: bool, silent: bool) {
    tls_zend_mm_state_get!(shutdown)(full, silent);

    if !full && tls_zend_mm_state_get!(uses_zend_mm) && !tls_zend_mm_state_get!(prefix_allocations)
    {
        let mut state = tls_zend_mm_state_copy!();
        state.prefix_allocations = true;
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
    fn prefix_preserves_allocator_alignment() {
        let base = unsafe { libc::malloc(PREFIX_SIZE + 1) };
        let ptr = unsafe { add_prefix(base) };

        assert_eq!(ptr as usize % PREFIX_SIZE, 0);
        assert_eq!(unsafe { remove_prefix(ptr) }, base);
        unsafe { libc::free(base) };
    }

    #[test]
    fn free_handler_tracks_only_when_heap_live_is_enabled() {
        assert_eq!(
            alloc_prof_free_handler(true) as usize,
            alloc_prof_free as zend::VmMmCustomFreeFn as usize
        );
        assert_eq!(
            alloc_prof_free_handler(false) as usize,
            alloc_prof_free_noop as zend::VmMmCustomFreeFn as usize
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
