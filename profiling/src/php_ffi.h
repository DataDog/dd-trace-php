#include <SAPI.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_types.h>
#if CFG_FIBERS // defined by build.rs
#include <Zend/zend_fibers.h>
#endif
#include <Zend/zend_generators.h>
#include <Zend/zend_globals_macros.h>
#include <Zend/zend_modules.h>
#include <Zend/zend_alloc.h>
#include <main/php_main.h>
#include <php.h>
#include <stdbool.h>
#include <stddef.h>

#include <ext/standard/info.h>

#ifdef __linux__
#include <elf.h>
#endif

// Needed for `zend_observer_error_register` starting from PHP 8
#if CFG_ZEND_ERROR_OBSERVER // defined by build.rs
#include <Zend/zend_errors.h>
#include <Zend/zend_observer.h>
#endif

// Profiling needs ZAI config for INI support.
#include <config/config.h>
// And json to cleanup json state for graceful restart
#include <json/json.h>

// Exception profiling needs to get the message of the exception (and ZAI
// provides `zai_exception_message()`)
#include <exceptions/exceptions.h>

// Used to communicate strings from C -> Rust.
#include <zai_string/string.h>

/* C11 allows a duplicate typedef provided they are the same, so this should be
 * fine as long as we compile with C11 or higher.
 */
typedef ZEND_RESULT_CODE zend_result;

/**
 * Returns macro expansion of ZEND_EXTENSION_BUILD_ID, which bindgen cannot
 * currently handle.
 */
const char *datadog_extension_build_id(void);

/**
 * Returns macro expansion of ZEND_MODULE_BUILD_ID, which bindgen cannot
 * currently handle.
 */
const char *datadog_module_build_id(void);

/**
 * Returns the `sapi_request_info` from the SAPI_GLOBALS
 */
sapi_request_info datadog_sapi_globals_request_info();

/**
 * Lookup module by name in the module registry. Returns NULL if not found.
 * This is meant to be called from Rust, so it uses uintptr_t, not size_t, for
 * the length for convenience.
 */
zend_module_entry *datadog_get_module_entry(const char *str, uintptr_t len);

/**
 * Fetches the VM interrupt address of the calling PHP thread.
 */
void *datadog_php_profiling_vm_interrupt_addr(void);

/**
 * For Code Hotspots, we need the tracer's local root span id and the current
 * span id. This is a cross-product struct, so keep it in sync with tracer's
 * version of this struct.
 * todo: re-use the tracer's header?
 */
typedef struct ddtrace_profiling_context_s {
    uint64_t local_root_span_id, span_id;
} ddtrace_profiling_context;

/**
 * A pointer to the tracer's ddtrace_get_profiling_context function if it was
 * found, otherwise points to a function which just returns {0, 0}.
 */
extern ddtrace_profiling_context (*datadog_php_profiling_get_profiling_context)(void);

/**
 * A pointer to the tracer's ddtrace_get_process_tags_serialized function if it
 * was found, otherwise points to a function which just returns NULL;
 */
extern zend_string *(*datadog_php_profiling_get_process_tags_serialized)(void);

/**
 * Called by this zend_extension's .startup handler. Does things that are
 * burdensome in Rust, like locating the ddtrace extension in the module
 * registry and finding the ddtrace_get_profiling_context function.
 */
void datadog_php_profiling_startup(zend_extension *extension);

/**
 * Used to hold information for overwriting the internal function handler
 * pointer in the Zend Engine.
 */
typedef struct {
    const char *name;
    size_t name_len;
    void (**old_handler)(INTERNAL_FUNCTION_PARAMETERS);
    void (*new_handler)(INTERNAL_FUNCTION_PARAMETERS);
} datadog_php_profiling_internal_function_handler;

void datadog_php_profiling_install_internal_function_handler(
    datadog_php_profiling_internal_function_handler handler);

/**
 * Copies the bytes represented by `view` into a zend_string, which is stored
 * in `dest`, passing `persistent` along so the right allocator is used.
 *
 * Does an empty string optimization.
 *
 * `dest` is expected to be uninitialized. Any existing content will not be
 * dtor'.
 */
void datadog_php_profiling_copy_string_view_into_zval(zval *dest, zai_str view,
                                                      bool persistent);

/**
 * Copies the number in `num` into a zval, which is stored in `dest`
 *
 * `dest` is expected to be uninitialized. Any existing content will not be
 * dtor'.
 */
void ddog_php_prof_copy_long_into_zval(zval *dest, long num);

/**
 * Wrapper to PHP's `zend_mm_set_custom_handlers()`. Starting from PHP 7.3
 * onwards the upstream `zend_mm_set_custom_handlers()` function will restore
 * the `use_custom_heap` flag on the `zend_mm_heap` to
 * `ZEND_MM_CUSTOM_HEAP_NONE` when you pass in three null pointers. PHP
 * versions prior to 7.3 (e.g. 7.2 and 7.1) which we currently do support don't
 * do this. This leads to a situation where null pointers are being called
 * which leads to segfaults. To circumvent this bug we will manually reset the
 * `use_custom_heap` flag back to normal when null pointers are being passed
 * in on those PHP versions.
 */
void ddog_php_prof_zend_mm_set_custom_handlers(zend_mm_heap *heap,
                                               void* (*_malloc)(size_t),
                                               void  (*_free)(void*),
                                               void* (*_realloc)(void*, size_t));

zend_execute_data* ddog_php_prof_get_current_execute_data();

#if CFG_FIBERS
zend_fiber* ddog_php_prof_get_active_fiber();
zend_fiber* ddog_php_prof_get_active_fiber_test();
#endif

/**
 * The following two functions exist for the sole purpose of creating fake stack
 * frames that can be used in testing/benchmarking scenarios
 */
zend_execute_data* ddog_php_test_create_fake_zend_execute_data(int depth);
void ddog_php_test_free_fake_zend_execute_data(zend_execute_data *execute_data);

void ddog_php_opcache_init_handle();
bool ddog_php_jit_enabled();

/**
 * Registers a single run_time_cache slot for caching FunctionIndex values.
 * Must be called during module/extension initialization.
 */
void ddog_php_prof_function_run_time_cache_init(const char *module_name);

/**
 * Returns the address of the single FunctionIndex run_time_cache slot, or NULL
 * if the run_time_cache is unavailable for this function.
 */
uintptr_t *ddog_php_prof_function_run_time_cache(const zend_function *func);

/**
 * Detects if the current thread is a parallel extension thread.
 * Returns true if the thread was spawned by the parallel extension.
 */
bool ddog_php_prof_is_parallel_thread();

/**
 * Allocates a reserved[] slot via zend_get_resource_handle().
 * Must be called in MINIT, after the zend_extension struct is populated but
 * before zend_register_extension(). On PHP 7 the API takes a zend_extension*;
 * on PHP 8+ it takes a const char*. This wrapper handles both.
 * Returns FAILURE and logs to stderr if the slot is out of range.
 */
zend_result ddog_php_prof_op_array_reserved_slot_init(zend_extension *extension);

/**
 * Returns the reserved[] slot index, or -1 if not yet initialized.
 */
int ddog_php_prof_op_array_reserved_slot(void);

/**
 * Returns true when OPcache file cache is enabled in any mode for this process.
 * The result is computed during startup from system INI state.
 */
bool ddog_php_prof_opcache_file_cache_enabled(void);

/**
 * Refreshes the cached request-local OPcache policy booleans from active INI
 * state. Call from RINIT after request configuration has been activated.
 */
void ddog_php_prof_refresh_request_opcache_policy(void);

/**
 * Releases the cached OPcache INI key strings created during startup.
 * Call from zend extension shutdown.
 */
void ddog_php_prof_shutdown_opcache_ini_keys(void);

/**
 * Updates the current thread's cached request-local OPcache policy state.
 * Implemented in Rust against profiling module globals.
 */
void ddog_php_prof_set_cached_request_opcache_policy(
    bool opcache_enabled,
    bool opcache_file_cache_enabled);

/**
 * Reads or updates the cached CLI `opcache.enable_cli` state for the current
 * thread. Implemented in Rust against profiling module globals.
 */
void ddog_php_prof_get_cached_cli_opcache_enable_state(bool *initialized, bool *enabled);
void ddog_php_prof_set_cached_cli_opcache_enable_state(bool initialized, bool enabled);

/**
 * Store a FunctionIndex in func->common.reserved[slot].
 */
void ddog_php_prof_set_function_index(zend_function *func, uint32_t index);

/**
 * Read the FunctionIndex from func->common.reserved[slot].
 * Returns true and writes to *out on success (0 means FUNCTION_EMPTY).
 * Returns false only if the reserved slot is not allocated (_op_array_reserved_slot < 0).
 */
bool ddog_php_prof_get_function_index(const zend_function *func, uint32_t *out);

/**
 * Returns the op_array_persist_calc hook. Always returns 0: we store FunctionIndex
 * directly in reserved[slot] (already in OPcache SHM), so no extra arena bytes needed.
 */
op_array_persist_calc_func_t ddog_php_prof_get_persist_calc_fn(void);

/**
 * Returns the op_array_persist hook. Interns the function into the profiling SHM
 * and writes the FunctionIndex into reserved[slot] when the reserved-slot policy
 * allows it. Returns 0.
 */
op_array_persist_func_t ddog_php_prof_get_persist_fn(void);

/**
 * Iterate all functions in CG(function_table) and all methods in CG(class_table),
 * calling ddog_php_prof_intern_and_store() for each.
 * Must be called from the zend_extension startup hook.
 */
void ddog_php_prof_intern_all_functions(void);

/**
 * Intern a single zend_function into the profiling SHM and store the
 * resulting FunctionIndex in func->common.reserved[slot] when the reserved-slot
 * policy allows it.
 * Implemented in Rust; called from C.
 */
void ddog_php_prof_intern_and_store(zend_function *func);
