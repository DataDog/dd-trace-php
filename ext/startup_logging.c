#include "startup_logging.h"
#include <tracer/tracer_startup_logging.h>

#include <SAPI.h>
#include <Zend/zend_API.h>
#include <Zend/zend_smart_str.h>
#include <json/json.h>
#include <time.h>

#include <ext/standard/info.h>

#ifndef _WIN32
#include <curl/curl.h>
#include <tracer/coms.h>
#endif

#include "configuration.h"
#include "endpoints.h"
#include "telemetry.h"
#include <ext/excluded_modules.h>
#include "ext/version.h"
#include <components/log/log.h>

#include "startup_logging_helpers.h"

#define ISO_8601_LEN (20 + 1)  // +1 for terminating null-character

static void dd_get_time(char *buf) {
    time_t now = time(NULL);
    struct tm *tm = gmtime(&now);
    if (tm) {
        strftime(buf, ISO_8601_LEN, "%Y-%m-%dT%TZ", tm);
    } else {
        LOG(WARN, "Error getting time");
    }
}


static void dd_get_startup_config(HashTable *ht) {
    // Cross-language tracer values
    char time[ISO_8601_LEN];
    dd_get_time(time);
    dd_add_assoc_string(ht, ZEND_STRL("date"), time);

    dd_add_assoc_zstring(ht, ZEND_STRL("os_name"), php_get_uname('a'));
    dd_add_assoc_zstring(ht, ZEND_STRL("os_version"), php_get_uname('r'));
    dd_add_assoc_string(ht, ZEND_STRL("version"), PHP_DDTRACE_VERSION);
    dd_add_assoc_string(ht, ZEND_STRL("lang"), "php");
    dd_add_assoc_string(ht, ZEND_STRL("lang_version"), PHP_VERSION);
    dd_add_assoc_zstring(ht, ZEND_STRL("env"), zend_string_copy(get_DD_ENV()));
    dd_add_assoc_bool(ht, ZEND_STRL("enabled"), !dd_parse_bool(ZEND_STRL("ddtrace.disable")));
    dd_add_assoc_zstring(ht, ZEND_STRL("service"), zend_string_copy(get_DD_SERVICE()));
    dd_add_assoc_bool(ht, ZEND_STRL("enabled_cli"), get_DD_TRACE_CLI_ENABLED());
    dd_add_assoc_string_free(ht, ZEND_STRL("agent_url"), datadog_agent_url());


    dd_add_assoc_bool(ht, ZEND_STRL("debug"), get_DD_TRACE_DEBUG());
}

// TODO replace with a rust-based implementation
#ifndef _WIN32
static size_t dd_curl_write_noop(void *ptr, size_t size, size_t nmemb, void *userdata) {
    UNUSED(ptr, userdata);
    return size * nmemb;
}

static size_t dd_check_for_agent_error(char *error, bool quick) {
    CURL *curl = curl_easy_init();
    ddtrace_curl_set_hostname(curl);
    if (quick) {
        curl_easy_setopt(curl, CURLOPT_TIMEOUT_MS, DATADOG_AGENT_QUICK_TIMEOUT);
        curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT_MS, DATADOG_AGENT_QUICK_CONNECT_TIMEOUT);
    } else {
        ddtrace_curl_set_timeout(curl);
        ddtrace_curl_set_connect_timeout(curl);
    }

    struct curl_slist *headers = NULL;
    headers = curl_slist_append(headers, "X-Datadog-Diagnostic-Check: 1");
    headers = curl_slist_append(headers, "Content-Type: application/json");
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);

    const char *body = "[]";
    curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE, (long)strlen(body));
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, body);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, dd_curl_write_noop);
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
#endif

static void dd_check_for_excluded_module(HashTable *ht, zend_module_entry *module) {
    char error[DATADOG_EXCLUDED_MODULES_ERROR_MAX_LEN + 1];

    if (module && module->name && module->version && datadog_is_excluded_module(module, error)) {
        char key[64];
        size_t key_len;

        key_len = snprintf(key, sizeof(key) - 1, "incompatible module %s", module->name);
        dd_add_assoc_string(ht, key, key_len, error);
    }
}

/* Supported zval types for diagnostics: string, bool, null
 * To support other types, update phpinfo.c:_dd_info_diagnostics_table();
 */
void datadog_startup_diagnostics(HashTable *ht, bool quick) {
#ifdef DDTRACE
    ddtrace_startup_diagnostics(ht, quick);
#endif
#ifndef _WIN32
    char agent_error[CURL_ERROR_SIZE];
    if (dd_check_for_agent_error(agent_error, quick)) {
        dd_add_assoc_string(ht, ZEND_STRL("agent_error"), agent_error);
    }
#endif
    //dd_add_assoc_string(ht, ZEND_STRL("service_mapping_error"), ""); // TODO Parse at C level

    //dd_add_assoc_string(ht, ZEND_STRL("uri_fragment_regex_error"), ""); // TODO Parse at C level
    //dd_add_assoc_string(ht, ZEND_STRL("uri_mapping_incoming_error"), ""); // TODO Parse at C level
    //dd_add_assoc_string(ht, ZEND_STRL("uri_mapping_outgoing_error"), ""); // TODO Parse at C level

    // opcache.file_cache was added in PHP 7.0
    const char *opcache_file_cache = dd_get_ini(ZEND_STRL("opcache.file_cache"));
    if (opcache_file_cache && opcache_file_cache[0]) {
        dd_add_assoc_string(ht, ZEND_STRL("opcache_file_cache_set"),
                             "The opcache.file_cache INI setting is set. This setting can cause unexpected behavior "
                             "with the PHP tracer due to a bug in OPcache: https://bugs.php.net/bug.php?id=79825");
    }

    for (uint16_t i = 0; i < zai_config_memoized_entries_count; ++i) {
        zai_config_memoized_entry *cfg = &zai_config_memoized_entries[i];
        // DD_TRACE_LOGS_ENABLED would be the proper name, but for compatibility with other tracers, we also support DD_LOGS_INJECTION officially
        if (cfg->name_index > 0 && i != DATADOG_CONFIG_DD_TRACE_LOGS_ENABLED) {
            zai_config_name *old_name = &cfg->names[cfg->name_index];
            zend_string *message = zend_strpprintf(0, "'%s=%s' is deprecated, use %s instead.", old_name->ptr,
                                                   ZSTR_VAL(cfg->ini_entries[0]->value), cfg->names[0].ptr);
            dd_add_assoc_zstring(ht, old_name->ptr, old_name->len, message);
        }
    }

    if (datadog_has_excluded_module == true) {
        zend_module_entry *module;
        ZEND_HASH_FOREACH_PTR(&module_registry, module) { dd_check_for_excluded_module(ht, module); }
        ZEND_HASH_FOREACH_END();
    }
}

static void dd_serialize_json(HashTable *ht, smart_str *buf, int options) {
    zval zv;
    ZVAL_ARR(&zv, ht);
    zai_json_encode(buf, &zv, options);
    smart_str_0(buf);
}

void datadog_startup_logging_json(smart_str *buf, int options) {
    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, DATADOG_STARTUP_STAT_COUNT, NULL, ZVAL_PTR_DTOR, 0);

    dd_get_startup_config(ht);
#ifdef DDTRACE
    ddtrace_populate_startup_config(ht);
#endif
    dd_add_assoc_bool(ht, ZEND_STRL("loaded_by_ssi"), datadog_loaded_by_ssi);
    datadog_startup_diagnostics(ht, false);

    dd_serialize_json(ht, buf, options);

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}

static void dd_print_values_to_log(HashTable *ht, void (*log)(const char *format, ...)) {
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(ht, key, val) {
        switch (Z_TYPE_P(val)) {
            case IS_STRING:
                log("DATADOG TRACER DIAGNOSTICS - %s: %s", ZSTR_VAL(key), Z_STRVAL_P(val));
                break;
            case IS_NULL:
                log("DATADOG TRACER DIAGNOSTICS - %s: NULL", ZSTR_VAL(key));
                break;
            case IS_TRUE:
                log("DATADOG TRACER DIAGNOSTICS - %s: true", ZSTR_VAL(key));
                break;
            case IS_FALSE:
                log("DATADOG TRACER DIAGNOSTICS - %s: false", ZSTR_VAL(key));
                break;
            default:
                log("DATADOG TRACER DIAGNOSTICS - %s: {unknown type}", ZSTR_VAL(key));
                break;
        }
    }
    ZEND_HASH_FOREACH_END();
}

static void dd_print_startup_logs(void (*log)(const char *format, ...)) {
    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, DATADOG_STARTUP_STAT_COUNT, NULL, ZVAL_PTR_DTOR, 0);

    datadog_startup_diagnostics(ht, true);
    dd_get_startup_config(ht);
#ifdef DDTRACE
    ddtrace_populate_startup_config(ht);
#endif
    dd_add_assoc_bool(ht, ZEND_STRL("loaded_by_ssi"), datadog_loaded_by_ssi);
    dd_print_values_to_log(ht, log);

    smart_str buf = {0};
    dd_serialize_json(ht, &buf, 0);
    log("DATADOG TRACER CONFIGURATION - %s", ZSTR_VAL(buf.s));
    log("For additional diagnostic checks such as Agent connectivity, see the 'ddtrace' section of a phpinfo() "
        "page. Alternatively set DD_TRACE_DEBUG=Error,Startup to add diagnostic checks to the error logs on the first request "
        "of a new PHP process. Set DD_TRACE_STARTUP_LOGS=0 to disable this tracer configuration message.");

#ifdef DDTRACE
    ddtrace_startup_logging_extra(log);
#endif

    smart_str_free(&buf);

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);
}

// Only show startup logs on the first request
void datadog_startup_logging_first_rinit(void) {
    LOGEV(STARTUP, dd_print_startup_logs(log);)
}
