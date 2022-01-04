extern "C" {
#include "zai_sapi/zai_sapi.h"
}

#include <catch2/catch.hpp>
#include <cstring>

TEST_CASE("eval: SAPI, modules, and request init/shutdown", "[zai_sapi]") {
    REQUIRE(zai_sapi_sinit());
    REQUIRE(zai_sapi_minit());
    REQUIRE(zai_sapi_rinit());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(ZAI_SAPI_EVAL_STR("echo 'Hello world' . PHP_EOL;") == SUCCESS);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_rshutdown();
    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}

TEST_CASE("eval: spinup/spindown", "[zai_sapi]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(ZAI_SAPI_EVAL_STR("echo 'Hello world' . PHP_EOL;") == SUCCESS);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("compile file success", "[zai_sapi]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/basic.php"));

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("compile file failure (file not found)", "[zai_sapi]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()

    // No need to wrap with REQUIRE() since it will zend_bailout (long jump)
    zai_sapi_execute_script("./this_does_not_exist.php");

    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("hard-coded SAPI INI entries added to config hash table", "[zai_sapi]") {
    REQUIRE(zai_sapi_sinit());
    REQUIRE(zai_sapi_minit());

    // Hard-coded SAPI INI entries from 'zai_sapi.c'
    REQUIRE(strcmp(ZAI_SAPI_INI_STR("html_errors"), "0") == 0);
    REQUIRE(strcmp(ZAI_SAPI_INI_STR("implicit_flush"), "1") == 0);
    REQUIRE(strcmp(ZAI_SAPI_INI_STR("output_buffering"), "0") == 0);

    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}

TEST_CASE("overwrite hard-coded SAPI INI", "[zai_sapi]") {
    REQUIRE(zai_sapi_sinit());
    // Hard-coded SAPI INI entry: "html_errors=0"
    REQUIRE(zai_sapi_append_system_ini_entry("html_errors", "1"));
    REQUIRE(zai_sapi_minit());

    REQUIRE(strcmp(ZAI_SAPI_INI_STR("html_errors"), "1") == 0);

    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}

TEST_CASE("access modified INI", "[zai_sapi]") {
    REQUIRE(zai_sapi_sinit());
    REQUIRE(zai_sapi_append_system_ini_entry("error_prepend_string", "Foo prepend"));
    REQUIRE(zai_sapi_minit());

    REQUIRE(strcmp(ZAI_SAPI_INI_STR("error_prepend_string"), "Foo prepend") == 0);

    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}
