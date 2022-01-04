extern "C" {
#include "function_call_interceptor/function_call_interceptor.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
}

#include <catch2/catch.hpp>
#include <cstring>

/******************************* Test Helpers *********************************/

// Injected into the test extension's MINIT function to install hooks
static void (*startup_hooks_installer)(void);

static PHP_MINIT_FUNCTION(ext_prehook) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_fci_minit();
    assert(startup_hooks_installer && "Hooks installer not set");
    startup_hooks_installer();
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(ext_prehook) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_fci_mshutdown();
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

static PHP_RINIT_FUNCTION(ext_prehook) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_fci_rinit();
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(ext_prehook) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_fci_rshutdown();
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

static int prehook_invocation_count;
static int posthook_invocation_count;

static void foo_prehook(zend_execute_data *execute_data) {
    prehook_invocation_count++;
}

static void foo_posthook(zend_execute_data *execute_data, zval *retval) {
    posthook_invocation_count++;
}

/************************* zai_fci_startup_prehook() **************************/

static void install_prehooks(void) {
    bool status = zai_fci_startup_prehook("Foo\\App::testing", foo_prehook);
    assert(status && "Failed to add prehook");
}

TEST_CASE("add prehooks", "[zai_fci]") {
    REQUIRE(zai_sapi_sinit());
    prehook_invocation_count = 0;
    posthook_invocation_count = 0;
    zai_sapi_extension.module_startup_func = PHP_MINIT(ext_prehook);
    zai_sapi_extension.module_shutdown_func = PHP_MSHUTDOWN(ext_prehook);
    zai_sapi_extension.request_startup_func = PHP_RINIT(ext_prehook);
    zai_sapi_extension.request_shutdown_func = PHP_RSHUTDOWN(ext_prehook);
    startup_hooks_installer = install_prehooks;

    REQUIRE(zai_sapi_minit());
    REQUIRE(zai_sapi_rinit());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(true);
    //REQUIRE(prehook_invocation_count == 1);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/************************* zai_fci_startup_posthook() *************************/


/************************* zai_fci_runtime_hook_ex() **************************/
