#include "php_ffi.h"

#include <assert.h>
#include <stdlib.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include "SAPI.h"

#if CFG_STACK_WALKING_TESTS
#include <dlfcn.h> // for dlsym
#endif

#if PHP_VERSION_ID >= 70400 && PHP_VERSION_ID < 80400
#define CFG_NEED_OPCODE_HANDLERS 1
#else
#define CFG_NEED_OPCODE_HANDLERS 0
#endif

const char *datadog_extension_build_id(void) { return ZEND_EXTENSION_BUILD_ID; }
const char *datadog_module_build_id(void) { return ZEND_MODULE_BUILD_ID; }

uint8_t *ddtrace_runtime_id = NULL;

static void locate_ddtrace_runtime_id(const zend_extension *extension) {
    ddtrace_runtime_id = DL_FETCH_SYMBOL(extension->handle, "ddtrace_runtime_id");
}

static void locate_ddtrace_get_profiling_context(const zend_extension *extension) {
    ddtrace_profiling_context (*get_profiling)(void) =
        DL_FETCH_SYMBOL(extension->handle, "ddtrace_get_profiling_context");
    if (EXPECTED(get_profiling)) {
        datadog_php_profiling_get_profiling_context = get_profiling;
    }
}

static bool is_ddtrace_extension(const zend_extension *ext) {
    return ext && ext->name && strcmp(ext->name, "ddtrace") == 0;
}

static ddtrace_profiling_context noop_get_profiling_context(void) {
    return (ddtrace_profiling_context){0, 0};
}

#if PHP_VERSION_ID >= 80300
unsigned int php_version_id(void);
#else
// Forward declare zend_get_constant_str which will be used to polyfill the
// php_version_id function.
zval *zend_get_constant_str(const char *name, size_t name_len);

// Error helper in rare case of error for `php_version_id` shim.
ZEND_COLD zend_never_inline ZEND_NORETURN static void exit_php_version_id(zval *constant_str) {
    const char *message = constant_str != NULL
        ? "error looking up PHP_VERSION_ID: expected lval return type"
        : "error looking up PHP_VERSION_ID: constant not found";
    fprintf(stderr, "%s", message);
    exit(EXIT_FAILURE);
}

static unsigned int php_version_id(void) {
    zval *constant_str = zend_get_constant_str(ZEND_STRL("PHP_VERSION_ID"));
    if (EXPECTED(constant_str && Z_TYPE_P(constant_str))) {
        return Z_LVAL_P(constant_str);
    }

    // This branch should be dead code, just being defensive. The constant
    // PHP_VERSION_ID is registered before modules are ever registered:
    // https://heap.space/xref/PHP-7.1/main/main.c?r=ccd4716e#2180
    exit_php_version_id(constant_str);
}
#endif

sapi_request_info datadog_sapi_globals_request_info() {
    return SG(request_info);
}

/**
 * Returns the PHP_VERSION_ID of the engine at run-time, not the version the
 * extension was built against at compile-time.
 */
uint32_t ddog_php_prof_php_version_id(void) { return php_version_id(); }

#if CFG_POST_STARTUP_CB // defined by build.rs
static bool _is_post_startup = false;

bool ddog_php_prof_is_post_startup(void) {
    return _is_post_startup;
}

#if CFG_NEED_OPCODE_HANDLERS
/* The purpose here is to set the opline, because the built-in opcode handlers
 * for some versions are missing a SAVE_OPLINE() and it causes a crash when
 * trying to access the line number. The allocation profiler is vulnerable in
 * particular because it can access the opline on any allocation.
 *
 * The handler doesn't actually need to do anything, because just by having a
 * user opcode handler the engine will save the opline before calling the user
 * handler:
 * https://heap.space/xref/PHP-7.4/Zend/zend_vm_execute.h?r=0b7dffb4#2650
 */
static zend_result ddog_php_prof_opcode_dispatch(zend_execute_data *execute_data) {
    (void)execute_data;
    return ZEND_USER_OPCODE_DISPATCH;
}

// Argument `php_version_id` should be the version of PHP at runtime, not the
// version this was compiled against.
static void ddog_php_prof_install_opcode_handlers(uint32_t php_version_id) {

    /* Only need to install user opcode handlers if there isn't one already
     * for the given opcode, see the docs on `ddog_php_prof_opcode_dispatch`.
     */
    user_opcode_handler_t dispatch_handler = (user_opcode_handler_t)ddog_php_prof_opcode_dispatch;

#if PHP_VERSION_ID < 80100
    /* Issue is fixed in 8.0.26:
     * https://github.com/php/php-src/commit/26c7c82d32dad841dd151ebc6a31b8ea6f93f94a
     */
    if (php_version_id < 80026 && zend_get_user_opcode_handler(ZEND_GENERATOR_CREATE) == NULL) {
        zend_set_user_opcode_handler(ZEND_GENERATOR_CREATE, dispatch_handler);
    }
#endif

#if PHP_VERSION_ID < 80400
    /* Part of the issue was fixed in 8.0.12:
     * https://github.com/php/php-src/commit/ec54ffad1e3b15fedfd07f7d29d97ec3e8d1c45a
     * However, the fix is not complete as it's possible for the opcode to
     * call `zend_array_dup()` before the `SAVE_OPLINE()`.
     *
     * This was finally fixed with https://github.com/php/php-src/pull/12758 in
     * PHP 8.1.27, 8.2.14 and 8.3.1
     */
    if ((php_version_id < 80127 ||
        (php_version_id >= 80200 && php_version_id < 80214) ||
        php_version_id == 80300) &&
        zend_get_user_opcode_handler(ZEND_BIND_STATIC) == NULL)
    {
        zend_set_user_opcode_handler(ZEND_BIND_STATIC, dispatch_handler);
    }

    /* This was fixed with https://github.com/php/php-src/pull/12768 in
     * PHP 8.1.27, 8.2.14 and 8.3.1
     */
    if ((php_version_id < 80127 ||
        (php_version_id >= 80200 && php_version_id < 80214) ||
        php_version_id == 80300) &&
        zend_get_user_opcode_handler(ZEND_FUNC_GET_ARGS) == NULL)
    {
        zend_set_user_opcode_handler(ZEND_FUNC_GET_ARGS, dispatch_handler);
    }
#endif
}
#endif

#if PHP_VERSION_ID < 80000
#define post_startup_cb_result int
#else
#define post_startup_cb_result zend_result
#endif

static post_startup_cb_result (*orig_post_startup_cb)(void) = NULL;

static post_startup_cb_result ddog_php_prof_post_startup_cb(void) {
    if (orig_post_startup_cb) {
        post_startup_cb_result (*cb)(void) = orig_post_startup_cb;

        orig_post_startup_cb = NULL;
        if (cb() != SUCCESS) {
            return FAILURE;
        }
    }

    _is_post_startup = true;

#if CFG_NEED_OPCODE_HANDLERS
    uint32_t php_version_id = ddog_php_prof_php_version_id();
    ddog_php_prof_install_opcode_handlers(php_version_id);
#endif

    return SUCCESS;
}
#endif


#if CFG_RUN_TIME_CACHE // defined by build.rs
/**
 * Currently used to ignore run_time_cache on CLI SAPI as a precaution against
 * unbounded memory growth. Unbounded growth is more likely there since it's
 * always one PHP request, and we only reset it on each new request.
 */
static bool _ignore_run_time_cache = false;
#endif

void datadog_php_profiling_startup(zend_extension *extension) {
#if CFG_RUN_TIME_CACHE  // defined by build.rs
    _ignore_run_time_cache = strcmp(sapi_module.name, "cli") == 0;
#endif

    datadog_php_profiling_get_profiling_context = noop_get_profiling_context;

    /* Due to the optional dependency on ddtrace, the profiling module will be
     * loaded after ddtrace if it's present, so ddtrace should always be found
     * on startup and not need a message handler.
     */
    const zend_llist *list = &zend_extensions;
    for (const zend_llist_element *item = list->head; item; item = item->next) {
        const zend_extension *maybe_ddtrace = (zend_extension *)item->data;
        if (maybe_ddtrace != extension && is_ddtrace_extension(maybe_ddtrace)) {
            locate_ddtrace_get_profiling_context(maybe_ddtrace);
            locate_ddtrace_runtime_id(maybe_ddtrace);
            break;
        }
    }

#if CFG_POST_STARTUP_CB // defined by build.rs
    _is_post_startup = false;

    orig_post_startup_cb = zend_post_startup_cb;
    zend_post_startup_cb = ddog_php_prof_post_startup_cb;
#endif
}

void *datadog_php_profiling_vm_interrupt_addr(void) { return &EG(vm_interrupt); }

zend_module_entry *datadog_get_module_entry(const char *str, uintptr_t len) {
    return zend_hash_str_find_ptr(&module_registry, str, len);
}

ddtrace_profiling_context (*datadog_php_profiling_get_profiling_context)(void) =
    noop_get_profiling_context;

void datadog_php_profiling_install_internal_function_handler(
    datadog_php_profiling_internal_function_handler handler) {
    zend_function *old_handler;
    old_handler = zend_hash_str_find_ptr(CG(function_table), handler.name, handler.name_len);
    if (old_handler != NULL) {
        *handler.old_handler = old_handler->internal_function.handler;
        old_handler->internal_function.handler = handler.new_handler;
    }
}

void datadog_php_profiling_copy_string_view_into_zval(zval *dest, zai_str view,
                                                      bool persistent) {
    ZEND_ASSERT(dest);

    if (view.len == 0) {
        if (persistent) {
            ZVAL_EMPTY_PSTRING(dest);
        } else {
            ZVAL_EMPTY_STRING(dest);
        }
    } else {
        ZEND_ASSERT(view.ptr);
        ZVAL_STR(dest, zend_string_init(view.ptr, view.len, persistent));
    }
}

void ddog_php_prof_copy_long_into_zval(zval *dest, long num) {
    ZEND_ASSERT(dest);
    ZVAL_LONG(dest, num);
    return;
}

void ddog_php_prof_zend_mm_set_custom_handlers(zend_mm_heap *heap,
                                               void* (*_malloc)(size_t),
                                               void  (*_free)(void*),
                                               void* (*_realloc)(void*, size_t)) {
    zend_mm_set_custom_handlers(heap, _malloc, _free, _realloc);
#if PHP_VERSION_ID < 70300
    if (!_malloc && !_free && !_realloc) {
        memset(heap, ZEND_MM_CUSTOM_HEAP_NONE, sizeof(int));
    }
#endif
}

zend_execute_data* ddog_php_prof_get_current_execute_data() {
    return EG(current_execute_data);
}

#if CFG_FIBERS // defined by build.rs
zend_fiber* ddog_php_prof_get_active_fiber()
{
    return EG(active_fiber);
}

zend_fiber* ddog_php_prof_get_active_fiber_test()
{
    return NULL;
}
#endif

#if CFG_RUN_TIME_CACHE // defined by build.rs
static int ddog_php_prof_run_time_cache_handle = -1;
#endif

void ddog_php_prof_function_run_time_cache_init(const char *module_name) {
#if CFG_RUN_TIME_CACHE // defined by build.rs
    // Grab 1 slot for the full module|class::method name.
    // Grab 1 slot for caching filename, as it turns out the utf-8 validity
    // check is worth caching.
#if PHP_VERSION_ID < 80200
    ddog_php_prof_run_time_cache_handle =
        zend_get_op_array_extension_handle(module_name);
    int second = zend_get_op_array_extension_handle(module_name);
    ZEND_ASSERT(ddog_php_prof_run_time_cache_handle + 1 == second);
#else
    ddog_php_prof_run_time_cache_handle =
        zend_get_op_array_extension_handles(module_name, 2);
#endif
#else
    (void)module_name;
#endif

    /* It's possible to work on PHP 7.4 as well, but there are opcache bugs
     * that weren't truly fixed until PHP 8:
     * https://github.com/php/php-src/pull/5871
     * I would rather avoid these bugs for now.
     */
}

#if CFG_RUN_TIME_CACHE // defined by build.rs
static bool has_invalid_run_time_cache(zend_function const *func) {
    if (UNEXPECTED(_ignore_run_time_cache) || UNEXPECTED(ddog_php_prof_run_time_cache_handle < 0))
        return true;

    // during an `include()`/`require()` with enabled OPcache, OPcache is
    // persisting the compiled file and puts a fake frame on the stack where the
    // runtime cache is not yet initialized.
#if PHP_VERSION_ID < 80200
    bool is_file_compile = ZEND_MAP_PTR(func->op_array.run_time_cache) == NULL;
#else
    bool is_file_compile = ZEND_MAP_PTR(func->common.run_time_cache) == NULL;
#endif

    // Trampolines use the extension slot for internal things.
    bool is_trampoline = func->common.fn_flags & ZEND_ACC_CALL_VIA_TRAMPOLINE;

#if PHP_VERSION_ID < 80200
    // Internal functions don't have a runtime cache until PHP 8.2.
    bool is_internal = func->type == ZEND_INTERNAL_FUNCTION;

    // The bitwise-or is intentional. Branch prediction is not going to be great
    // on either of these, so reducing the number of branches is preferred.
    return is_trampoline | is_internal | is_file_compile;
#else
    return is_trampoline | is_file_compile;
#endif
}
#endif

uintptr_t *ddog_php_prof_function_run_time_cache(zend_function const *func) {
#if CFG_RUN_TIME_CACHE && !CFG_STACK_WALKING_TESTS
    if (UNEXPECTED(has_invalid_run_time_cache(func))) return NULL;

#if PHP_VERSION_ID < 80200
    // Internal functions don't have a runtime cache until PHP 8.2.
    uintptr_t *cache_addr = RUN_TIME_CACHE(&func->op_array);
#else
    uintptr_t *cache_addr = RUN_TIME_CACHE(&func->common);
#endif

    /* The above checks, including has_invalid_run_time_cache, protect this.
     * It's better to fail now on the null, than to wait for the returned addr
     * to get used, in the event future code changes screw this up.
     */
    ZEND_ASSERT(cache_addr);

    return cache_addr + ddog_php_prof_run_time_cache_handle;

#else
    (void)func;
    /* It's possible to work on PHP 7.4 as well, but there are opcache bugs
     * that weren't truly fixed until PHP 8:
     * https://github.com/php/php-src/pull/5871
     * I would rather avoid these bugs for now.
     */
    return NULL;
#endif
}

#if CFG_STACK_WALKING_TESTS
uintptr_t *ddog_test_php_prof_function_run_time_cache(zend_function const *func) {
#if CFG_RUN_TIME_CACHE
    if (_ignore_run_time_cache) return NULL;
    zend_function *non_const_func = (zend_function *)func;
#if PHP_VERSION_ID < 80200
    if (non_const_func->op_array.run_time_cache__ptr == NULL) {
        non_const_func->op_array.run_time_cache__ptr = calloc(1, sizeof(uintptr_t));
        *non_const_func->op_array.run_time_cache__ptr = calloc(2, sizeof(uintptr_t));
    }
    return *non_const_func->op_array.run_time_cache__ptr;
#else
    if (non_const_func->common.run_time_cache__ptr == NULL) {
        non_const_func->common.run_time_cache__ptr = calloc(1, sizeof(uintptr_t));
        *non_const_func->common.run_time_cache__ptr = calloc(2, sizeof(uintptr_t));
    }
    return *non_const_func->common.run_time_cache__ptr;
#endif
#else
    return NULL;
#endif
}
#endif

#if CFG_STACK_WALKING_TESTS
static int (*og_snprintf)(char *, size_t, const char *, ...);

// "weak" let's us polyfill, needed by zend_string_init(..., persistent: 1).
void *__attribute__((weak)) __zend_malloc(size_t len) {
    void *tmp = malloc(len);
    if (EXPECTED(tmp || !len)) {
        return tmp;
    }
    fprintf(stderr, "Out of memory\n");
    exit(1);
}

static zend_execute_data *create_fake_frame(int depth) {
    zend_execute_data *execute_data = calloc(1, sizeof(zend_execute_data));
    zend_op_array *op_array = calloc(1, sizeof(zend_function));
    op_array->type = ZEND_USER_FUNCTION;
    execute_data->func = (zend_function *)op_array;

    char buffer[64] = {0};
    int len = og_snprintf(buffer, sizeof buffer, "function name %03d", depth) + 1;
    ZEND_ASSERT(len >= 0 && sizeof buffer > (size_t)len);
    op_array->function_name = zend_string_init(buffer, len - 1, true);

    len = og_snprintf(buffer, sizeof buffer, "filename-%03d.php", depth) + 1;
    ZEND_ASSERT(len >= 0 && sizeof buffer > (size_t)len);
    op_array->filename = zend_string_init(buffer, len - 1, true);

    return execute_data;
}

static zend_execute_data *create_fake_zend_execute_data(int depth) {
    if (depth <= 0) return NULL;
    zend_execute_data *execute_data = create_fake_frame(depth);
    execute_data->prev_execute_data = create_fake_zend_execute_data(depth - 1);
    return execute_data;
}

zend_execute_data *ddog_php_test_create_fake_zend_execute_data(int depth) {
    if (!og_snprintf) {
        og_snprintf = dlsym(RTLD_NEXT, "snprintf");
        if (!og_snprintf) {
            fprintf(stderr, "Failed to locate symbol: %s", dlerror());
            exit(1);
        }
    }

    return create_fake_zend_execute_data(depth);
}

void ddog_php_test_free_fake_zend_execute_data(zend_execute_data *execute_data) {
    if (!execute_data) return;

    ddog_php_test_free_fake_zend_execute_data(execute_data->prev_execute_data);

    if (execute_data->func) {
        // free function name
        if (execute_data->func->common.function_name) {
            free(execute_data->func->common.function_name);
            execute_data->func->common.function_name = NULL;
        }
        // free filename
        if (execute_data->func->op_array.filename) {
            free(execute_data->func->op_array.filename);
            execute_data->func->op_array.filename = NULL;
        }
        // free zend_op_array
        free(execute_data->func);
        execute_data->func = NULL;
    }

    free(execute_data);
}
#endif

void *opcache_handle = NULL;

// OPcache NULLs its handle, so this function will only get the handle during
// MINIT phase. You as the caller has to make sure to only call this function
// during MINIT and not later.
void ddog_php_opcache_init_handle() {
    const zend_llist *list = &zend_extensions;
    zend_extension *maybe_opcache = NULL;
    for (const zend_llist_element *item = list->head; item; item = item->next) {
        maybe_opcache = (zend_extension *)item->data;
        if (maybe_opcache->name && strcmp(maybe_opcache->name, "Zend OPcache") == 0) {
            opcache_handle = maybe_opcache->handle;
            break;
        }
    }
}

// This checks if the JIT actually has a buffer, if so, JIT is active, otherwise
// JIT is inactive. This will only work after OPcache was initialized (after it
// assigned its PHP.INI settings), so make sure to call this in RINIT.
//
// Attention: this will check for the `opcache.jit_buffer_size` setting as this
// one is a PHP_INI_SYSTEM (can not be changed on a per directory basis). This
// means that the `opcache.jit` setting could be `off` and this function would
// still consider JIT to be enabled, as it could be enabled at anytime (runtime
// and per directory settings).
bool ddog_php_jit_enabled() {
    bool jit = false;

    if (opcache_handle) {
        void (*zend_jit_status)(zval *ret) = DL_FETCH_SYMBOL(opcache_handle, "zend_jit_status");
        if (zend_jit_status == NULL) {
            zend_jit_status = DL_FETCH_SYMBOL(opcache_handle, "_zend_jit_status");
        }
        if (zend_jit_status) {
            zval jit_stats_arr;
            array_init(&jit_stats_arr);
            zend_jit_status(&jit_stats_arr);

            zval *jit_stats = zend_hash_str_find(Z_ARR(jit_stats_arr), ZEND_STRL("jit"));
            zval *jit_buffer = zend_hash_str_find(Z_ARR_P(jit_stats), ZEND_STRL("buffer_size"));
            jit = Z_LVAL_P(jit_buffer) > 0; // JIT is active!

            zval_ptr_dtor(&jit_stats_arr);
        }
    }
    return jit;
}


#if PHP_VERSION_ID < 70200
#define zend_parse_parameters_none_throw() \
    (EXPECTED(ZEND_NUM_ARGS() == 0) ? SUCCESS : zend_parse_parameters_throw(ZEND_NUM_ARGS(), ""))
#endif

#if CFG_TRIGGER_TIME_SAMPLE
// Provided by Rust.
void ddog_php_prof_trigger_time_sample(void);

static ZEND_FUNCTION(Datadog_Profiling_trigger_time_sample) {
    zend_parse_parameters_none_throw();
    ddog_php_prof_trigger_time_sample();
    RETURN_NULL();
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_Datadog_Profiling_trigger_time_sample, 0, 0, 0)
ZEND_END_ARG_INFO()
#endif

static const zend_function_entry functions[] = {
#if CFG_TRIGGER_TIME_SAMPLE
    ZEND_NS_NAMED_FE(
        "Datadog\\Profiling",
        trigger_time_sample,
        ZEND_FN(Datadog_Profiling_trigger_time_sample),
        arginfo_Datadog_Profiling_trigger_time_sample
    )
#endif
    ZEND_FE_END
};
const zend_function_entry* ddog_php_prof_functions = functions;

zval *ddog_php_prof_get_memoized_config(uint16_t config_id) {
    return &zai_config_memoized_entries[config_id].decoded_value;
}
