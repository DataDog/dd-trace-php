use crate::allocation::{collect_allocation, ALLOCATION_PROFILING_STATS};
use crate::bindings::{
    self as zend, datadog_php_install_handler, datadog_php_zif_handler,
    ddog_php_prof_copy_long_into_zval,
};
use crate::{RefCellExt, PROFILER_NAME, REQUEST_LOCALS};
use core::{cell::Cell, ptr};
use lazy_static::lazy_static;
use libc::{c_char, c_int, c_void, size_t};
use log::{debug, error, trace, warn};
use std::sync::atomic::Ordering::Relaxed;

#[cfg(feature = "debug_stats")]
use crate::allocation::{ALLOCATION_PROFILING_COUNT, ALLOCATION_PROFILING_SIZE};

static mut GC_MEM_CACHES_HANDLER: zend::InternalFunctionHandler = None;

type ZendHeapPrepareFn = unsafe fn(heap: *mut zend::_zend_mm_heap) -> c_int;
type ZendHeapRestoreFn = unsafe fn(heap: *mut zend::_zend_mm_heap, custom_heap: c_int);

#[derive(Copy, Clone)]
struct ZendMMState {
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
    const fn new() -> ZendMMState {
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

const NEEDS_RUN_TIME_CHECK_FOR_ENABLED_JIT: bool =
    zend::PHP_VERSION_ID >= 80000 && zend::PHP_VERSION_ID < 80300 || zend::PHP_VERSION_ID >= 80400;

fn alloc_prof_needs_disabled_for_jit(version: u32) -> bool {
    // see https://github.com/php/php-src/pull/11380
    (80000..80121).contains(&version)
        || (80200..80208).contains(&version)
        || (80400..80407).contains(&version)
}

lazy_static! {
    static ref JIT_ENABLED: bool = unsafe { zend::ddog_php_jit_enabled() };
}

pub fn alloc_prof_ginit() {
    unsafe { zend::ddog_php_opcache_init_handle() };
}

pub fn first_rinit_should_disable_due_to_jit() -> bool {
    if NEEDS_RUN_TIME_CHECK_FOR_ENABLED_JIT
        && alloc_prof_needs_disabled_for_jit(crate::RUNTIME_PHP_VERSION_ID.load(Relaxed))
        && *JIT_ENABLED
    {
        if zend::PHP_VERSION_ID >= 80400 {
            error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.4.7. See https://github.com/DataDog/dd-trace-php/pull/3199");
        } else {
            error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.1.21 or 8.2.8. See https://github.com/DataDog/dd-trace-php/pull/2088");
        }
        true
    } else {
        false
    }
}

pub fn alloc_prof_rinit() {
    let zend_mm_state_init = |mut zend_mm_state: ZendMMState| -> ZendMMState {
        // Safety: `zend_mm_get_heap()` always returns a non-null pointer to a valid heap structure
        let heap = unsafe { zend::zend_mm_get_heap() };

        zend_mm_state.heap = Some(heap);

        if !is_zend_mm() {
            // Neighboring custom memory handlers found
            debug!("Found another extension using the ZendMM custom handler hook");
            unsafe {
                zend::zend_mm_get_custom_handlers(
                    heap,
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_alloc),
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_free),
                    ptr::addr_of_mut!(zend_mm_state.prev_custom_mm_realloc),
                );
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
        unsafe {
            zend::ddog_php_prof_zend_mm_set_custom_handlers(
                heap,
                Some(alloc_prof_malloc),
                Some(alloc_prof_free),
                Some(alloc_prof_realloc),
            );
        }
        zend_mm_state
    };

    let mm_state = tls_zend_mm_state_copy!();
    tls_zend_mm_state_set!(zend_mm_state_init(mm_state));

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
    // If `is_zend_mm()` is true, the custom handlers have already been reset
    // to `None`. This is unexpected, therefore we will not touch the ZendMM
    // handlers anymore as resetting to prev handlers might result in segfaults
    // and other undefined behavior.
    if is_zend_mm() {
        return;
    }

    let zend_mm_state_shutdown = |mut zend_mm_state: ZendMMState| -> ZendMMState {
        let mut custom_mm_malloc: Option<zend::VmMmCustomAllocFn> = None;
        let mut custom_mm_free: Option<zend::VmMmCustomFreeFn> = None;
        let mut custom_mm_realloc: Option<zend::VmMmCustomReallocFn> = None;

        // SAFETY: UnsafeCell::get() ensures non-null, and the object should
        // be valid for reads during rshutdown.
        let Some(heap) = zend_mm_state.heap else {
            // The heap can be None if a fork happens outside the request.
            return zend_mm_state;
        };

        unsafe {
            zend::zend_mm_get_custom_handlers(
                heap,
                &mut custom_mm_malloc,
                &mut custom_mm_free,
                &mut custom_mm_realloc,
            );
        }
        if custom_mm_free != Some(alloc_prof_free)
            || custom_mm_malloc != Some(alloc_prof_malloc)
            || custom_mm_realloc != Some(alloc_prof_realloc)
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
                zend::ddog_php_prof_zend_mm_set_custom_handlers(
                    heap,
                    zend_mm_state.prev_custom_mm_alloc,
                    zend_mm_state.prev_custom_mm_free,
                    zend_mm_state.prev_custom_mm_realloc,
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

pub fn alloc_prof_startup() {
    unsafe {
        let handle = datadog_php_zif_handler::new(
            c"gc_mem_caches",
            ptr::addr_of_mut!(GC_MEM_CACHES_HANDLER),
            Some(alloc_prof_gc_mem_caches),
        );
        datadog_php_install_handler(handle);
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
            let (prepare, restore) = tls_zend_mm_state_get!(prepare_restore_zend_heap);
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
    // Safety: `ZEND_MM_STATE.prev_custom_mm_alloc` will be initialised in
    // `alloc_prof_rinit()` and only point to this function when
    // `prev_custom_mm_alloc` is also initialised
    let alloc = tls_zend_mm_state_get!(prev_custom_mm_alloc).unwrap();
    alloc(len)
}

unsafe fn alloc_prof_orig_alloc(len: size_t) -> *mut c_void {
    let heap = zend::zend_mm_get_heap();
    let (prepare, restore) = tls_zend_mm_state_get!(prepare_restore_zend_heap);
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
    tls_zend_mm_state_get!(free)(ptr);
}

unsafe fn alloc_prof_prev_free(ptr: *mut c_void) {
    // Safety: `ZEND_MM_STATE.prev_custom_mm_free` will be initialised in
    // `alloc_prof_rinit()` and only point to this function when
    // `prev_custom_mm_free` is also initialised
    let free = tls_zend_mm_state_get!(prev_custom_mm_free).unwrap();
    free(ptr)
}

unsafe fn alloc_prof_orig_free(ptr: *mut c_void) {
    let heap = zend::zend_mm_get_heap();
    zend::_zend_mm_free(heap, ptr);
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
    // Safety: `ZEND_MM_STATE.prev_custom_mm_realloc` will be initialised in
    // `alloc_prof_rinit()` and only point to this function when
    // `prev_custom_mm_realloc` is also initialised
    let realloc = tls_zend_mm_state_get!(prev_custom_mm_realloc).unwrap();
    realloc(prev_ptr, len)
}

unsafe fn alloc_prof_orig_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    let heap = zend::zend_mm_get_heap();
    let (prepare, restore) = tls_zend_mm_state_get!(prepare_restore_zend_heap);
    let custom_heap = prepare(heap);
    let ptr: *mut c_void = zend::_zend_mm_realloc(heap, prev_ptr, len);
    restore(heap, custom_heap);
    ptr
}

/// safe wrapper for `zend::is_zend_mm()`.
/// `true` means the internal ZendMM is being used, `false` means that a custom memory manager is
/// installed. Upstream returns a `c_bool` as of PHP 8.0. PHP 7 returns a `c_int`
fn is_zend_mm() -> bool {
    #[cfg(php7)]
    unsafe {
        zend::is_zend_mm() == 1
    }
    #[cfg(php8)]
    unsafe {
        zend::is_zend_mm()
    }
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
