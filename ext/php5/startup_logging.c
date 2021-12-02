#include "startup_logging.h"

#include <SAPI.h>
#include <Zend/zend_API.h>
#include <curl/curl.h>
#include <php.h>
#include <stdbool.h>
#include <time.h>

#include <json/json.h>
#include <ext/standard/info.h>

#include "coms.h"
#include "configuration.h"
#include "excluded_modules.h"
#include "ext/version.h"
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
    zval *value;
    MAKE_STD_ZVAL(value);
    size_t str_len = str ? strlen(str) : 0;
    if (str_len > 0) {
        ZVAL_STRINGL(value, str, str_len, 1);
    } else {
        ZVAL_NULL(value);
    }
    zend_symtable_update(ht, name, (name_len + 1), (void **)&value, sizeof(zval *), NULL);
}

static void _dd_add_assoc_string_free(HashTable *ht, const char *name, size_t name_len, char *str) {
    _dd_add_assoc_string(ht, name, name_len, (const char *)str);
    free(str);
}

static void _dd_add_assoc_array(HashTable *ht, const char *name, size_t name_len, HashTable *array) {
    zval *value;
    MAKE_STD_ZVAL(value);
    Z_ARRVAL_P(value) = array;
    Z_TYPE_P(value) = IS_ARRAY;
    zend_symtable_update(ht, name, (name_len + 1), (void **)&value, sizeof(zval *), NULL);
}

static void _dd_add_assoc_bool(HashTable *ht, const char *name, size_t name_len, bool v) {
    zval *value;
    MAKE_STD_ZVAL(value);
    ZVAL_BOOL(value, v);
    zend_symtable_update(ht, name, (name_len + 1), (void **)&value, sizeof(zval *), NULL);
}

static void _dd_add_assoc_double(HashTable *ht, const char *name, size_t name_len, double num) {
    zval *value;
    MAKE_STD_ZVAL(value);
    ZVAL_DOUBLE(value, num);
    zend_symtable_update(ht, name, (name_len + 1), (void **)&value, sizeof(zval *), NULL);
}

static char *_dd_get_ini(const char *name, size_t name_len) { return zend_ini_string((char *)name, (name_len + 1), 0); }

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

static HashTable *_dd_array_copy(HashTable *array) {
    zval zv;
    Z_ARRVAL(zv) = array;
    Z_TYPE(zv) = IS_ARRAY;
    zval_copy_ctor(&zv);
    return Z_ARRVAL(zv);
}

static char *_dd_implode_keys(HashTable *array) {
    smart_str imploded = {0};
    char *key;
    uint key_len;
    ulong num_key;
    HashPosition pos;
    zend_hash_internal_pointer_reset_ex(array, &pos);
    while (zend_hash_get_current_key_ex(array, &key, &key_len, &num_key, 0, &pos) == HASH_KEY_IS_STRING) {
        if (imploded.a != 0) {
            smart_str_appendc_ex(&imploded, ',', 1);
        }
        smart_str_appends_ex(&imploded, key, 1);
        zend_hash_move_forward_ex(array, &pos);
    }
    smart_str_0(&imploded);
    return imploded.c ? imploded.c : calloc(1, 1);
}

static void _dd_get_startup_config(HashTable *ht) {
    // Cross-language tracer values
    char time[ISO_8601_LEN];
    _dd_get_time(time);
    _dd_add_assoc_string(ht, ZEND_STRL("date"), time);

    char *os_name = php_get_uname('a');
    _dd_add_assoc_string(ht, ZEND_STRL("os_name"), os_name);
    efree(os_name);

    char *os_version = php_get_uname('r');
    _dd_add_assoc_string(ht, ZEND_STRL("os_version"), os_version);
    efree(os_version);

    _dd_add_assoc_string(ht, ZEND_STRL("version"), PHP_DDTRACE_VERSION);
    _dd_add_assoc_string(ht, ZEND_STRL("lang"), "php");
    _dd_add_assoc_string(ht, ZEND_STRL("lang_version"), PHP_VERSION);
    _dd_add_assoc_string(ht, ZEND_STRL("env"), get_DD_ENV().ptr);
    _dd_add_assoc_bool(ht, ZEND_STRL("enabled"), !_dd_parse_bool(ZEND_STRL("ddtrace.disable")));
    _dd_add_assoc_string(ht, ZEND_STRL("service"), get_DD_SERVICE().ptr);
    _dd_add_assoc_bool(ht, ZEND_STRL("enabled_cli"), get_DD_TRACE_CLI_ENABLED());

    _dd_add_assoc_string_free(ht, ZEND_STRL("agent_url"), ddtrace_agent_url());

    _dd_add_assoc_bool(ht, ZEND_STRL("debug"), get_DD_TRACE_DEBUG());
    _dd_add_assoc_bool(ht, ZEND_STRL("analytics_enabled"), get_DD_TRACE_ANALYTICS_ENABLED());
    _dd_add_assoc_double(ht, ZEND_STRL("sample_rate"), get_DD_TRACE_SAMPLE_RATE());
    _dd_add_assoc_array(ht, ZEND_STRL("sampling_rules"), _dd_array_copy(get_DD_TRACE_SAMPLING_RULES()));
    // TODO Add integration-specific config: integration_<integration>_analytics_enabled,
    // integration_<integration>_sample_rate, integrations_loaded
    _dd_add_assoc_array(ht, ZEND_STRL("tags"), _dd_array_copy(get_DD_TAGS()));
    _dd_add_assoc_array(ht, ZEND_STRL("service_mapping"), _dd_array_copy(get_DD_SERVICE_MAPPING()));
    // "log_injection_enabled" N/A for PHP
    // "runtime_metrics_enabled" N/A for PHP
    // "configuration_file" N/A for PHP
    // "vm" N/A for PHP
    // "partial_flushing_enabled" N/A for PHP
    _dd_add_assoc_bool(ht, ZEND_STRL("distributed_tracing_enabled"), get_DD_DISTRIBUTED_TRACING());
    _dd_add_assoc_bool(ht, ZEND_STRL("priority_sampling_enabled"), get_DD_PRIORITY_SAMPLING());
    // "logs_correlation_enabled" N/A for PHP
    // "profiling_enabled" N/A for PHP
    _dd_add_assoc_string(ht, ZEND_STRL("dd_version"), get_DD_VERSION().ptr);
    // "health_metrics_enabled" N/A for PHP

    char *architecture = php_get_uname('m');
    _dd_add_assoc_string(ht, ZEND_STRL("architecture"), architecture);
    efree(architecture);

    // PHP-specific values
    _dd_add_assoc_string(ht, ZEND_STRL("sapi"), sapi_module.name);
    _dd_add_assoc_string(ht, ZEND_STRL("datadog.trace.request_init_hook"), get_DD_TRACE_REQUEST_INIT_HOOK().ptr);
    _dd_add_assoc_bool(ht, ZEND_STRL("open_basedir_configured"), _dd_ini_is_set(ZEND_STRL("open_basedir")));
    _dd_add_assoc_string_free(ht, ZEND_STRL("uri_fragment_regex"),
                              _dd_implode_keys(get_DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX()));
    _dd_add_assoc_string_free(ht, ZEND_STRL("uri_mapping_incoming"),
                              _dd_implode_keys(get_DD_TRACE_RESOURCE_URI_MAPPING_INCOMING()));
    _dd_add_assoc_string_free(ht, ZEND_STRL("uri_mapping_outgoing"),
                              _dd_implode_keys(get_DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING()));
    _dd_add_assoc_bool(ht, ZEND_STRL("auto_flush_enabled"), get_DD_TRACE_AUTO_FLUSH_ENABLED());
    _dd_add_assoc_bool(ht, ZEND_STRL("generate_root_span"), get_DD_TRACE_GENERATE_ROOT_SPAN());
    _dd_add_assoc_bool(ht, ZEND_STRL("http_client_split_by_domain"), get_DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN());
    _dd_add_assoc_bool(ht, ZEND_STRL("measure_compile_time"), get_DD_TRACE_MEASURE_COMPILE_TIME());
    _dd_add_assoc_bool(ht, ZEND_STRL("report_hostname_on_root_span"), get_DD_TRACE_REPORT_HOSTNAME());
    _dd_add_assoc_string_free(ht, ZEND_STRL("traced_internal_functions"),
                              _dd_implode_keys(get_DD_TRACE_TRACED_INTERNAL_FUNCTIONS()));
    _dd_add_assoc_bool(ht, ZEND_STRL("auto_prepend_file_configured"), _dd_ini_is_set(ZEND_STRL("auto_prepend_file")));
    _dd_add_assoc_string_free(ht, ZEND_STRL("integrations_disabled"), _dd_implode_keys(get_DD_INTEGRATIONS_DISABLED()));
    _dd_add_assoc_bool(ht, ZEND_STRL("enabled_from_env"), get_DD_TRACE_ENABLED());
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

static bool _dd_file_exists(const char *file TSRMLS_DC) {
    if (!strlen(file)) {
        return false;
    }
    // TSRMLS_CC is embedded in the VCWD_ACCESS macro
    return (VCWD_ACCESS(file, R_OK) == 0);
}

static bool _dd_open_basedir_allowed(const char *file TSRMLS_DC) {
    return (php_check_open_basedir_ex(file, 0 TSRMLS_CC) != -1);
}

static void dd_check_for_excluded_module(HashTable *ht, zend_module_entry *module) {
    char error[DDTRACE_EXCLUDED_MODULES_ERROR_MAX_LEN + 1];

    if (module && module->name && ddtrace_is_excluded_module(module, error)) {
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
    TSRMLS_FETCH();
    // Cross-language tracer values
    char agent_error[CURL_ERROR_SIZE];
    if (_dd_check_for_agent_error(agent_error, quick)) {
        _dd_add_assoc_string(ht, ZEND_STRL("agent_error"), agent_error);
    }
    //_dd_add_assoc_string(ht, ZEND_STRL("sampling_rules_error"), ""); // TODO Parse at C level
    //_dd_add_assoc_string(ht, ZEND_STRL("service_mapping_error"), ""); // TODO Parse at C level

    // PHP-specific values
    const char *rih = get_DD_TRACE_REQUEST_INIT_HOOK().ptr;
    bool rih_exists = _dd_file_exists(rih TSRMLS_CC);
    if (!rih_exists) {
        _dd_add_assoc_bool(ht, ZEND_STRL("datadog.trace.request_init_hook_reachable"), rih_exists);
    } else {
        bool rih_allowed = _dd_open_basedir_allowed(rih TSRMLS_CC);
        if (!rih_allowed) {
            _dd_add_assoc_bool(ht, ZEND_STRL("open_basedir_init_hook_allowed"), rih_allowed);
        }
    }

    bool container_allowed = _dd_open_basedir_allowed("/proc/self/cgroup" TSRMLS_CC);
    if (!container_allowed) {
        _dd_add_assoc_bool(ht, ZEND_STRL("open_basedir_container_tagging_allowed"), container_allowed);
    }

    //_dd_add_assoc_string(ht, ZEND_STRL("uri_fragment_regex_error"), ""); // TODO Parse at C level
    //_dd_add_assoc_string(ht, ZEND_STRL("uri_mapping_incoming_error"), ""); // TODO Parse at C level
    //_dd_add_assoc_string(ht, ZEND_STRL("uri_mapping_outgoing_error"), ""); // TODO Parse at C level

    for (uint8_t i = 0; i < zai_config_memoized_entries_count; ++i) {
        zai_config_memoized_entry *cfg = &zai_config_memoized_entries[i];
        if (cfg->name_index > 0) {
            zai_config_name *old_name = &cfg->names[cfg->name_index];
            char *message;
            asprintf(&message, "'%s=%s' is deprecated, use %s instead.", old_name->ptr, cfg->ini_entries[0]->value,
                     cfg->names[0].ptr);
            _dd_add_assoc_string_free(ht, old_name->ptr, old_name->len, message);
        }
    }

    zai_config_memoized_entry *integrations_disabled_cfg =
        &zai_config_memoized_entries[DDTRACE_CONFIG_DD_INTEGRATIONS_DISABLED];
    if (integrations_disabled_cfg->name_index >= 0) {
        char *message;
        asprintf(&message,
                 "'DD_INTEGRATIONS_DISABLED=%s' is deprecated, use DD_TRACE_[INTEGRATION]_ENABLED=false instead.",
                 integrations_disabled_cfg->ini_entries[0]->value);
        _dd_add_assoc_string_free(ht, ZEND_STRL("DD_INTEGRATIONS_DISABLED"), message);
    }

    if (ddtrace_has_excluded_module == true) {
        zend_module_entry *module;
        HashPosition pos;

        zend_hash_internal_pointer_reset_ex(&module_registry, &pos);
        while (zend_hash_get_current_data_ex(&module_registry, (void *)&module, &pos) != FAILURE) {
            dd_check_for_excluded_module(ht, module);
            zend_hash_move_forward_ex(&module_registry, &pos);
        }
    }
}

static void _dd_serialize_json(HashTable *ht, smart_str *buf TSRMLS_DC) {
    zval zv;
    Z_ARRVAL(zv) = ht;
    Z_TYPE(zv) = IS_ARRAY;
    zai_json_encode(buf, &zv, 0 TSRMLS_CC);
    smart_str_0(buf);
}

void ddtrace_startup_logging_json(smart_str *buf) {
    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, DDTRACE_STARTUP_STAT_COUNT, NULL, ZVAL_PTR_DTOR, 0);

    _dd_get_startup_config(ht);
    ddtrace_startup_diagnostics(ht, false);

    TSRMLS_FETCH();
    _dd_serialize_json(ht, buf TSRMLS_CC);

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}

static void _dd_print_values_to_log(HashTable *ht) {
    int key_type;
    zval **val;
    HashPosition pos;
    char *key;
    uint key_len;
    ulong num_key;

    zend_hash_internal_pointer_reset_ex(ht, &pos);
    while (zend_hash_get_current_data_ex(ht, (void **)&val, &pos) == SUCCESS) {
        key_type = zend_hash_get_current_key_ex(ht, &key, &key_len, &num_key, 0, &pos);
        if (key_type == HASH_KEY_IS_STRING) {
            switch (Z_TYPE_PP(val)) {
                case IS_STRING:
                    ddtrace_log_errf("DATADOG TRACER DIAGNOSTICS - %s: %s", key, Z_STRVAL_PP(val));
                    break;
                case IS_NULL:
                    ddtrace_log_errf("DATADOG TRACER DIAGNOSTICS - %s: NULL", key);
                    break;
                case IS_BOOL:
                    if (Z_BVAL_PP(val)) {
                        ddtrace_log_errf("DATADOG TRACER DIAGNOSTICS - %s: true", key);
                    } else {
                        ddtrace_log_errf("DATADOG TRACER DIAGNOSTICS - %s: false", key);
                    }
                    break;
                default:
                    ddtrace_log_errf("DATADOG TRACER DIAGNOSTICS - %s: {unknown type}", key);
                    break;
            }
        }
        zend_hash_move_forward_ex(ht, &pos);
    }
}

// Only show startup logs on the first request
void ddtrace_startup_logging_first_rinit(TSRMLS_D) {
    if (!get_DD_TRACE_DEBUG() || !get_DD_TRACE_STARTUP_LOGS() || strcmp("cli", sapi_module.name) == 0) {
        return;
    }

    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, DDTRACE_STARTUP_STAT_COUNT, NULL, ZVAL_PTR_DTOR, 0);

    ddtrace_startup_diagnostics(ht, true);
    _dd_print_values_to_log(ht);
    _dd_get_startup_config(ht);

    smart_str buf = {0};
    _dd_serialize_json(ht, &buf TSRMLS_CC);
    ddtrace_log_errf("DATADOG TRACER CONFIGURATION - %s", buf.c);
    ddtrace_log_errf(
        "For additional diagnostic checks such as Agent connectivity, see the 'ddtrace' section of a phpinfo() "
        "page. Alternatively set DD_TRACE_DEBUG=1 to add diagnostic checks to the error logs on the first request "
        "of a new PHP process. Set DD_TRACE_STARTUP_LOGS=0 to disable this tracer configuration message.");
    smart_str_free(&buf);

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}
