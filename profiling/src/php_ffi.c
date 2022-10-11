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
}

void *datadog_php_profiling_vm_interrupt_addr(void) { return &EG(vm_interrupt); }

zend_module_entry *datadog_get_module_entry(const uint8_t *str, uintptr_t len) {
    return zend_hash_str_find_ptr(&module_registry, (const char *)str, len);
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

#if PHP_VERSION_ID >= 80000
static int dd_profiling_handle = -1;
#endif

void datadog_php_profiling_cache_polymorphic_init(const char *module_name) {
    /* The polymorphic cache requires 2 handles, one for the CE and one for
     * the value being stored in the cache. In our case, the CE is used as a
     * pointer round-tripped through uintptr_t, but the value is not a pointer
     * at all; it's just a pointer sized integer.
     */

#if PHP_VERSION_ID >= 80000
    dd_profiling_handle = zend_get_op_array_extension_handles(module_name, 2);
#endif

    /* It's possible to work on PHP 7.4 as well, but there are opcache bugs
     * that weren't truly fixed until PHP 8:
     * https://github.com/php/php-src/pull/5871
     * I would rather avoid these bugs.
     */
}

/**
 * Gets the cached pointer-sized integer, or 0 if the cache is invalid, or if
 * the feature is not supported on this version.
 */
uintptr_t datadog_php_profiling_cached_polymorphic_ptr(zend_function *func, zend_class_entry *ce) {
#if PHP_VERSION_ID < 80000
    return 0;
#else

#if PHP_VERSION_ID < 80200
    // internal functions don't have a runtime cache until PHP 8.2
    if (func->type == ZEND_INTERNAL_FUNCTION) return 0;
#endif

    uintptr_t *cache_addr = RUN_TIME_CACHE(&func->common);

    // To my knowledge, this is always a bug but it has happened.
    if (!cache_addr) return 0;

    uintptr_t *slots = cache_addr + dd_profiling_handle;
    // There is a macro CACHED_POLYMORPHIC_PTR_EX but it's not really needed.
    return (*(zend_class_entry **)slots) == ce ? slots[1] : 0;
#endif
}

/**
 * Caches the pointer-sized integer in the polymorphic cache. The value of
 * zero means it is not set or the feature is unsupported on this version.
 */
void datadog_php_profiling_cache_polymorphic_ptr(zend_function *func, zend_class_entry *ce,
                                                 uintptr_t ptr) {
#if PHP_VERSION_ID < 80000
    return 0;
#else

#if PHP_VERSION_ID < 80200
    // internal functions don't have a runtime cache until PHP 8.2
    if (func->type == ZEND_INTERNAL_FUNCTION) return;
#endif
    uintptr_t *cache_addr = RUN_TIME_CACHE(&func->common);

    // To my knowledge, this is always a bug but it has happened.
    if (!cache_addr) return;

    uintptr_t *slots = cache_addr + dd_profiling_handle;
    (*(zend_class_entry **)slots) = ce;
    slots[1] = ptr;
#endif
}
