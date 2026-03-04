#include "agent_info.h"
#include "ddtrace.h"
#include "sidecar.h"
#include "configuration.h"
#include "span.h"

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

void ddtrace_check_agent_info_opm() {
    if (DDTRACE_G(agent_info_reader) && !DDTRACE_G(opm)) {
        bool changed;
        ddog_CharSlice opm = ddog_get_agent_info_opm(DDTRACE_G(agent_info_reader), &changed);
        if (opm.len) {
            DDTRACE_G(opm) = zend_string_init(opm.ptr, opm.len, 1);
        }
    }

    if (DDTRACE_G(opm)) {
        ddtrace_root_span_data *root_span = DDTRACE_G(active_stack) ? DDTRACE_G(active_stack)->root_span : NULL;
        if (root_span && Z_TYPE(root_span->property_org_propagation_marker) != IS_STRING) {
            ZVAL_STR_COPY(&root_span->property_org_propagation_marker, DDTRACE_G(opm));
        }
    }
}

void ddtrace_agent_info_rinit() {
    if (ddtrace_endpoint && !DDTRACE_G(agent_info_reader)) {
        DDTRACE_G(agent_info_reader) = ddog_get_agent_info_reader(ddtrace_endpoint);
    }
}
