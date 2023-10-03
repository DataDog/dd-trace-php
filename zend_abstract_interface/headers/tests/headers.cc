extern "C" {
#include "tea/sapi.h"
#include "tea/extension.h"
#include "headers/headers.h"

#include <Zend/zend_API.h>
}

#include "tea/testing/catch2.hpp"
#include <cstring>

static void define_server_value(zval *arr) {
    add_assoc_string(arr, "HTTP_MY_HEADER", (char *) "Datadog");
}

TEA_TEST_CASE_WITH_PROLOGUE("headers", "reading defined header value", {
    tea_sapi_register_custom_server_variables = define_server_value;
},
{
    zend_string *header;
    REQUIRE(zai_read_header_literal("MY_HEADER", &header) == ZAI_HEADER_SUCCESS);
    REQUIRE(zend_string_equals_literal(header, "Datadog"));
})

TEA_TEST_CASE_WITH_PROLOGUE("headers", "reading defined header value with autoglobals jit off", {
    tea_sapi_register_custom_server_variables = define_server_value;
},{
    REQUIRE(tea_sapi_append_system_ini_entry("auto_globals_jit", "0"));

    zend_string *header;
    REQUIRE(zai_read_header_literal("MY_HEADER", &header) == ZAI_HEADER_SUCCESS);
    REQUIRE(zend_string_equals_literal(header, "Datadog"));
})

TEA_TEST_CASE_WITH_PROLOGUE("headers", "reading undefined header value", {
    tea_sapi_register_custom_server_variables = define_server_value;
},{
    zend_string *header;
    REQUIRE(zai_read_header_literal("NOT_MY_HEADER", &header) == ZAI_HEADER_NOT_SET);
})

TEA_TEST_CASE("headers", "erroneous read_header input", {
    zend_string *header;
    REQUIRE(zai_read_header(ZAI_STR_EMPTY, &header) == ZAI_HEADER_ERROR);
    REQUIRE(zai_read_header_literal("abc", nullptr) == ZAI_HEADER_ERROR);
})

/****************************** Access from RINIT *****************************/

zai_header_result zai_rinit_last_res;
static zend_string *zai_rinit_str;

static PHP_RINIT_FUNCTION(zai_env) {
#if PHP_VERSION_ID >= 80000
    zend_result result = SUCCESS;
#else
    int result = SUCCESS;
#endif

    zend_try {
        zai_rinit_last_res = zai_read_header_literal("MY_HEADER", &zai_rinit_str);
    } zend_catch {
        result = FAILURE;
    } zend_end_try();

    return result;
}

TEA_TEST_CASE_WITH_PROLOGUE("headers", "get SAPI header (RINIT): defined header", {
    tea_sapi_register_custom_server_variables = define_server_value;
    zai_rinit_last_res = ZAI_HEADER_ERROR;
    zai_rinit_str = {0};
    tea_extension_rinit(PHP_RINIT(zai_env));
},{
    // Env var is fetched in rinit
    REQUIRE(zai_rinit_last_res == ZAI_HEADER_SUCCESS);
    REQUIRE(zend_string_equals_literal(zai_rinit_str, "Datadog"));
})
