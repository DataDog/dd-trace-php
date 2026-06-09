#include <SAPI.h>
#include "target_metadata.h"
#include <tracer/tracer_api.h>
#include <ext/standard/php_string.h>

zend_string *datadog_default_service_name(void) {
    if (strcmp(sapi_module.name, "cli") != 0) {
        return zend_string_init(ZEND_STRL("web.request"), 0);
    }

    const char *script_name;
    if (SG(request_info).argc > 0 && (script_name = SG(request_info).argv[0]) && script_name[0] != '\0') {
        return php_basename(script_name, strlen(script_name), NULL, 0);
    } else {
        return zend_string_init(ZEND_STRL("cli.command"), 0);
    }
}

void datadog_populate_target_data_with_defaults(ddtrace_span_data *span, zend_string **service, zend_string **env, zend_string **version, zend_string *cfg_service, zend_string *cfg_env, zend_string *cfg_version) {
    ddtrace_populate_span_data(span, service, env, version);

    if (!*service) {
        if (ZSTR_LEN(cfg_service)) {
            *service = zend_string_copy(cfg_service);
        } else {
            *service = datadog_default_service_name();
        }
    }

    if (!*env && ZSTR_LEN(cfg_env)) {
        *env = zend_string_copy(cfg_env);
    }
    if (!*env) {
        *env = zend_string_init(ZEND_STRL("none"), 0);
    }

    if (!*version && ZSTR_LEN(cfg_version)) {
        *version = zend_string_copy(cfg_version);
    }
    if (!*version) {
        *version = ZSTR_EMPTY_ALLOC();
    }
}
