#include "php_ffi.h"

#include <assert.h>
#include <stdbool.h>
#include <string.h>

const char *datadog_extension_build_id(void) { return ZEND_EXTENSION_BUILD_ID; }
const char *datadog_module_build_id(void) { return ZEND_MODULE_BUILD_ID; }

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

#if CFG_PRELOAD // defined by build.rs
static bool _is_post_startup = false;

bool ddog_php_prof_is_post_startup(void) {
    return _is_post_startup;
}

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

    return SUCCESS;
}
#endif

void datadog_php_profiling_startup(zend_extension *extension) {
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
            break;
        }
    }

#if CFG_PRELOAD // defined by build.rs
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

void datadog_php_profiling_copy_string_view_into_zval(zval *dest, zai_string_view view,
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

/**
 * Converts the zend_string pointer into a string view. Null pointers and
 * empty strings will be converted into a string view to a static empty
 * string (single byte of null, len of 0).
 */
zai_string_view ddog_php_prof_zend_string_view(zend_string *zstr) {
    return (!zstr || ZSTR_LEN(zstr) == 0)
        ? ZAI_STRING_EMPTY
        : ZAI_STRING_FROM_ZSTR(zstr);
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

#if PHP_VERSION_ID >= 80000
static int ddog_php_prof_run_time_cache_handle = -1;
#endif

void ddog_php_prof_function_run_time_cache_init(const char *module_name) {
#if PHP_VERSION_ID >= 80000
    // Grab 2, one for function name and one for filename.
#if PHP_VERSION_ID < 80200
    ddog_php_prof_run_time_cache_handle =
        zend_get_op_array_extension_handle(module_name);
    int second = zend_get_op_array_extension_handle(module_name);
    ZEND_ASSERT(ddog_php_prof_run_time_cache_handle + 1 == second);
#else
    ddog_php_prof_run_time_cache_handle =
        zend_get_op_array_extension_handles(module_name, 2);
#endif
#endif

    /* It's possible to work on PHP 7.4 as well, but there are opcache bugs
     * that weren't truly fixed until PHP 8:
     * https://github.com/php/php-src/pull/5871
     * I would rather avoid these bugs for now.
     */
}

static bool has_invalid_run_time_cache(zend_function *func) {
    // It should be initialized by this point, or we failed.
    bool is_not_initialized = ddog_php_prof_run_time_cache_handle < 0;

    // Trampolines use the extension slot for internal things.
    bool is_trampoline = func->common.fn_flags & ZEND_ACC_CALL_VIA_TRAMPOLINE;

#if PHP_VERSION_ID < 80200
    // Internal functions don't have a runtime cache until PHP 8.2.
    bool is_internal = func->type == ZEND_INTERNAL_FUNCTION;

    // In some cases, using the logical-or instead of bitwise-or will end up
    // having conditional jumps. Since we overwhelmingly expect all conditions
    // to be false, reducing the branching helps a tiny bit for performance.
    return is_not_initialized | is_trampoline | is_internal;
#else
    return is_not_initialized | is_trampoline;
#endif
}

uintptr_t *ddog_php_prof_function_run_time_cache(zend_function *func) {
#if PHP_VERSION_ID < 80000
    /* It's possible to work on PHP 7.4 as well, but there are opcache bugs
     * that weren't truly fixed until PHP 8:
     * https://github.com/php/php-src/pull/5871
     * I would rather avoid these bugs for now.
     */
    return NULL;
#else

    if (UNEXPECTED(has_invalid_run_time_cache(func))) return NULL;

#if PHP_VERSION_ID < 80200
    // Internal functions don't have a runtime cache until PHP 8.2.
    uintptr_t *cache_addr = RUN_TIME_CACHE(&func->op_array);
#else
    uintptr_t *cache_addr = RUN_TIME_CACHE(&func->common);
#endif

    // todo: how sure can I be, here? Can I be so confident as to omit it?
    ZEND_ASSERT(cache_addr);

    return cache_addr + ddog_php_prof_run_time_cache_handle;
#endif
}
