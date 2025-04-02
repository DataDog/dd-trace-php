#include "configuration.h"

#include <assert.h>

#include "ip_extraction.h"
#include "logging.h"
#include "json/json.h"
#include "sidecar.h"
#include <components/log/log.h>
#include <zai_string/string.h>
#include "sidecar.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define DD_TO_DATADOG_INC 5 /* "DD" expanded to "datadog" */

#define APPLY_0(...)
#define APPLY_1(macro, arg, ...) macro(arg)
#define APPLY_2(macro, arg, ...) macro(arg) APPLY_1(macro, __VA_ARGS__)
#define APPLY_3(macro, arg, ...) macro(arg) APPLY_2(macro, __VA_ARGS__)
#define APPLY_4(macro, arg, ...) macro(arg) APPLY_3(macro, __VA_ARGS__)
#define APPLY_NAME_EXPAND(count) APPLY_##count
#define APPLY_NAME(count) APPLY_NAME_EXPAND(count)
#define APPLY_COUNT(_0, _1, _2, _3, _4, N, ...) N
#if defined(_MSVC_TRADITIONAL) && _MSVC_TRADITIONAL
#define EXPAND(...) __VA_ARGS__
#define APPLY_N(macro, ...) APPLY_NAME(EXPAND(APPLY_COUNT(0, ##__VA_ARGS__, 4, 3, 2, 1, 0)))EXPAND((macro, ##__VA_ARGS__))
#else
#define APPLY_N(macro, ...) APPLY_NAME(APPLY_COUNT(0, ##__VA_ARGS__, 4, 3, 2, 1, 0))(macro, ##__VA_ARGS__)
#endif

// static assert name lengths, number of configs and number of aliases (Visual Studio 2017 and older do not support _Static_assert)
#ifndef _WIN32
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
#define CALIASES(...) (APPLY_N(ELEMENT, ##__VA_ARGS__))
#define ELEMENTS(...) __VA_ARGS__
#define CALIAS(type, name, default, aliases, ...)                             \
    _Static_assert(sizeof((uint8_t[]){ELEMENTS aliases}) < ZAI_CONFIG_NAMES_COUNT_MAX, \
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
#endif

static bool dd_parse_dbm_mode(zai_str value, zval *decoded_value, bool persistent) {
    UNUSED(persistent);
    if (zai_str_eq_ci_cstr(value, "disabled")) {
        ZVAL_LONG(decoded_value, DD_TRACE_DBM_PROPAGATION_DISABLED);
    } else if (zai_str_eq_ci_cstr(value, "service")) {
        ZVAL_LONG(decoded_value, DD_TRACE_DBM_PROPAGATION_SERVICE);
    } else if (zai_str_eq_ci_cstr(value, "full")) {
        ZVAL_LONG(decoded_value, DD_TRACE_DBM_PROPAGATION_FULL);
    } else {
        return false;
    }

    return true;
}

static bool dd_parse_sampling_rules_format(zai_str value, zval *decoded_value, bool persistent) {
    UNUSED(persistent);
    if (zai_str_eq_ci_cstr(value, "regex")) {
        ZVAL_LONG(decoded_value, DD_TRACE_SAMPLING_RULES_FORMAT_REGEX);
    } else if (zai_str_eq_ci_cstr(value, "glob")) {
        ZVAL_LONG(decoded_value, DD_TRACE_SAMPLING_RULES_FORMAT_GLOB);
    } else {
        return false;
    }

    return true;
}

static bool dd_parse_tags(zai_str value, zval *decoded_value, bool persistent) {
    ZVAL_ARR(decoded_value, pemalloc(sizeof(HashTable), persistent));
    zend_hash_init(Z_ARR_P(decoded_value), 8, NULL, persistent ? ZVAL_INTERNAL_PTR_DTOR : ZVAL_PTR_DTOR, persistent);

    if (value.len == 0) {
        return true;
    }

    const char *str = value.ptr;
    const char *end = str + value.len;
    const char *current = str;

    // Determine separator - prefer comma if present, otherwise use space
    char sep = memchr(str, ',', value.len) ? ',': ' ';

    while (current < end) {
        // Skip leading whitespace
        while (current < end && *current == ' ') current++;
        if (current >= end) break;
        // Find next separator, this will be the end of the tag
        const char *tag_end = memchr(current, sep, end - current);
        if (!tag_end) {
            tag_end = end;
        }
        size_t tag_len = tag_end - current;
        if (tag_len == 0) {
            // If the first character is a separator, move to the next character
            ++current;
            continue;
        }
        // Prepare key and value
        // Initialize key to be the entire tag and value to be empty
        const char *key_start = current;
        const char *key_end = tag_end;
        const char *val_start = "";
        size_t key_len = tag_len;
        size_t val_len = 0;
        // If the tag has a colon, use the index of the colon to split the tag into key and value
        const char *colon = memchr(current, ':', tag_len);
        if (colon) {
            // Tag has a colon, use the index of the colon to  split into key and value
            key_end = colon - 1;
            val_start = colon + 1;
            key_len = key_end - key_start + 1;
            val_len = tag_end - val_start;
        }
        // Strip whitespace from key
        while (key_start < key_end && *key_start == ' ') key_start++;
        while (key_end > key_start && *key_end == ' ') key_end--;
        key_len = key_end - key_start + 1;
        // Strip whitespace from value (if it is not empty)
        if (val_len > 0) {
            while (val_start < tag_end && *val_start == ' ') val_start++;
            const char *val_end = tag_end - 1;
            while (val_end > val_start && *val_end == ' ') val_end--;
            val_len = val_end - val_start + 1;
        }
        // Only add if key is non-empty (value can be empty)
        if (key_len > 0) {
            zval val;
            ZVAL_STR(&val, zend_string_init(val_start, val_len, persistent));
            zend_hash_str_update(Z_ARRVAL_P(decoded_value), key_start, key_len, &val);
        }
        // Move to the start of the next tag
        current = tag_end + 1;
    }

    return true;
}

#define INI_CHANGE_DYNAMIC_CONFIG(name, config) \
    static bool ddtrace_alter_##name(zval *old_value, zval *new_value, zend_string *new_str) { \
        UNUSED(old_value, new_value); \
        if (!DDTRACE_G(remote_config_state)) {  \
            return true; \
        } \
        return ddog_remote_config_alter_dynamic_config(DDTRACE_G(remote_config_state), DDOG_CHARSLICE_C(config), dd_zend_string_to_CharSlice(new_str)); \
    }

INI_CHANGE_DYNAMIC_CONFIG(DD_TRACE_HEADER_TAGS, "datadog.trace.header_tags")
INI_CHANGE_DYNAMIC_CONFIG(DD_TRACE_SAMPLE_RATE, "datadog.trace.sample_rate")
INI_CHANGE_DYNAMIC_CONFIG(DD_TRACE_LOGS_ENABLED, "datadog.logs_injection")

#define CALIAS_EXPAND(name) {.ptr = name, .len = sizeof(name) - 1},
#define EXPAND_FIRST(arg, ...) arg
#define EXPAND_CALL(macro, args) macro args // I hate the "traditional" MSVC preprocessor
#define EXPAND_IDENTITY(...) __VA_ARGS__

#ifndef _WIN32
// Allow for partially defined struct initialization here
#pragma GCC diagnostic ignored "-Wmissing-field-initializers"
#else
#define CONFIG(...)
#define CALIASES(...) ({APPLY_N(CALIAS_EXPAND, ##__VA_ARGS__)})
#define CALIAS(type, name, default, aliases, ...) const zai_str dd_config_aliases_##name[] = EXPAND_CALL(EXPAND_IDENTITY, EXPAND_FIRST(aliases));
DD_CONFIGURATION
#undef CALIAS
#undef CONFIG
#endif

#define CUSTOM(...) CUSTOM
#define CONFIG(type, name, ...) EXPAND_CALL(ZAI_CONFIG_ENTRY, (DDTRACE_CONFIG_##name, name, type, __VA_ARGS__)),
#ifndef _WIN32
#define CALIASES(...) ((zai_str[]){APPLY_N(CALIAS_EXPAND, ##__VA_ARGS__)})
#define CALIAS(type, name, ...) ZAI_CONFIG_ALIASED_ENTRY(DDTRACE_CONFIG_##name, name, type, __VA_ARGS__),
#else
#define CALIAS(type, name, default, aliases, ...) ZAI_CONFIG_ALIASED_ENTRY(DDTRACE_CONFIG_##name, name, type, default, dd_config_aliases_##name, ##__VA_ARGS__),
#endif
static zai_config_entry config_entries[] = {DD_CONFIGURATION};
#undef CALIAS
#undef CONFIG

bool runtime_config_first_init = false;

static char dd_tolower_ascii(char c) { return c >= 'A' && c <= 'Z' ? c - ('A' - 'a') : c; }

#if defined(_WIN32) && PHP_VERSION_ID < 80000 && !defined(restrict)
#define restrict
#endif
static void dd_copy_tolower(char *restrict dst, const char *restrict src) {
    while (*src) {
        *(dst++) = dd_tolower_ascii(*(src++));
    }
}

static void dd_ini_env_to_ini_name(const zai_str env_name, zai_config_name *ini_name) {
    if (env_name.len + DD_TO_DATADOG_INC >= ZAI_CONFIG_NAME_BUFSIZ) {
        assert(false && "Expanded env name length is larger than the INI name buffer");
        return;
    }

    if (env_name.ptr == strstr(env_name.ptr, "DD_")) {
        dd_copy_tolower(ini_name->ptr + DD_TO_DATADOG_INC, env_name.ptr);
        memcpy(ini_name->ptr, "datadog.", sizeof("datadog.") - 1);
        ini_name->len = env_name.len + DD_TO_DATADOG_INC;

        if (env_name.ptr == strstr(env_name.ptr, "DD_TRACE_")) {
            ini_name->ptr[sizeof("datadog.trace") - 1] = '.';
        } else if (env_name.ptr == strstr(env_name.ptr, "DD_APPSEC_")) {
            ini_name->ptr[sizeof("datadog.appsec") - 1] = '.';
        } else if (env_name.ptr == strstr(env_name.ptr, "DD_DYNAMIC_INSTRUMENTATION_")) {
            ini_name->ptr[sizeof("datadog.dynamic_instrumentation") - 1] = '.';
        }
    } else {
        ini_name->len = 0;
        assert(false && "Unexpected env var name: missing 'DD_' prefix");
    }

    ini_name->ptr[ini_name->len] = '\0';
}

bool ddtrace_config_minit(int module_number) {
    if (ddtrace_active_sapi == DATADOG_PHP_SAPI_CLI) {
        config_entries[DDTRACE_CONFIG_DD_TRACE_AUTO_FLUSH_ENABLED].default_encoded_value = (zai_str) ZAI_STR_FROM_CSTR("true");
    }

#ifndef _WIN32
    // Background sender does not send a Content-Length header, but sidecar does. Force-enable it thus, as the background sender does not work at all.
    if (getenv("AWS_LAMBDA_FUNCTION_NAME")) {
        config_entries[DDTRACE_CONFIG_DD_TRACE_SIDECAR_TRACE_SENDER].default_encoded_value = (zai_str) ZAI_STR_FROM_CSTR("true");
    }
#endif

    if (!zai_config_minit(config_entries, (sizeof config_entries / sizeof *config_entries), dd_ini_env_to_ini_name,
                          module_number)) {
        ddtrace_log_ginit();
        LOG(ERROR, "Unable to load configuration; likely due to json symbols failing to resolve.");
        return false;
    }
    // We immediately initialize inis at MINIT, so that we can use a select few values already at minit.
    // Note that we are not calling zai_config_rinit(), i.e. the get_...() functions will not work.
    // This is intentional, so that places wishing to use values pre-RINIT do have to explicitly opt in by using the
    // arduous way of accessing the decoded_value directly from zai_config_memoized_entries.
    zai_config_first_time_rinit(false);

    ddtrace_alter_dd_trace_debug(NULL, &zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_DEBUG].decoded_value, NULL);
    ddtrace_log_ginit();
    return true;
}

void ddtrace_config_first_rinit() {
    zend_ini_entry *internal_functions_ini =
        zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_TRACED_INTERNAL_FUNCTIONS].ini_entries[0];
    zend_string *internal_functions_old = zend_string_copy(
        internal_functions_ini->modified ? internal_functions_ini->orig_value : internal_functions_ini->value);

    zai_config_first_time_rinit(true);
    zai_config_rinit();

    zend_string *internal_functions_new =
        internal_functions_ini->modified ? internal_functions_ini->orig_value : internal_functions_ini->value;

    if (!zend_string_equals(internal_functions_old, internal_functions_new)) {
        LOG(ERROR,
            "Received DD_TRACE_TRACED_INTERNAL_FUNCTIONS configuration via environment variable."
            "This specific value cannot be set via environment variable for this SAPI. This configuration "
            "needs to be available early in startup. To provide this value, set an ini value with the key "
            "datadog.trace.traced_internal_functions in your system PHP ini file. System value: %s. "
            "Environment variable value: %s",
            ZSTR_VAL(internal_functions_old), ZSTR_VAL(internal_functions_new));
    }
    zend_string_release(internal_functions_old);

    if (!get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED() && get_DD_APPSEC_SCA_ENABLED()) {
        LOG(WARN, "DD_APPSEC_SCA_ENABLED requires DD_INSTRUMENTATION_TELEMETRY_ENABLED in order to work");
    }

    runtime_config_first_init = true;
}

// note: only call this if get_DD_TRACE_ENABLED() returns true
bool ddtrace_config_integration_enabled(ddtrace_integration_name integration_name) {
    ddtrace_integration *integration = &ddtrace_integrations[integration_name];

    return integration->is_enabled();
}

void ddtrace_change_default_ini(ddtrace_config_id config_id, zai_str str) {
    zai_config_memoized_entry *memoized = &zai_config_memoized_entries[config_id];
    zend_ini_entry *entry = memoized->ini_entries[0];
    zend_string_release(entry->value);
    entry->value = zend_string_init(str.ptr, str.len, 1);
    if (entry->modified) {
        entry->modified = false;
        zend_string_release(entry->orig_value);
    }
#if ZTS
    zend_ini_entry *runtime_entry = zend_hash_find_ptr(EG(ini_directives), entry->name);
    if (runtime_entry != entry) {
        zend_string_release(runtime_entry->value);
        runtime_entry->value = zend_string_copy(entry->value);
        if (runtime_entry->modified) {
            runtime_entry->modified = false;
            zend_string_release(runtime_entry->orig_value);
        }
    }
#endif
    memoized->default_encoded_value = str;
    memoized->name_index = -1;

    zval decoded;
    ZVAL_UNDEF(&decoded);
    if (zai_config_decode_value(str, memoized->type, memoized->parser, &decoded, 1)) {
        zai_json_dtor_pzval(&memoized->decoded_value);
        ZVAL_COPY_VALUE(&memoized->decoded_value, &decoded);
    }
}
