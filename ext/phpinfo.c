#include "phpinfo.h"
#include "datadog.h"
#include "startup_logging.h"
#include <json/json.h>
#include <ext/standard/info.h>
#include <php.h>
#include <SAPI.h>

static size_t datadog_info_print(const char *str) { return php_output_write(str, strlen(str)); }

static void dd_info_tracer_config(void) {
    smart_str buf = {0};
    datadog_startup_logging_json(&buf, PHP_JSON_PRETTY_PRINT);
    php_info_print_table_row(2, "DATADOG TRACER CONFIGURATION", buf.s ? ZSTR_VAL(buf.s) : "{}");
    smart_str_free(&buf);
}

static void dd_info_diagnostics_row(const char *key, const char *value) {
    if (sapi_module.phpinfo_as_text) {
        php_info_print_table_row(2, key, value);
        return;
    }
    datadog_info_print("<tr><td class='e'>");
    datadog_info_print(key);
    datadog_info_print("</td><td class='v' style='background-color:#f0e881;'>");
    datadog_info_print(value);
    datadog_info_print("</td></tr>");
}

static void dd_info_diagnostics_table(void) {
    php_info_print_table_start();
    php_info_print_table_colspan_header(2, "Diagnostics");

    HashTable *ht;
    ALLOC_HASHTABLE(ht);
    zend_hash_init(ht, 8, NULL, ZVAL_PTR_DTOR, 0);

    datadog_startup_diagnostics(ht, false);

    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(ht, key, val) {
        switch (Z_TYPE_P(val)) {
            case IS_STRING:
                dd_info_diagnostics_row(ZSTR_VAL(key), Z_STRVAL_P(val));
                break;
            case IS_NULL:
                dd_info_diagnostics_row(ZSTR_VAL(key), "NULL");
                break;
            case IS_TRUE:
            case IS_FALSE:
                dd_info_diagnostics_row(ZSTR_VAL(key), Z_TYPE_P(val) == IS_TRUE ? "true" : "false");
                break;
            default:
                dd_info_diagnostics_row(ZSTR_VAL(key), "{unknown type}");
                break;
        }
    }
    ZEND_HASH_FOREACH_END();

    php_info_print_table_row(2, "Diagnostic checks", zend_hash_num_elements(ht) == 0 ? "passed" : "failed");

    zend_hash_destroy(ht);
    FREE_HASHTABLE(ht);

    php_info_print_table_end();
}

void datadog_phpinfo_section(const char *title) {
    if (sapi_module.phpinfo_as_text) {
        datadog_info_print("\n");
        datadog_info_print(title);
        datadog_info_print("\n\n");
    } else {
        datadog_info_print("<h2>");
        datadog_info_print(title);
        datadog_info_print("</h2>\n");
    }
}

static datadog_phpinfo_ini_group dd_info_ini_group_of(const zend_string *name) {
    const char *n = ZSTR_VAL(name);
    if (strncmp(n, "datadog.appsec.", sizeof("datadog.appsec.") - 1) == 0 ||
        strncmp(n, "datadog.api_security_", sizeof("datadog.api_security_") - 1) == 0) {
        return DATADOG_PHPINFO_INI_APPSEC;
    }
    if (strncmp(n, "datadog.profiling.", sizeof("datadog.profiling.") - 1) == 0) {
        return DATADOG_PHPINFO_INI_PROFILER;
    }
    return DATADOG_PHPINFO_INI_TRACER;
}

// Mirrors what PHP does for each value of the INI table, except that values are HTML-escaped
static void dd_info_ini_value(zend_ini_entry *ini, int type) {
    if (ini->displayer) {
        ini->displayer(ini, type);
        return;
    }

    zend_string *value = type == ZEND_INI_DISPLAY_ORIG && ini->modified ? ini->orig_value : ini->value;
    if (!value || !ZSTR_VAL(value)[0]) {
        datadog_info_print(sapi_module.phpinfo_as_text ? "no value" : "<i>no value</i>");
    } else if (sapi_module.phpinfo_as_text) {
        php_output_write(ZSTR_VAL(value), ZSTR_LEN(value));
    } else {
        // php_info_html_esc() is not part of the public headers on PHP 8.4+, escape by hand
        const char *s = ZSTR_VAL(value), *end = s + ZSTR_LEN(value), *run = s;
        for (; s < end; ++s) {
            const char *repl;
            switch (*s) {
                case '&': repl = "&amp;"; break;
                case '<': repl = "&lt;"; break;
                case '>': repl = "&gt;"; break;
                case '"': repl = "&quot;"; break;
                default: continue;
            }
            php_output_write(run, (size_t)(s - run));
            datadog_info_print(repl);
            run = s + 1;
        }
        php_output_write(run, (size_t)(end - run));
    }
}

void datadog_phpinfo_ini_entries(zend_module_entry *module, unsigned groups) {
    bool started = false;
    zend_ini_entry *ini;

    ZEND_HASH_FOREACH_PTR(EG(ini_directives), ini) {
        if (ini->module_number != module->module_number || !(groups & dd_info_ini_group_of(ini->name))) {
            continue;
        }

        if (!started) {
            started = true;
            php_info_print_table_start();
            php_info_print_table_header(3, "Directive", "Local Value", "Master Value");
        }

        if (sapi_module.phpinfo_as_text) {
            datadog_info_print(ZSTR_VAL(ini->name));
            datadog_info_print(" => ");
            dd_info_ini_value(ini, ZEND_INI_DISPLAY_ACTIVE);
            datadog_info_print(" => ");
            dd_info_ini_value(ini, ZEND_INI_DISPLAY_ORIG);
            datadog_info_print("\n");
        } else {
            datadog_info_print("<tr><td class=\"e\">");
            datadog_info_print(ZSTR_VAL(ini->name));
            datadog_info_print("</td><td class=\"v\">");
            dd_info_ini_value(ini, ZEND_INI_DISPLAY_ACTIVE);
            datadog_info_print("</td><td class=\"v\">");
            dd_info_ini_value(ini, ZEND_INI_DISPLAY_ORIG);
            datadog_info_print("</td></tr>\n");
        }
    }
    ZEND_HASH_FOREACH_END();

    if (started) {
        php_info_print_table_end();
    }
}

void datadog_phpinfo_tracer(void) {
    datadog_phpinfo_section("Datadog Tracer");

    php_info_print_table_start();
#ifdef TRACER
    php_info_print_table_row(2, "Datadog tracing support", datadog_disable ? "disabled" : "enabled");
#else
    php_info_print_table_row(2, "Datadog tracing support", "disabled (not built)");
#endif
    dd_info_tracer_config();
    php_info_print_table_end();

    if (!datadog_disable) {
        dd_info_diagnostics_table();
    }
}

void datadog_phpinfo(bool appsec_loaded, bool profiler_loaded) {
    php_info_print_box_start(0);
    datadog_info_print("Datadog PHP library (tracer, Application Security and profiler)");
    if (!sapi_module.phpinfo_as_text) {
        datadog_info_print("<br><strong>For help, check out ");
        datadog_info_print(
            "<a href=\"https://docs.datadoghq.com/tracing/languages/php/\" "
            "style=\"background:transparent;\">the documentation</a>.</strong>");
    } else {
        datadog_info_print(
            "\nFor help, check out the documentation at "
            "https://docs.datadoghq.com/tracing/languages/php/");
    }
    datadog_info_print(!sapi_module.phpinfo_as_text ? "<br><br>" : "\n");
    datadog_info_print("(c) Datadog 2020\n");
    php_info_print_box_end();

    php_info_print_table_start();
    php_info_print_table_colspan_header(2, "Datadog Library");
    php_info_print_table_row(2, "Version", PHP_DDTRACE_VERSION);
#ifdef TRACER
    php_info_print_table_row(2, "Tracer", datadog_disable ? "disabled" : "loaded");
#else
    php_info_print_table_row(2, "Tracer", "not built");
#endif
    php_info_print_table_row(2, "Application Security", appsec_loaded ? "loaded" : "not loaded");
    php_info_print_table_row(2, "Profiler", profiler_loaded ? "loaded" : "not loaded");
    php_info_print_table_end();
}