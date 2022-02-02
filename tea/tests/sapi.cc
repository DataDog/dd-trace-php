#include <include/testing/catch2.hpp>
#include <cstring>

TEA_TEST_CASE("tea/sapi", "eval", {
    REQUIRE(TEA_EVAL_STR("echo 'Hello world' . PHP_EOL;") == SUCCESS);
})

TEA_TEST_CASE_WITH_STUB("tea/sapi", "execute", "./stubs/basic.php", {})

TEA_TEST_BAILING_CASE("tea/sapi", "execute non-existent", {
    tea_execute_script("./this_does_not_exist.php" TEA_TSRMLS_CC);
});

TEA_TEST_CASE("tea/sapi", "hardcoded ini", {
    REQUIRE(strcmp(TEA_INI_STR("html_errors"), "0") == 0);
    REQUIRE(strcmp(TEA_INI_STR("implicit_flush"), "1") == 0);
    REQUIRE(strcmp(TEA_INI_STR("output_buffering"), "0") == 0);
})

TEA_TEST_CASE_WITH_PROLOGUE("tea/sapi", "overwrite hardcoded ini", {
    REQUIRE(tea_sapi_append_system_ini_entry("html_errors", "1"));
}, {
    char *result = TEA_INI_STR("html_errors");

    REQUIRE(result);
    REQUIRE(strcmp(result, "1") == 0);
})

TEA_TEST_CASE_WITH_PROLOGUE("tea/sapi", "access modified ini", {
    REQUIRE(tea_sapi_append_system_ini_entry("error_prepend_string", "Foo prepend"));
}, {
    char *result = TEA_INI_STR("error_prepend_string");

    REQUIRE(result);
    REQUIRE(strcmp(result, "Foo prepend") == 0);
})
