#include "startup_logging.h"

#include <SAPI.h>
#include <Zend/zend_API.h>
#include <Zend/zend_smart_str.h>
#include <curl/curl.h>
#include <php.h>
#include <stdbool.h>
#include <time.h>

#include <ext/standard/info.h>

#include "../version.h"
#include "coms.h"
#include "configuration.h"
#include "excluded_modules.h"
#include "integrations/integrations.h"
#include "logging.h"

#define ISO_8601_LEN (20 + 1)  // +1 for terminating null-character

static void _dd_get_time(char *buf) {
    time_t now = time(NULL);
    struct tm *tm = gmtime(&now);
    if (tm) {
        strftime(buf, ISO_8601_LEN, "%Y-%m-%dT%TZ", tm);
    } else {
        ddtrace_log_debug("Error getting time");
    }
}

static void _dd_add_assoc_string(HashTable *ht, const char *name, size_t name_len, const char *str) {
    zval value;
    size_t str_len = str ? strlen(str) : 0;
    if (str_len > 0) {
        ZVAL_STRINGL(&value, str, str_len);
    } else {
        ZVAL_NULL(&value);
    }
    zend_hash_str_update(ht, name, name_len, &value);
}

static void _dd_add_assoc_string_free(HashTable *ht, const char *name, size_t name_len, char *str) {
    _dd_add_assoc_string(ht, name, name_len, (const char *)str);
    free(str);
}

static void _dd_add_assoc_zstring(HashTable *ht, const char *name, size_t name_len, zend_string *str) {
    zval value;
    ZVAL_STR(&value, str);
    zend_hash_str_update(ht, name, name_len, &value);
}

static void _dd_add_assoc_bool(HashTable *ht, const char *name, size_t name_len, bool v) {
    zval value;
    ZVAL_BOOL(&value, v);
    zend_hash_str_update(ht, name, name_len, &value);
}

static void _dd_add_assoc_double(HashTable *ht, const char *name, size_t name_len, double num) {
    zval value;
    ZVAL_DOUBLE(&value, num);
    zend_hash_str_update(ht, name, name_len, &value);
}

static char *_dd_get_ini(const char *name, size_t name_len) { return zend_ini_string((char *)name, name_len, 0); }

static bool _dd_ini_is_set(const char *name, size_t name_len) {
    const char *ini = _dd_get_ini(name, name_len);
    return ini && (strcmp(ini, "") != 0);
}

// Modified version of zend_ini_parse_bool()
// @see https://github.com/php/php-src/blob/28b4761/Zend/zend_ini.c#L493-L502
static bool _dd_parse_bool(const char *name, size_t name_len) {
    const char *ini = _dd_get_ini(name, name_len);
    size_t ini_len = strlen(ini);
    if ((ini_len == 4 && strcasecmp(ini, "true") == 0) || (ini_len == 3 && strcasecmp(ini, "yes") == 0) ||
        (ini_len == 2 && strcasecmp(ini, "on") == 0)) {
        return 1;
    } else {
        return atoi(ini) != 0;
    }
}

static void _dd_get_startup_config(HashTable *ht) {
    // Cross-language tracer values
    char time[ISO_8601_LEN];
    _dd_get_time(time);
    _dd_add_assoc_string(ht, ZEND_STRL("date"), time);

    _dd_add_assoc_zstring(ht, ZEND_STRL("os_name"), php_get_uname('a'));
    _dd_add_assoc_zstring(ht, ZEND_STRL("os_version"), php_get_uname('r'));
    _dd_add_assoc_string(ht, ZEND_STRL("version"), PHP_DDTRACE_VERSION);
    _dd_add_assoc_string(ht, ZEND_STRL("lang"), "php");
    _dd_add_assoc_string(ht, ZEND_STRL("lang_version"), PHP_VERSION);
    _dd_add_assoc_string_free(ht, ZEND_STRL("env"), get_dd_env());
    _dd_add_assoc_bool(ht, ZEND_STRL("enabled"), !_dd_parse_bool(ZEND_STRL("ddtrace.disable")));
    _dd_add_assoc_string_free(ht, ZEND_STRL("service"), get_dd_service());
    _dd_add_assoc_bool(ht, ZEND_STRL("enabled_cli"), get_dd_trace_cli_enabled());

    _dd_add_assoc_string_free(ht, ZEND_STRL("agent_url"), ddtrace_agent_url());

    _dd_add_assoc_bool(ht, ZEND_STRL("debug"), get_dd_trace_debug());
    _dd_add_assoc_bool(ht, ZEND_STRL("analytics_enabled"), get_dd_trace_analytics_enabled());
    _dd_add_assoc_double(ht, ZEND_STRL("sample_rate"), get_dd_trace_sample_rate());
    _dd_add_assoc_string_free(ht, ZEND_STRL("sampling_rules"), get_dd_trace_sampling_rules());
    // TODO Add integration-specific config: integration_<integration>_analytics_enabled,
    // integration_<integration>_sample_rate, integrations_loaded
    _dd_add_assoc_string_free(ht, ZEND_STRL("tags"), get_dd_tags());
    _dd_add_assoc_string_free(ht, ZEND_STRL("service_mapping"), get_dd_service_mapping());
    // "log_injection_enabled" N/A for PHP
    // "runtime_metrics_enabled" N/A for PHP
    // "configuration_file" N/A for PHP
    // "vm" N/A for PHP
    // "partial_flushing_enabled" N/A for PHP
    _dd_add_assoc_bool(ht, ZEND_STRL("distributed_tracing_enabled"), get_dd_distributed_tracing());
    _dd_add_assoc_bool(ht, ZEND_STRL("priority_sampling_enabled"), get_dd_priority_sampling());
    // "logs_correlation_enabled" N/A for PHP
    // "profiling_enabled" N/A for PHP
    _dd_add_assoc_string_free(ht, ZEND_STRL("dd_version"), get_dd_version());
    // "health_metrics_enabled" N/A for PHP
    _dd_add_assoc_zstring(ht, ZEND_STRL("architecture"), php_get_uname('m'));

    // PHP-specific values
    _dd_add_assoc_string(ht, ZEND_STRL("sapi"), sapi_module.name);
    _dd_add_assoc_string(ht, ZEND_STRL("ddtrace.request_init_hook"),
                         _dd_get_ini(ZEND_STRL("ddtrace.request_init_hook")));
    _dd_add_assoc_bool(ht, ZEND_STRL("open_basedir_configured"), _dd_ini_is_set(ZEND_STRL("open_basedir")));
    _dd_add_assoc_string_free(ht, ZEND_STRL("uri_fragment_regex"), get_dd_trace_resource_uri_fragment_regex());
    _dd_add_assoc_string_free(ht, ZEND_STRL("uri_mapping_incoming"), get_dd_trace_resource_uri_mapping_incoming());
    _dd_add_assoc_string_free(ht, ZEND_STRL("uri_mapping_outgoing"), get_dd_trace_resource_uri_mapping_outgoing());
    _dd_add_assoc_bool(ht, ZEND_STRL("auto_flush_enabled"), get_dd_trace_auto_flush_enabled());
    _dd_add_assoc_bool(ht, ZEND_STRL("generate_root_span"), get_dd_trace_generate_root_span());
    _dd_add_assoc_bool(ht, ZEND_STRL("http_client_split_by_domain"), get_dd_trace_http_client_split_by_domain());
    _dd_add_assoc_bool(ht, ZEND_STRL("measure_compile_time"), get_dd_trace_measure_compile_time());
    _dd_add_assoc_bool(ht, ZEND_STRL("report_hostname_on_root_span"), get_dd_trace_report_hostname());
    _dd_add_assoc_string_free(ht, ZEND_STRL("traced_internal_functions"), get_dd_trace_traced_internal_functions());
    _dd_add_assoc_bool(ht, ZEND_STRL("auto_prepend_file_configured"), _dd_ini_is_set(ZEND_STRL("auto_prepend_file")));
    _dd_add_assoc_string_free(ht, ZEND_STRL("integrations_disabled"), get_dd_integrations_disabled());
    _dd_add_assoc_bool(ht, ZEND_STRL("enabled_from_env"), get_dd_trace_enabled());
    _dd_add_assoc_string(ht, ZEND_STRL("opcache.file_cache"), _dd_get_ini(ZEND_STRL("opcache.file_cache")));
}

static size_t _dd_curl_write_noop(void *ptr, size_t size, size_t nmemb, void *userdata) {
    UNUSED(ptr, userdata);
    return size * nmemb;
}

static size_t _dd_check_for_agent_error(char *error, bool quick) {
    CURL *curl = curl_easy_init();
    ddtrace_curl_set_hostname(curl);
    if (quick) {
        curl_easy_setopt(curl, CURLOPT_TIMEOUT_MS, DDTRACE_AGENT_QUICK_TIMEOUT);
        curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT_MS, DDTRACE_AGENT_QUICK_CONNECT_TIMEOUT);
    } else {
        ddtrace_curl_set_timeout(curl);
        ddtrace_curl_set_connect_timeout(curl);
    }

    struct curl_slist *headers = NULL;
    headers = curl_slist_append(headers, "X-Datadog-Diagnostic-Check: 1");
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);

    const char *body = "[]";
    curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE, (long)strlen(body));
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, body);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, _dd_curl_write_noop);
    curl_easy_setopt(curl, CURLOPT_ERRORBUFFER, error);
    error[0] = 0;

    CURLcode res = curl_easy_perform(curl);

    size_t error_len = strlen(error);
    if (res != CURLE_OK && !error_len) {
        strcpy(error, curl_easy_strerror(res));
        error_len = strlen(error);
    }
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);
    return error_len;
}

static bool _dd_file_exists(const char *file) {
    if (!strlen(file)) {
        return false;
    }
    return (VCWD_ACCESS(file, R_OK) == 0);
}

static bool _dd_open_basedir_allowed(const char *file) { return (php_check_open_basedir_ex(file, 0) != -1); }

#define DD_ENV_DEPRECATION_MESSAGE "'%s=%s' is deprecated, use %s instead."

static void _dd_check_for_deprecated_env(HashTable *ht, const char *old_name, size_t old_name_len, const char *new_name,
                                         size_t new_name_len) {
    ddtrace_string val = ddtrace_string_getenv((char *)old_name, old_name_len);
    if (val.len) {
        size_t messsage_len = sizeof(DD_ENV_DEPRECATION_MESSAGE) + old_name_len + val.len + new_name_len;
        zend_string *message = zend_string_alloc(messsage_len, 0);
        int actual_len =
            snprintf(ZSTR_VAL(message), messsage_len, DD_ENV_DEPRECATION_MESSAGE, old_name, val.ptr, new_name);

        if (actual_len > 0) {
            ZSTR_VAL(message)[actual_len] = '\0';
            ZSTR_LEN(message) = actual_len;
            _dd_add_assoc_zstring(ht, old_name, old_name_len, message);
        } else {
            zend_string_free(message);
        }
    }
    if (val.ptr) {
        efree(val.ptr);
    }
}

static void _dd_check_for_deprecated_integration_envs(HashTable *ht, ddtrace_integration *integration) {
    char old[DDTRACE_LONGEST_INTEGRATION_ENV_LEN + 1];
    size_t old_len;
    char new[DDTRACE_LONGEST_INTEGRATION_ENV_LEN + 1];
    size_t new_len;

    old_len = ddtrace_config_integration_env_name(old, "DD_", integration, "_ANALYTICS_ENABLED");
    new_len = ddtrace_config_integration_env_name(new, "DD_TRACE_", integration, "_ANALYTICS_ENABLED");
    _dd_check_for_deprecated_env(ht, old, old_len, new, new_len);

    old_len = ddtrace_config_integration_env_name(old, "DD_", integration, "_ANALYTICS_SAMPLE_RATE");
    new_len = ddtrace_config_integration_env_name(new, "DD_TRACE_", integration, "_ANALYTICS_SAMPLE_RATE");
    _dd_check_for_deprecated_env(ht, old, old_len, new, new_len);
}

static void dd_check_for_excluded_module(HashTable *ht, zend_module_entry *module) {
    char error[DDTRACE_EXCLUDED_MODULES_ERROR_MAX_LEN + 1];

    if (module && module->name && module->version && ddtrace_is_excluded_module(module, error)) {
        char key[64];
        size_t key_len;

        key_len = snprintf(key, sizeof(key) - 1, "incompatible module %s", module->name);
        _dd_add_assoc_string(ht, key, key_len, error);
    }
}

/* Supported zval types for diagnostics: string, bool, null
 * To support other types, update:
 *     - ddtrace.c:_dd_info_diagnostics_table(); PHP info output
 *     - _dd_print_values_to_log(); Debug log output
 */
void ddtrace_startup_diagnostics(HashTable *ht, bool quick) {
    // Cross-language tracer values
    char agent_error[CURL_ERROR_SIZE];
    if (_dd_check_for_agent_error(agent_error, quick)) {
        _dd_add_assoc_string(ht, ZEND_STRL("agent_error"), agent_error);
    }
    //_dd_add_assoc_string(ht, ZEND_STRL("sampling_rules_error"), ""); // TODO Parse at C level
    //_dd_add_assoc_string(ht, ZEND_STRL("service_mapping_error"), ""); // TODO Parse at C level

    // PHP-specific values
    const char *rih = _dd_get_ini(ZEND_STRL("ddtrace.request_init_hook"));
    bool rih_exists = _dd_file_exists(rih);
    if (!rih_exists) {
        _dd_add_assoc_bool(ht, ZEND_STRL("ddtrace.request_init_hook_reachable"), rih_exists);
    } else {
        bool rih_allowed = _dd_open_basedir_allowed(rih);
        if (!rih_allowed) {
            _dd_add_assoc_bool(ht, ZEND_STRL("open_basedir_init_hook_allowed"), rih_allowed);
        }
    }

    bool container_allowed = _dd_open_basedir_allowed("/proc/self/cgroup");
    if (!container_allowed) {
        _dd_add_assoc_bool(ht, ZEND_STRL("open_basedir_container_tagging_allowed"), container_allowed);
    }

    //_dd_add_assoc_string(ht, ZEND_STRL("uri_fragment_regex_error"), ""); // TODO Parse at C level
    //_dd_add_assoc_string(ht, ZEND_STRL("uri_mapping_incoming_error"), ""); // TODO Parse at C level
    //_dd_add_assoc_string(ht, ZEND_STRL("uri_mapping_outgoing_error"), ""); // TODO Parse at C level

    // opcache.file_cache was added in PHP 7.0
    const char *opcache_file_cache = _dd_get_ini(ZEND_STRL("opcache.file_cache"));
    if (opcache_file_cache && opcache_file_cache[0]) {
        _dd_add_assoc_string(ht, ZEND_STRL("opcache_file_cache_set"),
                             "The opcache.file_cache INI setting is set. This setting can cause unexpected behavior "
                             "with the PHP tracer due to a bug in OPcache: https://bugs.php.net/bug.php?id=79825");
    }

    _dd_check_for_deprecated_env(ht, ZEND_STRL("DD_SERVICE_NAME"), ZEND_STRL("DD_SERVICE"));
    _dd_check_for_deprecated_env(ht, ZEND_STRL("DD_TRACE_APP_NAME"), ZEND_STRL("DD_SERVICE"));
    _dd_check_for_deprecated_env(ht, ZEND_STRL("ddtrace_app_name"), ZEND_STRL("DD_SERVICE"));

    _dd_check_for_deprecated_env(ht, ZEND_STRL("DD_TRACE_GLOBAL_TAGS"), ZEND_STRL("DD_TAGS"));
    _dd_check_for_deprecated_env(
        ht, ZEND_STRL("DD_TRACE_RESOURCE_URI_MAPPING"),
        ZEND_STRL("DD_TRACE_RESOURCE_URI_MAPPING_INCOMING and DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING"));
    _dd_check_for_deprecated_env(ht, ZEND_STRL("DD_SAMPLING_RATE"), ZEND_STRL("DD_TRACE_SAMPLE_RATE"));

    _dd_check_for_deprecated_env(ht, ZEND_STRL("DD_INTEGRATIONS_DISABLED"),
                                 ZEND_STRL("DD_TRACE_[INTEGRATION]_ENABLED=false"));

    for (size_t i = 0; i < ddtrace_integrations_len; ++i) {
        _dd_check_for_deprecated_integration_envs(ht, &ddtrace_integrations[i]);
    }

    if (ddtrace_has_excluded_module == true) {
        zend_module_entry *module;
        ZEND_HASH_FOREACH_PTR(&module_registry, module) { dd_check_for_excluded_module(ht, module); }
        ZEND_HASH_FOREACH_END();
    }
}

static void _dd_json_escape_string(smart_str *buf, const char *val, size_t len) {
    // Empty strings are treated as null
    if (len == 0) {
        smart_str_appendl(buf, "null", 4);
        return;
    }

    smart_str_alloc(buf, len + 2, 0);  // +2 for quotes
    smart_str_appendc(buf, '"');

    unsigned long pos = 0;
    unsigned char c;
    while (pos < len) {
        c = val[pos++];
        // @see http://www.ecma-international.org/publications/files/ECMA-ST/ECMA-404.pdf
        switch (c) {
            case '"':
                smart_str_appendl(buf, "\\\"", 2);
                break;
            case '\\':
                smart_str_appendl(buf, "\\\\", 2);
                break;
            // Ignoring case '/' as JSON will not be used in scripting contexts
            case '\b':
                smart_str_appendl(buf, "\\b", 2);
                break;
            case '\f':
                smart_str_appendl(buf, "\\f", 2);
                break;
            case '\n':
                smart_str_appendl(buf, "\\n", 2);
                break;
            case '\r':
                smart_str_appendl(buf, "\\r", 2);
                break;
            case '\t':
                smart_str_appendl(buf, "\\t", 2);
                break;
            default:
                smart_str_appendc(buf, c);
                break;
        }
    }

    smart_str_appendc(buf, '"');
}

static void _dd_serialize_json(HashTable *ht, smart_str *buf) {
    zend_string *key;
    zval *val;
    bool first_elem = true;
    smart_str_appendc(buf, '{');
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(ht, key, val) {
        if (first_elem) {
            first_elem = false;
            smart_str_appendc(buf, '"');
        } else {
            smart_str_appendl(buf, ",\"", 2);
        }
        smart_str_appendl(buf, ZSTR_VAL(key), ZSTR_LEN(key));
        smart_str_appendl(buf, "\":", 2);
        switch (Z_TYPE_P(val)) {
            case IS_STRING:
                _dd_json_escape_string(buf, Z_STRVAL_P(val), Z_STRLEN_P(val));
                break;
            case IS_NULL:
                smart_str_appendl(buf, "null", 4);
                break;
            case IS_DOUBLE: {
                char *tmp;
                int l = spprintf(&tmp, 0, "%f", Z_DVAL_P(val));
                smart_str_appendl(buf, tmp, l);
                efree(tmp);
            } break;
            case IS_LONG:
                smart_str_append_long(buf, Z_LVAL_P(val));
                break;
            case IS_TRUE:
                smart_str_appendl(buf, "true", 4);
                break;
            case IS_FALSE:
                smart_str_appendl(buf, "false", 5);
                break;
            default:
                smart_str_appendl(buf, "\"{unknown type}\"", 16);
                break;
        }
    }
    ZEND_HASH_FOREACH_END();
    smart_str_appendc(buf, '}');
    smart_str_0(buf);
}

void ddtrace_startup_logging_json(smart_str *buf) {
    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, DDTRACE_STARTUP_STAT_COUNT, NULL, ZVAL_PTR_DTOR, 0);

    _dd_get_startup_config(ht);
    ddtrace_startup_diagnostics(ht, false);

    _dd_serialize_json(ht, buf);

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}

static void _dd_print_values_to_log(HashTable *ht) {
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(ht, key, val) {
        switch (Z_TYPE_P(val)) {
            case IS_STRING:
                ddtrace_log_errf("DATADOG TRACER DIAGNOSTICS - %s: %s", ZSTR_VAL(key), Z_STRVAL_P(val));
                break;
            case IS_NULL:
                ddtrace_log_errf("DATADOG TRACER DIAGNOSTICS - %s: NULL", ZSTR_VAL(key));
                break;
            case IS_TRUE:
                ddtrace_log_errf("DATADOG TRACER DIAGNOSTICS - %s: true", ZSTR_VAL(key));
                break;
            case IS_FALSE:
                ddtrace_log_errf("DATADOG TRACER DIAGNOSTICS - %s: false", ZSTR_VAL(key));
                break;
            default:
                ddtrace_log_errf("DATADOG TRACER DIAGNOSTICS - %s: {unknown type}", ZSTR_VAL(key));
                break;
        }
    }
    ZEND_HASH_FOREACH_END();
}

// Only show startup logs on the first request
void ddtrace_startup_logging_first_rinit(void) {
    if (!get_dd_trace_debug() || !get_dd_trace_startup_logs() || strcmp("cli", sapi_module.name) == 0) {
        return;
    }

    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, DDTRACE_STARTUP_STAT_COUNT, NULL, ZVAL_PTR_DTOR, 0);

    ddtrace_startup_diagnostics(ht, true);
    _dd_print_values_to_log(ht);
    _dd_get_startup_config(ht);

    smart_str buf = {0};
    _dd_serialize_json(ht, &buf);
    ddtrace_log_errf("DATADOG TRACER CONFIGURATION - %s", ZSTR_VAL(buf.s));
    ddtrace_log_errf(
        "For additional diagnostic checks such as Agent connectivity, see the 'ddtrace' section of a phpinfo() "
        "page. Alternatively set DD_TRACE_DEBUG=1 to add diagnostic checks to the error logs on the first request "
        "of a new PHP process. Set DD_TRACE_STARTUP_LOGS=0 to disable this tracer configuration message.");
    smart_str_free(&buf);

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}
