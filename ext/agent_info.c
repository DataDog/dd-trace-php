#include "agent_info.h"
#include "ddtrace.h"
#include "sidecar.h"
#include "configuration.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_check_agent_info_env() {
    if (DDTRACE_G(agent_info_reader) && ZSTR_LEN(get_DD_ENV()) == 0) {
        bool changed;
        ddog_CharSlice env = ddog_get_agent_info_env(DDTRACE_G(agent_info_reader), &changed);
        if (env.len) {
            zend_alter_ini_entry_chars(zai_config_memoized_entries[DDTRACE_CONFIG_DD_ENV].ini_entries[0]->name, env.ptr, env.len, ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
        }
    }
}

void ddtrace_agent_info_rinit() {
    if (ddtrace_endpoint && !DDTRACE_G(agent_info_reader) && !ZSTR_LEN(get_global_DD_ENV())) {
        DDTRACE_G(agent_info_reader) = ddog_get_agent_info_reader(ddtrace_endpoint);
    }
}
