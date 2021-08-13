extern "C" {
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
#include "headers/headers.h"

#include <Zend/zend_API.h>
}

#include <catch2/catch.hpp>
#include <cstring>

#define TEST(name, code) TEST_CASE(name, "[zai headers]") { \
        REQUIRE(zai_sapi_spinup()); \
        ZAI_SAPI_TSRMLS_FETCH(); \
        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() \
        { code } \
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE() \
        zai_sapi_spindown(); \
        zai_sapi_register_custom_server_variables = NULL; \
    }

static void define_server_value(zval *arr) {
    add_assoc_string(arr, "HTTP_MY_HEADER", (char *) "Datadog");
}

TEST("reading defined header value", {
    zai_sapi_register_custom_server_variables = define_server_value;

    zend_string *header;
    REQUIRE(zai_read_header_literal("MY_HEADER", &header) == ZAI_HEADER_SUCCESS);
    REQUIRE(zend_string_equals_literal(header, "Datadog"));
})

TEST("reading defined header value with autoglobals jit off", {
    REQUIRE(zai_sapi_append_system_ini_entry("auto_globals_jit", "0"));
    zai_sapi_register_custom_server_variables = define_server_value;

    zend_string *header;
    REQUIRE(zai_read_header_literal("MY_HEADER", &header) == ZAI_HEADER_SUCCESS);
    REQUIRE(zend_string_equals_literal(header, "Datadog"));
})

TEST("reading undefined header value", {
    zai_sapi_register_custom_server_variables = define_server_value;

    zend_string *header;
    REQUIRE(zai_read_header_literal("NOT_MY_HEADER", &header) == ZAI_HEADER_NOT_SET);
})

TEST("erroneous read_header input", {
    zend_string *header;
    REQUIRE(zai_read_header({ 1, nullptr }, &header) == ZAI_HEADER_ERROR);
    REQUIRE(zai_read_header({ 0, "" }, &header) == ZAI_HEADER_ERROR);
    REQUIRE(zai_read_header_literal("abc", nullptr) == ZAI_HEADER_ERROR);
})

/****************************** Access from RINIT *****************************/

zai_header_result zai_rinit_last_res;
static zend_string *zai_rinit_str;

static PHP_RINIT_FUNCTION(zai_env) {
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zai_rinit_last_res = zai_read_header_literal("MY_HEADER", &zai_rinit_str);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

TEST_CASE("get SAPI header (RINIT): defined header", "[zai headers]") {
    REQUIRE(zai_sapi_sinit());

    zai_sapi_register_custom_server_variables = define_server_value;
    zai_rinit_last_res = ZAI_HEADER_ERROR;
    zai_rinit_str = NULL;
    zai_sapi_extension.request_startup_func = PHP_RINIT(zai_env);

    REQUIRE(zai_sapi_minit());
    REQUIRE(zai_sapi_rinit());  // Env var is fetched here

    REQUIRE(zai_rinit_last_res == ZAI_HEADER_SUCCESS);
    REQUIRE(zend_string_equals_literal(zai_rinit_str, "Datadog"));

    zai_sapi_spindown();
    zai_sapi_register_custom_server_variables = NULL;
}
