#include "ddtrace_config.h"

#include <assert.h>
#include <ctype.h>
#include <stdio.h>
#include <string.h>

#define DD_CONFIG_ENTRY(name, type, default) ZAI_CONFIG_ENTRY(DDTRACE_CONFIG_##name, name, type, default)
#define DD_CONFIG_ALIASED_ENTRY(name, type, default, aliases) \
    ZAI_CONFIG_ALIASED_ENTRY(DDTRACE_CONFIG_##name, name, type, default, aliases)

static void dd_ini_env_to_ini_name(zai_string_view env_name, zai_config_ini_name *ini_name) {
    if ((env_name.len + 5 /* "DD" expanded to "datadog" */) >= ZAI_CONFIG_INI_NAME_BUFSIZ) {
        assert(false && "Expanded env name length is larger than the INI name buffer");
        return;
    }

    char *ptr = (char *)env_name.ptr;
    char *prefix = strstr(ptr, "DD_");
    if (ptr == prefix) {
        ptr += (sizeof("DD_") - 1);
        strcpy(ini_name->ptr, "datadog.");
        ini_name->len = (sizeof("datadog.") - 1);
    } else {
        ini_name->len = 0;
        assert(false && "Unexpected env var name: missing 'DD_' prefix");
    }

    prefix = strstr(ptr, "TRACE_");
    if (ptr == prefix) {
        ptr += (sizeof("TRACE_") - 1);
        strcpy((ini_name->ptr + ini_name->len), "trace.");
        ini_name->len += (sizeof("trace.") - 1);
    }

    while (*ptr) {
        ini_name->ptr[ini_name->len++] = tolower(*ptr++);
    }

    ini_name->ptr[ini_name->len] = '\0';
}

void ddtrace_config_minit(int module_number) {
    zai_string_view aliases_service[] = {ZAI_STRL_VIEW("DD_SERVICE_NAME"), ZAI_STRL_VIEW("DD_TRACE_APP_NAME")};
    zai_string_view aliases_tags[] = {ZAI_STRL_VIEW("DD_TRACE_GLOBAL_TAGS")};

    zai_config_entry entries[] = {
        DD_CONFIG_ALIASED_ENTRY(DD_SERVICE, STRING, "", aliases_service),
        DD_CONFIG_ALIASED_ENTRY(DD_TAGS, MAP, "", aliases_tags),
        DD_CONFIG_ENTRY(DD_TRACE_AGENT_PORT, INT, "8126"),
        DD_CONFIG_ENTRY(DD_TRACE_DEBUG, BOOL, "0"),
        DD_CONFIG_ENTRY(DD_TRACE_SAMPLE_RATE, DOUBLE, "1.0"),
    };

    zai_config_minit(entries, (sizeof entries / sizeof entries[0]), dd_ini_env_to_ini_name, module_number);
}
