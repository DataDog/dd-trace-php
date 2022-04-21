#include "configuration.h"

#include <assert.h>

#include "circuit_breaker.h"
#include "logging.h"

#define DD_TO_DATADOG_INC 5 /* "DD" expanded to "datadog" */

#define APPLY_0(...)
#define APPLY_1(macro, arg, ...) macro(arg)
#define APPLY_2(macro, arg, ...) macro(arg) APPLY_1(macro, __VA_ARGS__)
#define APPLY_3(macro, arg, ...) macro(arg) APPLY_2(macro, __VA_ARGS__)
#define APPLY_4(macro, arg, ...) macro(arg) APPLY_3(macro, __VA_ARGS__)
#define APPLY_NAME_EXPAND(count) APPLY_##count
#define APPLY_NAME(count) APPLY_NAME_EXPAND(count)
#define APPLY_COUNT(_0, _1, _2, _3, _4, N, ...) N
#define APPLY_N(macro, ...) APPLY_NAME(APPLY_COUNT(0, ##__VA_ARGS__, 4, 3, 2, 1, 0))(macro, ##__VA_ARGS__)

// static assert name lengths, number of configs and number of aliases
#define CALIAS CONFIG
#define CONFIG(...) 1,
#define NUMBER_OF_CONFIGURATIONS sizeof((uint8_t[]){DD_CONFIGURATION})
_Static_assert(NUMBER_OF_CONFIGURATIONS < ZAI_CONFIG_ENTRIES_COUNT_MAX,
               "There are more config entries than ZAI_CONFIG_ENTRIES_COUNT_MAX.");
#undef CONFIG
#define CONFIG(type, name, ...)                                                \
    _Static_assert(sizeof(#name) < ZAI_CONFIG_NAME_BUFSIZ - DD_TO_DATADOG_INC, \
                   "The name of " #name                                        \
                   " is longer than allowed ZAI_CONFIG_NAME_BUFSIZ - " DD_CFG_STR(DD_TO_DATADOG_INC));
DD_CONFIGURATION
#undef CONFIG
#undef CALIAS
#define CONFIG(...)
#define ELEMENT(arg) 1,
#define CALIASES(...) APPLY_N(ELEMENT, ##__VA_ARGS__)
#define CALIAS(type, name, default, aliases, ...)                             \
    _Static_assert(sizeof((uint8_t[]){aliases}) < ZAI_CONFIG_NAMES_COUNT_MAX, \
                   #name " has more than the allowed ZAI_CONFIG_NAMES_COUNT_MAX alias names");
DD_CONFIGURATION
#undef CALIAS
#undef CALIASES
#define CALIAS_CHECK_LENGTH(name)                                              \
    _Static_assert(sizeof(#name) < ZAI_CONFIG_NAME_BUFSIZ - DD_TO_DATADOG_INC, \
                   "The name of " #name                                        \
                   " alias is longer than allowed ZAI_CONFIG_NAME_BUFSIZ - " DD_CFG_STR(DD_TO_DATADOG_INC));
#define CALIASES(...) APPLY_N(CALIAS_CHECK_LENGTH, ##__VA_ARGS__)
#define CALIAS(type, name, default, aliases, ...) aliases
DD_CONFIGURATION
#undef CALIAS
#undef CALIASES
#undef CONFIG

// Allow for partially defined struct initialization here
#pragma GCC diagnostic ignored "-Wmissing-field-initializers"

#define CALIAS_EXPAND(name) {.ptr = name, .len = sizeof(name) - 1},
#define CALIASES(...) ((zai_string_view[]){APPLY_N(CALIAS_EXPAND, ##__VA_ARGS__)})
#define CONFIG(type, name, ...) ZAI_CONFIG_ENTRY(DDTRACE_CONFIG_##name, name, type, __VA_ARGS__),
#define CALIAS(type, name, ...) ZAI_CONFIG_ALIASED_ENTRY(DDTRACE_CONFIG_##name, name, type, __VA_ARGS__),
static zai_config_entry config_entries[] = {DD_CONFIGURATION};
#undef CALIAS
#undef CONFIG

bool runtime_config_first_init = false;

static char dd_tolower_ascii(char c) { return c >= 'A' && c <= 'Z' ? c - ('A' - 'a') : c; }

static void dd_copy_tolower(char *restrict dst, const char *restrict src) {
    while (*src) {
        *(dst++) = dd_tolower_ascii(*(src++));
    }
}

static void dd_ini_env_to_ini_name(const zai_string_view env_name, zai_config_name *ini_name) {
    if (env_name.len + DD_TO_DATADOG_INC >= ZAI_CONFIG_NAME_BUFSIZ) {
        assert(false && "Expanded env name length is larger than the INI name buffer");
        return;
    }

    if (env_name.ptr == strstr(env_name.ptr, "DDTRACE_")) {
        // legacy names
        dd_copy_tolower(ini_name->ptr, env_name.ptr);
        ini_name->len = env_name.len;
        ini_name->ptr[sizeof("ddtrace") - 1] = '.';
    } else if (env_name.ptr == strstr(env_name.ptr, "DD_")) {
        dd_copy_tolower(ini_name->ptr + DD_TO_DATADOG_INC, env_name.ptr);
        memcpy(ini_name->ptr, "datadog.", sizeof("datadog.") - 1);
        ini_name->len = env_name.len + DD_TO_DATADOG_INC;

        if (env_name.ptr == strstr(env_name.ptr, "DD_TRACE_")) {
            ini_name->ptr[sizeof("datadog.trace") - 1] = '.';
        }
    } else {
        ini_name->len = 0;
        assert(false && "Unexpected env var name: missing 'DD_' prefix");
    }

    ini_name->ptr[ini_name->len] = '\0';
}

bool ddtrace_config_minit(int module_number) {
    if (!zai_config_minit(config_entries, (sizeof config_entries / sizeof *config_entries), dd_ini_env_to_ini_name,
                          module_number)) {
        ddtrace_log_err("Unable to load configuration; likely due to json symbols failing to resolve.");
        return false;
    }
    // We immediately initialize inis at MINIT, so that we can use a select few values already at minit.
    // Note that we are not calling zai_config_rinit(), i.e. the get_...() functions will not work.
    // This is intentional, so that places wishing to use values pre-RINIT do have to explicitly opt in by using the
    // arduous way of accessing the decoded_value directly from zai_config_memoized_entries.
    zai_config_first_time_rinit();
    return true;
}

void ddtrace_config_first_rinit() {
    zend_ini_entry *internal_functions_ini =
        zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_TRACED_INTERNAL_FUNCTIONS].ini_entries[0];
    zend_string *internal_functions_old = zend_string_copy(
        internal_functions_ini->modified ? internal_functions_ini->orig_value : internal_functions_ini->value);

    zai_config_first_time_rinit();
    zai_config_rinit();

    zend_string *internal_functions_new =
        internal_functions_ini->modified ? internal_functions_ini->orig_value : internal_functions_ini->value;

    if (!zend_string_equals(internal_functions_old, internal_functions_new)) {
        ddtrace_log_errf(
            "Received DD_TRACE_TRACED_INTERNAL_FUNCTIONS configuration via environment variable."
            "This specific value cannot be set via environment variable for this SAPI. This configuration "
            "needs to be available early in startup. To provide this value, set an ini value with the key "
            "datadog.trace.traced_internal_functions in your system PHP ini file. System value: %s. "
            "Environment variable value: %s",
            ZSTR_VAL(internal_functions_old), ZSTR_VAL(internal_functions_new));
    }
    zend_string_release(internal_functions_old);

    runtime_config_first_init = true;
}

// note: only call this if get_DD_TRACE_ENABLED() returns true
bool ddtrace_config_integration_enabled(ddtrace_integration_name integration_name) {
    ddtrace_integration *integration = &ddtrace_integrations[integration_name];

    if (zend_hash_str_find(get_DD_INTEGRATIONS_DISABLED(), ZEND_STRL("default"))) {
        return integration->is_enabled();
    } else {
        // Deprecated as of 0.47.1
        return zend_hash_str_find(get_DD_INTEGRATIONS_DISABLED(), integration->name_lcase, integration->name_len) ==
               NULL;
    }
}
