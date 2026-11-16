#include "agent_info.h"
#include "datadog.h"
#include "sidecar.h"
#include "configuration.h"
#include "process_tags.h"

ZEND_EXTERN_MODULE_GLOBALS(datadog);

void datadog_agent_info_rinit() {
    if (datadog_endpoint && !DATADOG_G(agent_info_reader)) {
        DATADOG_G(agent_info_reader) = ddog_get_agent_info_reader(datadog_endpoint);
    }
}

void datadog_apply_agent_info(void) {
    if (!DATADOG_G(agent_info_reader)) {
        return;
    }
    ddog_CharSlice env = {0}, hash = {0};
    ddog_apply_agent_info(DATADOG_G(agent_info_reader), &env, &hash);
    if (env.len && ZSTR_LEN(get_DD_ENV()) == 0) {
        zend_alter_ini_entry_chars(
            zai_config_memoized_entries[DATADOG_CONFIG_DD_ENV].ini_entries[0]->name,
            env.ptr, env.len, ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
    }
    if (hash.len) {
        zend_string *hash_str = zend_string_init(hash.ptr, hash.len, 1);
        datadog_process_tags_set_container_tags_hash(hash_str);
        zend_string_release(hash_str);
    }
}
