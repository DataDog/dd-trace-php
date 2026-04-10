#include "agent_info.h"
#include "ddtrace.h"
#include "sidecar.h"
#include "configuration.h"
#include "process_tags.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_agent_info_rinit() {
    if (ddtrace_endpoint && !DDTRACE_G(agent_info_reader)) {
        DDTRACE_G(agent_info_reader) = ddog_get_agent_info_reader(ddtrace_endpoint);
    }
}

void ddtrace_apply_agent_info(void) {
    if (!DDTRACE_G(agent_info_reader)) {
        return;
    }
    ddog_CharSlice env = {}, hash = {};
    ddog_apply_agent_info(DDTRACE_G(agent_info_reader), &env, &hash);
    if (env.len && ZSTR_LEN(get_DD_ENV()) == 0) {
        zend_alter_ini_entry_chars(
            zai_config_memoized_entries[DDTRACE_CONFIG_DD_ENV].ini_entries[0]->name,
            env.ptr, env.len, ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
    }
    if (hash.len) {
        zend_string *hash_str = zend_string_init(hash.ptr, hash.len, 1);
        ddtrace_process_tags_set_container_tags_hash(hash_str);
        zend_string_release(hash_str);
    }
}
