extern "C" {
#include "zai_sapi/zai_sapi.h"
}

#include "zai_sapi/zai_sapi_test.hpp"

/**************************** Zai\trigger_error() ****************************/

ZAI_SAPI_TEST_CASE("zai_sapi/functions", "trigger_error: E_CORE_ERROR", {
    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
    zai_sapi_execute_script("./stubs/trigger_error_E_CORE_ERROR.php");
    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_CORE_ERROR, "My E_CORE_ERROR"));
})

ZAI_SAPI_TEST_CASE("zai_sapi/functions", "trigger_error: E_ERROR", {
    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
    zai_sapi_execute_script("./stubs/trigger_error_E_ERROR.php");
    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_ERROR, "My E_ERROR"));
})

ZAI_SAPI_TEST_CASE_WITH_STUB(
    "zai_sapi/functions", "trigger_error: E_NOTICE",
    "./stubs/trigger_error_E_NOTICE.php", {
    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "My E_NOTICE"));
})

ZAI_SAPI_TEST_CASE_WITH_STUB(
    "zai_sapi/functions", "trigger_error: E_WARNING",
    "./stubs/trigger_error_E_WARNING.php", {
    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "My E_WARNING"));
})

ZAI_SAPI_TEST_CASE_WITH_STUB(
    "zai_sapi/functions", "trigger_error: invalid error type returns NULL",
    "./stubs/trigger_error_invalid.php", {
    REQUIRE(zai_sapi_last_error_is_empty());
})
