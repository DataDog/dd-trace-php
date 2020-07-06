#include "startup_logging.h"

#include <SAPI.h>
#include <Zend/zend_API.h>
#include <Zend/zend_smart_str.h>
#include <curl/curl.h>
#include <php.h>
#include <stdbool.h>
#include <time.h>

#include <ext/standard/info.h>

#include "coms.h"
#include "configuration.h"
#include "logging.h"
#include "version.h"

#define ISO_8601_LEN (20 + 1)  // +1 for terminating null-character

// True global; only modify on MINIT/STARTUP
static time_t startup_time = -1;

static void _dd_get_startup_time(char *buf) {
    time_t now = (startup_time == -1) ? time(NULL) : startup_time;
    struct tm *tm = gmtime(&now);
    if (tm) {
        strftime(buf, ISO_8601_LEN, "%Y-%m-%dT%TZ", tm);
    } else {
        ddtrace_log_debug("Error getting startup time");
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

static void _dd_get_agent_url(char *buf) {
    char *host = get_dd_agent_host();
    sprintf(buf, "http://%s:%d", host, (int)get_dd_trace_agent_port());
    free(host);
}

static void _dd_get_startup_config(HashTable *ht) {
    // Cross-language tracer values
    char time[ISO_8601_LEN];
    _dd_get_startup_time(time);
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

    char agent_url[64];
    _dd_get_agent_url(agent_url);
    _dd_add_assoc_string(ht, ZEND_STRL("agent_url"), agent_url);

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

static size_t _dd_check_for_agent_error(char *error) {
    CURL *curl = curl_easy_init();
    ddtrace_curl_set_hostname(curl);
    ddtrace_curl_set_timeout(curl);
    ddtrace_curl_set_connect_timeout(curl);

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

/* Supported zval types for diagnostics: string, bool, null
 * To support other types, update:
 *     - ddtrace.c:_dd_info_diagnostics_table(); PHP info output
 *     - _dd_print_values_to_log(); Debug log output
 */
void ddtrace_startup_diagnostics(HashTable *ht) {
    // Cross-language tracer values
    char agent_error[CURL_ERROR_SIZE];
    if (_dd_check_for_agent_error(agent_error)) {
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

    char *old_service = get_dd_service_name();
    if (strcmp(old_service, "") != 0) {
        _dd_add_assoc_string(ht, ZEND_STRL("service_name"), old_service);
        _dd_add_assoc_string(ht, ZEND_STRL("service_name_error"),
                             "Usage of DD_SERVICE_NAME is deprecated, use DD_SERVICE instead.");
    }
    free(old_service);

    char *old_tags = get_dd_trace_global_tags();
    if (strcmp(old_tags, "") != 0) {
        _dd_add_assoc_string(ht, ZEND_STRL("global_tags"), old_tags);
        _dd_add_assoc_string(ht, ZEND_STRL("global_tags_error"),
                             "Usage of DD_TRACE_GLOBAL_TAGS is deprecated, use DD_TAGS instead.");
    }
    free(old_tags);
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
    ddtrace_startup_diagnostics(ht);

    _dd_serialize_json(ht, buf);

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}

static void _dd_print_values_to_log(HashTable *ht, char *time) {
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(ht, key, val) {
        switch (Z_TYPE_P(val)) {
            case IS_STRING:
                ddtrace_log_errf("[%s] DATADOG TRACER DIAGNOSTICS - %s: %s", time, ZSTR_VAL(key), Z_STRVAL_P(val));
                break;
            case IS_NULL:
                ddtrace_log_errf("[%s] DATADOG TRACER DIAGNOSTICS - %s: NULL", time, ZSTR_VAL(key));
                break;
            case IS_TRUE:
                ddtrace_log_errf("[%s] DATADOG TRACER DIAGNOSTICS - %s: true", time, ZSTR_VAL(key));
                break;
            case IS_FALSE:
                ddtrace_log_errf("[%s] DATADOG TRACER DIAGNOSTICS - %s: false", time, ZSTR_VAL(key));
                break;
            default:
                ddtrace_log_errf("[%s] DATADOG TRACER DIAGNOSTICS - %s: {unknown type}", time, ZSTR_VAL(key));
                break;
        }
    }
    ZEND_HASH_FOREACH_END();
}

void ddtrace_startup_logging_startup(void) {
    startup_time = time(NULL);
    if (!get_dd_trace_startup_logs() || strcmp("cli", sapi_module.name) == 0) {
        return;
    }

    char time[ISO_8601_LEN];
    _dd_get_startup_time(time);

    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, DDTRACE_STARTUP_STAT_COUNT, NULL, ZVAL_PTR_DTOR, 0);

    ddtrace_startup_diagnostics(ht);
    if (get_dd_trace_debug()) {
        _dd_print_values_to_log(ht, time);
    }
    _dd_get_startup_config(ht);

    smart_str buf = {0};
    _dd_serialize_json(ht, &buf);
    ddtrace_log_errf("[%s] DATADOG TRACER CONFIGURATION - %s", time, ZSTR_VAL(buf.s));
    smart_str_free(&buf);

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}
