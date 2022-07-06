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

/**
 * Returns true if `a` and `b` have the same size and all their bytes compare
 * as equal. The pointers `a.ptr` and `b.ptr` should not be NULL (please use an
 * empty string).
 */
static bool str_eq(datadog_php_str a, datadog_php_str b) {
    assert(a.ptr);
    assert(b.ptr);
    return a.size == b.size && memcmp(a.ptr, b.ptr, b.size) == 0;
}

/**
 * Converts the ASCII char to its lowercase equivalent if it's a recognized
 * uppercase character; otherwise returns the char without modification.
 */
static char char_tolower_ascii(char c) {
    return (char)(c >= 'A' && c <= 'Z' ? (c - ('A' - 'a')) : c);
}

static void str_copy_tolower_ascii(size_t len, const char src[static len], char dest[static len]) {
    for (size_t i = 0; i != len; ++i) {
        *(dest++) = char_tolower_ascii(src[i]);
    }
}

static zend_result datadog_php_profiling_log_level_parse(datadog_php_str str,
                                                         uintptr_t *log_level) {
    // If the level string's length is greater than 5, it's definitely unknown.
    if (!str.size || !str.ptr || str.size > 5u) {
        return FAILURE;
    }

    // 8 chars is more than enough to hold the remaining strings.
    _Alignas(8) char buffer[8] = {0, 0, 0, 0, 0, 0, 0, 0};

    // Lowercase to make this a case-insensitive operation.
    str_copy_tolower_ascii(str.size, str.ptr, buffer);
    datadog_php_str buff = {&buffer[0], strlen(buffer)};

    struct {
        datadog_php_str str;
        datadog_php_profiling_log_level level;
    } options[] = {
        {{"off", 3}, DATADOG_PHP_PROFILING_LOG_LEVEL_OFF},
        {{"error", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_ERROR},
        {{"warn", 4}, DATADOG_PHP_PROFILING_LOG_LEVEL_WARN},
        {{"info", 4}, DATADOG_PHP_PROFILING_LOG_LEVEL_INFO},
        {{"debug", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_DEBUG},
        {{"trace", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_TRACE},
    };

    unsigned i, n_options = (sizeof options / sizeof options[0]);
    for (i = 0; i != n_options; ++i) {
        datadog_php_str slice = options[i].str;
        if (str_eq(buff, slice)) {
            *log_level = options[i].level;
            return SUCCESS;
        }
    }
    return FAILURE;
}

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

datadog_php_str datadog_php_profiling_log_level_to_str(uintptr_t log_level) {
    switch (log_level) {
        case DATADOG_PHP_PROFILING_LOG_LEVEL_OFF:
            return (datadog_php_str){"off", sizeof "off" - 1};
        case DATADOG_PHP_PROFILING_LOG_LEVEL_ERROR:
            return (datadog_php_str){"error", sizeof "error" - 1};
        case DATADOG_PHP_PROFILING_LOG_LEVEL_WARN:
            return (datadog_php_str){"warn", sizeof "warn" - 1};
        case DATADOG_PHP_PROFILING_LOG_LEVEL_INFO:
            return (datadog_php_str){"info", sizeof "info" - 1};
        case DATADOG_PHP_PROFILING_LOG_LEVEL_DEBUG:
            return (datadog_php_str){"debug", sizeof "debug" - 1};
        case DATADOG_PHP_PROFILING_LOG_LEVEL_TRACE:
            return (datadog_php_str){"trace", sizeof "trace" - 1};
        default:
            return (datadog_php_str){"unknown", sizeof "unknown" - 1};
    }
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

// TODO: can we get an ifdef for cfg(test) or something?
// At least this code seems to get shaken out, at least some of the time, so
// it's not urgent to stop building it.
#if 1

#include <stdio.h>

bool test_datadog_php_profiling_log_level_parse_success(void) {
    struct {
        datadog_php_str slice;
        uintptr_t log_level;
    } tests[] = {
        {{"off", 3}, DATADOG_PHP_PROFILING_LOG_LEVEL_OFF},
        {{"Off", 3}, DATADOG_PHP_PROFILING_LOG_LEVEL_OFF},
        {{"OFF", 3}, DATADOG_PHP_PROFILING_LOG_LEVEL_OFF},
        {{"error", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_ERROR},
        {{"Error", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_ERROR},
        {{"ERROR", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_ERROR},
        {{"warn", 4}, DATADOG_PHP_PROFILING_LOG_LEVEL_WARN},
        {{"Warn", 4}, DATADOG_PHP_PROFILING_LOG_LEVEL_WARN},
        {{"WARN", 4}, DATADOG_PHP_PROFILING_LOG_LEVEL_WARN},
        {{"info", 4}, DATADOG_PHP_PROFILING_LOG_LEVEL_INFO},
        {{"Info", 4}, DATADOG_PHP_PROFILING_LOG_LEVEL_INFO},
        {{"INFO", 4}, DATADOG_PHP_PROFILING_LOG_LEVEL_INFO},
        {{"debug", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_DEBUG},
        {{"Debug", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_DEBUG},
        {{"DEBUG", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_DEBUG},
        {{"trace", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_TRACE},
        {{"Trace", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_TRACE},
        {{"TRACE", 5}, DATADOG_PHP_PROFILING_LOG_LEVEL_TRACE},
    };

    bool success = true;
    size_t n_tests = sizeof tests / sizeof tests[0];
    for (unsigned i = 0; i != n_tests; ++i) {
        datadog_php_str str = tests[i].slice;
        uintptr_t log_level = tests[i].log_level;
        uintptr_t actual_level;
        if (datadog_php_profiling_log_level_parse(str, &actual_level) != SUCCESS) {
            fprintf(stderr, "Failed to parse log level: %*s.\n", (int)str.size, str.ptr);
            success = false;
        }

        if (log_level != actual_level) {
            datadog_php_str expected = datadog_php_profiling_log_level_to_str(log_level);
            datadog_php_str actual = datadog_php_profiling_log_level_to_str(actual_level);
            fprintf(stderr, "Log levels do not match; expected %*s, actual %*s.\n",
                    (int)expected.size, expected.ptr, (int)actual.size, actual.ptr);
            success = false;
        }
    }
    return success;
}

#endif
