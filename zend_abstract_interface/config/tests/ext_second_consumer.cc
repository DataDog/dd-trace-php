extern "C" {
#include "config/config.h"
#include "tea/extension.h"
}

#include <mutex>

static void ext_second_consumer_env_to_ini_name(zai_str env_name, zai_config_name *ini_name) {
    int len = snprintf(ini_name->ptr, ZAI_CONFIG_NAME_BUFSIZ, "zai_config.%s", env_name.ptr);
    ini_name->len = (len > 0 && len < ZAI_CONFIG_NAME_BUFSIZ) ? (size_t)len : 0;
}

typedef enum {
    EXT_CFG_SC_INI_FOO_STRING,
    EXT_CFG_SC_INI_FOO_INT,
} ext_second_consumer_cfg_id;

static std::once_flag sc_first_rinit_once;

static PHP_MINIT_FUNCTION(second_consumer) {
    new (&sc_first_rinit_once) std::once_flag{};
    zai_config_entry entries[] = {
        ZAI_CONFIG_ENTRY(EXT_CFG_SC_INI_FOO_STRING, INI_FOO_STRING, STRING, ""),
        ZAI_CONFIG_ENTRY(EXT_CFG_SC_INI_FOO_INT, INI_FOO_INT, INT, "0"),
    };
    if (!zai_config_minit(entries, sizeof(entries) / sizeof(entries[0]), ext_second_consumer_env_to_ini_name, module_number)) {
        return FAILURE;
    }
    return SUCCESS;
}

static PHP_RINIT_FUNCTION(second_consumer) {
    (void)type; (void)module_number;
    std::call_once(sc_first_rinit_once, zai_config_first_time_rinit, true);
    zai_config_rinit();
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(second_consumer) {
    (void)type; (void)module_number;
    zai_config_rshutdown();
    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(second_consumer) {
    zai_config_mshutdown();
    return SUCCESS;
}

static zend_module_entry second_consumer_module_entry = {
    STANDARD_MODULE_HEADER,
    "second_consumer",
    NULL,  /* functions */
    PHP_MINIT(second_consumer),
    PHP_MSHUTDOWN(second_consumer),
    PHP_RINIT(second_consumer),
    PHP_RSHUTDOWN(second_consumer),
    NULL,  /* PHP_MINFO */
    PHP_VERSION,
    STANDARD_MODULE_PROPERTIES
};

extern "C" ZEND_DLEXPORT zend_module_entry *get_module(void) {
    return &second_consumer_module_entry;
}
