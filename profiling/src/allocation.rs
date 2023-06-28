use crate::bindings as zend;
use crate::config;
use crate::PROFILER;
use crate::PROFILER_NAME;
use crate::REQUEST_LOCALS;
use libc::{c_char, c_int, c_void, size_t};
use log::{debug, error, info};
use once_cell::sync::OnceCell;
use std::cell::RefCell;
use std::ffi::CStr;

use rand_distr::{Distribution, Poisson};

use crate::bindings::{
    datadog_php_install_handler, datadog_php_zif_handler, ddog_php_prof_copy_long_into_zval,
};

static JIT_ENABLED: OnceCell<bool> = OnceCell::new();

static mut GC_MEM_CACHES_HANDLER: zend::InternalFunctionHandler = None;

/// The engine's previous custom allocation function, if there is one.
static mut PREV_CUSTOM_MM_ALLOC: Option<zend::VmMmCustomAllocFn> = None;

/// The engine's previous custom reallocation function, if there is one.
static mut PREV_CUSTOM_MM_REALLOC: Option<zend::VmMmCustomReallocFn> = None;

/// The engine's previous custom free function, if there is one.
static mut PREV_CUSTOM_MM_FREE: Option<zend::VmMmCustomFreeFn> = None;

pub fn allocation_profiling_minit() {
    unsafe { zend::ddog_php_opcache_init_handle() };
}

/// take a sample every 2048 KB
pub const ALLOCATION_PROFILING_INTERVAL: f64 = 1024.0 * 2048.0;

pub struct AllocationProfilingStats {
    /// number of bytes until next sample collection
    next_sample: i64,
}

impl AllocationProfilingStats {
    fn new() -> AllocationProfilingStats {
        AllocationProfilingStats {
            next_sample: AllocationProfilingStats::next_sampling_interval(),
        }
    }

    fn next_sampling_interval() -> i64 {
        Poisson::new(ALLOCATION_PROFILING_INTERVAL)
            .unwrap()
            .sample(&mut rand::thread_rng()) as i64
    }

    fn track_allocation(&mut self, len: size_t) {
        self.next_sample -= len as i64;

        if self.next_sample > 0 {
            return;
        }

        self.next_sample = AllocationProfilingStats::next_sampling_interval();

        REQUEST_LOCALS.with(|cell| {
            // Panic: there might already be a mutable reference to `REQUEST_LOCALS`
            let locals = cell.try_borrow();
            if locals.is_err() {
                return;
            }
            let locals = locals.unwrap();

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                // Safety: execute_data was provided by the engine, and the profiler doesn't mutate it.
                unsafe {
                    profiler.collect_allocations(
                        zend::ddog_php_prof_get_current_execute_data(),
                        1 as i64,
                        len as i64,
                        &locals,
                    )
                };
            }
        });
    }
}

thread_local! {
    static ALLOCATION_PROFILING_STATS: RefCell<AllocationProfilingStats> = RefCell::new(AllocationProfilingStats::new());
}

/* If Failure is returned the VM will do a C exit; try hard to avoid that,
 * using it for catastrophic errors only.
 */
pub fn allocation_profiling_rinit() {
    let profiling_allocation_enabled = unsafe { config::profiling_allocation_enabled() };

    {
        if profiling_allocation_enabled {
            let jit = JIT_ENABLED.get_or_init(|| unsafe { zend::ddog_php_jit_enabled() });
            if *jit {
                error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT. See https://github.com/DataDog/dd-trace-php/pull/2088");
                REQUEST_LOCALS.with(|cell| {
                    let mut locals = cell.borrow_mut();
                    locals.profiling_allocation_enabled = false;
                });
            } else {
                if !is_zend_mm() {
                    // Neighboring custom memory handlers found
                    debug!("Found another extension using the ZendMM custom handler hook");
                    unsafe {
                        zend::zend_mm_get_custom_handlers(
                            zend::zend_mm_get_heap(),
                            &mut PREV_CUSTOM_MM_ALLOC,
                            &mut PREV_CUSTOM_MM_FREE,
                            &mut PREV_CUSTOM_MM_REALLOC,
                        );
                        ALLOCATION_PROFILING_ALLOC = allocation_profiling_prev_alloc;
                        ALLOCATION_PROFILING_FREE = allocation_profiling_prev_free;
                        ALLOCATION_PROFILING_REALLOC = allocation_profiling_prev_realloc;
                    }
                } else {
                    unsafe {
                        ALLOCATION_PROFILING_ALLOC = allocation_profiling_orig_alloc;
                        ALLOCATION_PROFILING_FREE = allocation_profiling_orig_free;
                        ALLOCATION_PROFILING_REALLOC = allocation_profiling_orig_realloc;
                    }
                }

                unsafe {
                    zend::ddog_php_prof_zend_mm_set_custom_handlers(
                        zend::zend_mm_get_heap(),
                        Some(alloc_profiling_malloc),
                        Some(alloc_profiling_free),
                        Some(alloc_profiling_realloc),
                    );
                }

                if is_zend_mm() {
                    error!("Memory allocation profiling could not be enabled. Please feel free to fill an issue stating the PHP version and installed modules. Most likely the reason is your PHP binary was compiled with `ZEND_MM_CUSTOM` being disabled.");
                    REQUEST_LOCALS.with(|cell| {
                        let mut locals = cell.borrow_mut();
                        locals.profiling_allocation_enabled = false;
                    });
                } else {
                    info!("Memory allocation profiling enabled.")
                }
            }
        }
    }
}

pub fn allocation_profiling_rshutdown() {
    REQUEST_LOCALS.with(|cell| {
        let mut locals = cell.borrow_mut();

        {
            if locals.profiling_allocation_enabled {
                // If `is_zend_mm()` is true, the custom handlers have been reset to `None`
                // already. This is unexpected, therefore we will not touch the ZendMM handlers
                // anymore as resetting to prev handlers might result in segfaults and other
                // undefined behaviour.
                if !is_zend_mm() {
                    let mut custom_mm_malloc: Option<zend::VmMmCustomAllocFn> = None;
                    let mut custom_mm_free: Option<zend::VmMmCustomFreeFn> = None;
                    let mut custom_mm_realloc: Option<zend::VmMmCustomReallocFn> = None;
                    unsafe {
                        zend::zend_mm_get_custom_handlers(
                            zend::zend_mm_get_heap(),
                            &mut custom_mm_malloc,
                            &mut custom_mm_free,
                            &mut custom_mm_realloc,
                        );
                    }
                    if custom_mm_free != Some(alloc_profiling_free)
                        || custom_mm_malloc != Some(alloc_profiling_malloc)
                        || custom_mm_realloc != Some(alloc_profiling_realloc)
                    {
                        // Custom handlers are installed, but it's not us. Someone, somewhere might have
                        // function pointers to our custom handlers. Best bet to avoid segfaults is to not
                        // touch custom handlers in ZendMM and make sure our extension will not be
                        // `dlclose()`-ed so the pointers stay valid
                        let zend_extension = unsafe {
                            zend::zend_get_extension(PROFILER_NAME.as_ptr() as *const c_char)
                        };
                        if !zend_extension.is_null() {
                            // Safety: Checked for null pointer above.
                            unsafe {
                                (*zend_extension).handle = std::ptr::null_mut();
                            }
                        }
                        // disable any further allocation profiling
                        locals.profiling_allocation_enabled = false;
                        info!("Memory allocation profiling disabled.");
                    } else {
                        // This is the happy path (restore previously installed custom handlers)!
                        unsafe {
                            zend::ddog_php_prof_zend_mm_set_custom_handlers(
                                zend::zend_mm_get_heap(),
                                PREV_CUSTOM_MM_ALLOC,
                                PREV_CUSTOM_MM_FREE,
                                PREV_CUSTOM_MM_REALLOC,
                            );
                        }
                    }
                }
            }
        }
    });
}

pub fn allocation_profiling_startup() {
    unsafe {
        let handle = datadog_php_zif_handler::new(
            CStr::from_bytes_with_nul_unchecked(b"gc_mem_caches\0"),
            &mut GC_MEM_CACHES_HANDLER,
            Some(alloc_profiling_gc_mem_caches),
        );
        datadog_php_install_handler(handle);
    }
}

/// Overrides the ZendMM heap's `use_custom_heap` flag with the default `ZEND_MM_CUSTOM_HEAP_NONE`
/// (currently a `u32: 0`). This needs to be done, as the `zend_mm_gc()` and `zend_mm_shutdown()`
/// functions alter behaviour in case custom handlers are installed.
/// - `zend_mm_gc()` will not do anything anymore.
/// - `zend_mm_shutdown()` wont cleanup chunks anymore, leading to memory leaks
/// The `_zend_mm_heap`-struct itself is private, but we are lucky, as the `use_custom_heap` flag
/// is the first element and thus the first 4 bytes.
/// Take care and call `restore_zend_heap()` afterwards!
unsafe fn prepare_zend_heap(heap: *mut zend::_zend_mm_heap) -> c_int {
    let custom_heap: c_int = std::ptr::read(heap as *const c_int);
    std::ptr::write(heap as *mut c_int, zend::ZEND_MM_CUSTOM_HEAP_NONE as c_int);
    custom_heap
}

/// Restore the ZendMM heap's `use_custom_heap` flag, see `prepare_zend_heap` for details
unsafe fn restore_zend_heap(heap: *mut zend::_zend_mm_heap, custom_heap: c_int) {
    std::ptr::write(heap as *mut c_int, custom_heap);
}

/// The PHP userland function `gc_mem_caches()` directly calls the `zend_mm_gc()` function which
/// does nothing in case custom handlers are installed on the heap used. So we prepare the heap for
/// this operation, call the original function and restore the heap again
unsafe extern "C" fn alloc_profiling_gc_mem_caches(
    execute_data: *mut zend::zend_execute_data,
    return_value: *mut zend::zval,
) {
    let allocation_profiling: bool = REQUEST_LOCALS.with(|cell| {
        // Panic: there might already be a mutable reference to `REQUEST_LOCALS`
        let locals = cell.try_borrow();
        if locals.is_err() {
            // we can't check and don't know so assume it is not activated
            return false;
        }
        let locals = locals.unwrap();
        locals.profiling_allocation_enabled
    });

    if let Some(func) = GC_MEM_CACHES_HANDLER {
        if allocation_profiling {
            let heap = zend::zend_mm_get_heap();
            let custom_heap = prepare_zend_heap(heap);
            func(execute_data, return_value);
            restore_zend_heap(heap, custom_heap);
        } else {
            func(execute_data, return_value);
        }
    } else {
        ddog_php_prof_copy_long_into_zval(return_value, 0);
    }
}

unsafe extern "C" fn alloc_profiling_malloc(len: size_t) -> *mut c_void {
    let ptr: *mut c_void = ALLOCATION_PROFILING_ALLOC(len);

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

static mut ALLOCATION_PROFILING_ALLOC: unsafe fn(size_t) -> *mut c_void =
    allocation_profiling_orig_alloc;

unsafe fn allocation_profiling_prev_alloc(len: size_t) -> *mut c_void {
    let prev = PREV_CUSTOM_MM_ALLOC.unwrap();
    prev(len)
}

unsafe fn allocation_profiling_orig_alloc(len: size_t) -> *mut c_void {
    let ptr: *mut c_void;
    let heap = zend::zend_mm_get_heap();
    let custom_heap = prepare_zend_heap(heap);
    ptr = zend::_zend_mm_alloc(heap, len);
    restore_zend_heap(heap, custom_heap);
    ptr
}

// The reason this function exists is because when calling `zend_mm_set_custom_handlers()` you need
// to pass a pointer to a `free()` function as well, otherwise your custom handlers won't be
// installed. We can not just point to the original `zend::_zend_mm_free()` as the function
// definitions differ.
unsafe extern "C" fn alloc_profiling_free(ptr: *mut c_void) {
    ALLOCATION_PROFILING_FREE(ptr);
}

static mut ALLOCATION_PROFILING_FREE: unsafe fn(*mut c_void) = allocation_profiling_orig_free;

unsafe fn allocation_profiling_prev_free(ptr: *mut c_void) {
    let prev = PREV_CUSTOM_MM_FREE.unwrap();
    prev(ptr);
}

unsafe fn allocation_profiling_orig_free(ptr: *mut c_void) {
    let heap = zend::zend_mm_get_heap();
    zend::_zend_mm_free(heap, ptr);
}

unsafe extern "C" fn alloc_profiling_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    let ptr: *mut c_void = ALLOCATION_PROFILING_REALLOC(prev_ptr, len);

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

static mut ALLOCATION_PROFILING_REALLOC: unsafe fn(*mut c_void, size_t) -> *mut c_void =
    allocation_profiling_orig_realloc;

unsafe fn allocation_profiling_prev_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    let prev = PREV_CUSTOM_MM_REALLOC.unwrap();
    prev(prev_ptr, len)
}

unsafe fn allocation_profiling_orig_realloc(prev_ptr: *mut c_void, len: size_t) -> *mut c_void {
    let ptr: *mut c_void;
    let heap = zend::zend_mm_get_heap();
    let custom_heap = prepare_zend_heap(heap);
    ptr = zend::_zend_mm_realloc(heap, prev_ptr, len);
    restore_zend_heap(heap, custom_heap);
    ptr
}

/// safe wrapper for `zend::is_zend_mm()`.
/// `true` means the internal ZendMM is being used, `false` means that a custom memory manager is
/// installed. Upstream returns a `c_bool` as of PHP 8.0. PHP 7 returns a `c_int`
fn is_zend_mm() -> bool {
    #[cfg(php7)]
    {
        unsafe { zend::is_zend_mm() == 1 }
    }
    #[cfg(php8)]
    {
        unsafe { zend::is_zend_mm() }
    }
}
