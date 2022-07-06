#include "php_ffi.h"

#include <assert.h>
#include <stdbool.h>
#include <string.h>

const char *datadog_extension_build_id(void) { return ZEND_EXTENSION_BUILD_ID; }
const char *datadog_module_build_id(void) { return ZEND_MODULE_BUILD_ID; }

ZEND_DECLARE_MODULE_GLOBALS(datadog_php_profiling)

#ifdef ZTS
#define DATADOG_PHP_PROFILING_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(datadog_php_profiling, v)
#else
#define DATADOG_PHP_PROFILING(v) (datadog_php_profiling_globals.v)
#endif

#if PHP_VERSION_ID >= 80000
#define DD_INI_GET_ADDR() ZEND_INI_GET_ADDR()
#else
#ifndef ZTS
#define DD_INI_GET_BASE() ((char *)mh_arg2)
#else
#define DD_INI_GET_BASE() ((char *)ts_resource(*((int *)mh_arg2)))
#endif
#define DD_INI_GET_ADDR() (DD_INI_GET_BASE() + (size_t)mh_arg1)
#endif

#if PHP_VERSION_ID < 70200
static zend_string *zend_string_init_interned(const char *str, size_t len, int persistent) {
    zend_string *ret = zend_string_init(str, len, persistent);

    return zend_new_interned_string(ret);
}
#endif

zend_datadog_php_profiling_globals *datadog_php_profiling_globals_get(void) {
    // TODO: ZTS support
    return &datadog_php_profiling_globals;
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

void datadog_php_profiling_rinit(void) {
    DATADOG_PHP_PROFILING(vm_interrupt_addr) = &EG(vm_interrupt);
}

datadog_php_str datadog_php_profiling_intern(const char *str, size_t size, bool permanent) {
    zend_string *string = zend_string_init_interned(str, size, permanent);
    datadog_php_str interned = {
        .ptr = ZSTR_VAL(string),
        .size = ZSTR_LEN(string),
    };
    return interned;
}

zend_module_entry *datadog_get_module_entry(const uint8_t *str, uintptr_t len) {
    return zend_hash_str_find_ptr(&module_registry, (const char *)str, len);
}

ddtrace_profiling_context (*datadog_php_profiling_get_profiling_context)(void) =
    noop_get_profiling_context;
