extern "C" {
#include <main/php.h>
#include "tea/extension.h"
}

static ZEND_INI_MH(second_consumer_on_modify) {
    (void)entry; (void)new_value; (void)mh_arg1; (void)mh_arg2; (void)mh_arg3; (void)stage;
    return SUCCESS;
}

// "zai_config.INI_FOO_STRING" is the name ext_ini_env_to_ini_name produces for "INI_FOO_STRING"
// clang-format off
PHP_INI_BEGIN()
    PHP_INI_ENTRY("zai_config.INI_FOO_STRING", "", PHP_INI_ALL, second_consumer_on_modify)
PHP_INI_END()
// clang-format on

static PHP_MINIT_FUNCTION(second_consumer) {
    REGISTER_INI_ENTRIES();
    return SUCCESS;
}

static PHP_RINIT_FUNCTION(second_consumer) {
    (void)type; (void)module_number;
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(second_consumer) {
    (void)type; (void)module_number;
    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(second_consumer) {
    UNREGISTER_INI_ENTRIES();
    return SUCCESS;
}

void ext_second_consumer_load(void) {
    tea_extension_minit(PHP_MINIT(second_consumer));
    tea_extension_rinit(PHP_RINIT(second_consumer));
    tea_extension_rshutdown(PHP_RSHUTDOWN(second_consumer));
    tea_extension_mshutdown(PHP_MSHUTDOWN(second_consumer));
}
