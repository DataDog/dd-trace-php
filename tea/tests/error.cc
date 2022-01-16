extern "C" {
#include <include/sapi.h>
#include <include/error.h>
}

#include <include/testing/catch2.hpp>

/**************************** tea\trigger_error() ****************************/

TEA_TEST_CASE("tea/error", "trigger_error: E_CORE_ERROR", {
    TEA_TEST_CODE_WITH_BAILOUT({
        tea_execute_script("./stubs/trigger_error_E_CORE_ERROR.php" TEA_TSRMLS_CC);
    });

    REQUIRE(tea_error_eq(E_CORE_ERROR, "My E_CORE_ERROR" TEA_TSRMLS_CC));
})

TEA_TEST_CASE("tea/error", "trigger_error: E_ERROR", {
    TEA_TEST_CODE_WITH_BAILOUT({
        tea_execute_script("./stubs/trigger_error_E_ERROR.php" TEA_TSRMLS_CC);
    });

    REQUIRE(tea_error_eq(E_ERROR, "My E_ERROR" TEA_TSRMLS_CC));
})

TEA_TEST_CASE_WITH_STUB(
    "tea/error", "trigger_error: E_NOTICE",
    "./stubs/trigger_error_E_NOTICE.php", {
    REQUIRE(tea_error_eq(E_NOTICE, "My E_NOTICE" TEA_TSRMLS_CC));
})

TEA_TEST_CASE_WITH_STUB(
    "tea/error", "trigger_error: E_WARNING",
    "./stubs/trigger_error_E_WARNING.php", {
    REQUIRE(tea_error_eq(E_WARNING, "My E_WARNING" TEA_TSRMLS_CC));
})

TEA_TEST_CASE_WITH_STUB(
    "tea/error", "trigger_error: invalid error type returns NULL",
    "./stubs/trigger_error_invalid.php", {
    REQUIRE(tea_error_is_empty(TEA_TSRMLS_C));
})
