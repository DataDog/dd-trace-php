extern "C" {
#include "zai_sapi/zai_sapi.h"
}

#include <catch2/catch.hpp>

/**************************** Zai\trigger_error() ****************************/

TEST_CASE("trigger_error: E_CORE_ERROR", "[zai_sapi_functions]") {
    REQUIRE(zai_sapi_spinup());

    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
    zai_sapi_execute_script("./stubs/trigger_error_E_CORE_ERROR.php");
    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

    REQUIRE(zai_sapi_last_error_type_eq(E_CORE_ERROR));
    REQUIRE(zai_sapi_last_error_message_eq("My E_CORE_ERROR"));

    zai_sapi_spindown();
}

TEST_CASE("trigger_error: E_ERROR", "[zai_sapi_functions]") {
    REQUIRE(zai_sapi_spinup());

    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
    zai_sapi_execute_script("./stubs/trigger_error_E_ERROR.php");
    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

    REQUIRE(zai_sapi_last_error_type_eq(E_ERROR));
    REQUIRE(zai_sapi_last_error_message_eq("My E_ERROR"));

    zai_sapi_spindown();
}

TEST_CASE("trigger_error: E_NOTICE", "[zai_sapi_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/trigger_error_E_NOTICE.php"));

    REQUIRE(zai_sapi_last_error_type_eq(E_NOTICE));
    REQUIRE(zai_sapi_last_error_message_eq("My E_NOTICE"));

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("trigger_error: E_WARNING", "[zai_sapi_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/trigger_error_E_WARNING.php"));

    REQUIRE(zai_sapi_last_error_type_eq(E_WARNING));
    REQUIRE(zai_sapi_last_error_message_eq("My E_WARNING"));

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("trigger_error: invalid error type returns NULL", "[zai_sapi_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/trigger_error_invalid.php"));

    REQUIRE(zai_sapi_last_error_type_eq(0));
    REQUIRE(zai_sapi_last_error_message_eq(NULL));

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}
