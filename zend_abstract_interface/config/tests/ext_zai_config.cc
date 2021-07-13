extern "C" {
#include "ext_zai_config.h"

#include "config/config.h"
#include "zai_sapi/zai_sapi.h"
}

#include <atomic>

std::atomic<int> ext_first_rinit;
static zend_result (*ext_orig_minit)(INIT_FUNC_ARGS);

static PHP_MINIT_FUNCTION(zai_config) {
    atomic_init(&ext_first_rinit, 1);
    return ext_orig_minit(INIT_FUNC_ARGS_PASSTHRU);
}

static PHP_MSHUTDOWN_FUNCTION(zai_config) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zai_config_mshutdown();
    UNREGISTER_INI_ENTRIES();

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

static PHP_RINIT_FUNCTION(zai_config) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    int expected_first_rinit = 1;
    if (atomic_compare_exchange_strong(&ext_first_rinit, &expected_first_rinit, 0)) {
        zai_config_first_time_rinit();
    }

    zai_config_rinit();

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(zai_config) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zai_config_rshutdown();

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

void ext_zai_config_ctor(zend_module_entry *module, ext_zai_config_minit_fn orig_minit) {
    ext_orig_minit = orig_minit;
    module->module_startup_func = PHP_MINIT(zai_config);
    module->module_shutdown_func = PHP_MSHUTDOWN(zai_config);
    module->request_startup_func = PHP_RINIT(zai_config);
    module->request_shutdown_func = PHP_RSHUTDOWN(zai_config);
}
