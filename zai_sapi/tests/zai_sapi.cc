#include "zai_sapi/testing/catch2.hpp"
#include <cstring>

ZAI_SAPI_TEST_CASE("zai_sapi/compile", "eval", {
    REQUIRE(ZAI_SAPI_EVAL_STR("echo 'Hello world' . PHP_EOL;") == SUCCESS);
})

ZAI_SAPI_TEST_CASE_WITH_STUB("zai_sapi/compile", "file", "./stubs/basic.php", {})

ZAI_SAPI_TEST_BAILING_CASE("zai_sapi/compile", "non-existent", {
    zai_sapi_execute_script("./this_does_not_exist.php");
});

ZAI_SAPI_TEST_CASE("zai_sapi/ini", "hardcoded", {
    // Hard-coded SAPI INI entries from 'zai_sapi.c'
    REQUIRE(strcmp(ZAI_SAPI_INI_STR("html_errors"), "0") == 0);
    REQUIRE(strcmp(ZAI_SAPI_INI_STR("implicit_flush"), "1") == 0);
    REQUIRE(strcmp(ZAI_SAPI_INI_STR("output_buffering"), "0") == 0);
})

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("zai_sapi/ini", "overwrite hardcoded", {
    REQUIRE(zai_sapi_append_system_ini_entry("html_errors", "1"));
}, {
    REQUIRE(strcmp(ZAI_SAPI_INI_STR("html_errors"), "1") == 0);
})

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("zai_sapi/ini", "access modified", {
    REQUIRE(zai_sapi_append_system_ini_entry("error_prepend_string", "Foo prepend"));
}, {
    REQUIRE(strcmp(ZAI_SAPI_INI_STR("error_prepend_string"), "Foo prepend") == 0);
})
