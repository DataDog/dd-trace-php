#include <profiling/configuration.h>

#include <assert.h>
#include <string.h>

#define APPLY_0(...)
#define APPLY_1(macro, arg, ...) macro(arg)
#define APPLY_2(macro, arg, ...) macro(arg) APPLY_1(macro, __VA_ARGS__)
#define APPLY_3(macro, arg, ...) macro(arg) APPLY_2(macro, __VA_ARGS__)
#define APPLY_4(macro, arg, ...) macro(arg) APPLY_3(macro, __VA_ARGS__)
#define APPLY_NAME_EXPAND(count) APPLY_##count
#define APPLY_NAME(count) APPLY_NAME_EXPAND(count)
#define APPLY_COUNT(_0, _1, _2, _3, _4, N, ...) N
#define APPLY_N(macro, ...) APPLY_NAME(APPLY_COUNT(0, ##__VA_ARGS__, 4, 3, 2, 1, 0))(macro, ##__VA_ARGS__)

#define CALIAS CONFIG
#define CONFIG(type, name, ...) DDTRACE_PROFILING_CONFIG_##name,
typedef enum {
    DDTRACE_PROFILING_ALL_CONFIGURATIONS
    DDTRACE_PROFILING_CONFIG_COUNT
} ddprof_config_id;
#undef CONFIG
#undef CALIAS

#ifndef _WIN32
#define CALIAS CONFIG
#define CONFIG(...) 1,
#define DDTRACE_PROFILING_NUMBER_OF_CONFIGURATIONS sizeof((uint8_t[]){DDTRACE_PROFILING_ALL_CONFIGURATIONS})
_Static_assert(DDTRACE_PROFILING_NUMBER_OF_CONFIGURATIONS <= ZAI_CONFIG_ENTRIES_COUNT_MAX,
               "There are more profiler config entries than ZAI_CONFIG_ENTRIES_COUNT_MAX");
#undef CONFIG
#undef CALIAS
#endif

#define CALIAS_EXPAND(name) {.ptr = name, .len = sizeof(name) - 1},
#define CUSTOM(...) CUSTOM
#define CONFIG(type, name, ...) ZAI_CONFIG_ENTRY(DDTRACE_PROFILING_CONFIG_##name, name, type, __VA_ARGS__),
#define CALIASES(...) ((zai_str[]){APPLY_N(CALIAS_EXPAND, ##__VA_ARGS__)})
#define CALIAS(type, name, ...) ZAI_CONFIG_ALIASED_ENTRY(DDTRACE_PROFILING_CONFIG_##name, name, type, __VA_ARGS__),
static zai_config_entry ddprof_config_entries[] = {DDTRACE_PROFILING_ALL_CONFIGURATIONS};
#undef CALIAS
#undef CALIASES
#undef CONFIG
#undef CUSTOM

static char ddprof_tolower_ascii(char c) { return c >= 'A' && c <= 'Z' ? c - ('A' - 'a') : c; }

static void ddprof_copy_tolower(char *restrict dst, const char *restrict src) {
    while (*src) {
        *(dst++) = ddprof_tolower_ascii(*(src++));
    }
}

static void ddprof_env_to_ini_name(const zai_str env_name, zai_config_name *ini_name) {
    enum { DD_TO_DATADOG_INC = 5 }; /* "DD" expands to "datadog". */

    if (env_name.len + DD_TO_DATADOG_INC >= ZAI_CONFIG_NAME_BUFSIZ) {
        assert(false && "Expanded profiler env name is larger than the INI name buffer");
        return;
    }

    assert(env_name.ptr == strstr(env_name.ptr, "DD_") && "Profiler env name is missing its DD_ prefix");
    ddprof_copy_tolower(ini_name->ptr + DD_TO_DATADOG_INC, env_name.ptr);
    memcpy(ini_name->ptr, "datadog.", sizeof("datadog.") - 1);
    ini_name->len = env_name.len + DD_TO_DATADOG_INC;

    if (env_name.ptr == strstr(env_name.ptr, "DD_TRACE_")) {
        ini_name->ptr[sizeof("datadog.trace") - 1] = '.';
    } else if (env_name.ptr == strstr(env_name.ptr, "DD_PROFILING_")) {
        ini_name->ptr[sizeof("datadog.profiling") - 1] = '.';
    }

    ini_name->ptr[ini_name->len] = '\0';
}

bool ddog_php_prof_config_minit(int module_number) {
    return zai_config_minit(ddprof_config_entries, DDTRACE_PROFILING_CONFIG_COUNT, ddprof_env_to_ini_name, module_number);
}

uint16_t ddog_php_prof_config_count(void) { return DDTRACE_PROFILING_CONFIG_COUNT; }
