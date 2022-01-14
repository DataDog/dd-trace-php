extern "C" {
#include "json/json.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"

#include "zai_compat.h"
}

#include "zai_sapi/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

#define TEST_BODY(...)                           \
{                                                \
    REQUIRE(zai_sapi_sinit());                   \
    REQUIRE(zai_sapi_minit());                   \
    REQUIRE(zai_json_setup_bindings());          \
    REQUIRE(zai_sapi_rinit());                   \
    ZAI_SAPI_TSRMLS_FETCH();                     \
    ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN()   \
    { __VA_ARGS__ }                              \
    ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END()     \
    zai_sapi_spindown();                         \
}

#define TEST_JSON(description, ...) ZAI_SAPI_TEST_CASE_BARE("json", description, TEST_BODY(__VA_ARGS__))

TEST_JSON("encode", {
    smart_str buf = {0};
    zval val;

    ZVAL_LONG(&val, 42);

    zai_json_encode(&buf, &val, 0 ZAI_TSRMLS_CC);

    REQUIRE(smart_str_length(&buf) > 0);

    smart_str_free(&buf);
})

TEST_JSON("decode", {
    smart_str buf = {0};
    zval val;

    ZVAL_LONG(&val, 42);

    zai_json_encode(&buf, &val, 0 ZAI_TSRMLS_CC);
    smart_str_0(&buf);

    REQUIRE(smart_str_length(&buf));

    ZVAL_NULL(&val);

    zai_json_decode_ex(&val, smart_str_value(&buf), smart_str_length(&buf), 0, 1 ZAI_TSRMLS_CC);

    REQUIRE(Z_TYPE(val) == IS_LONG);
    REQUIRE(Z_LVAL(val) == 42);

    smart_str_free(&buf);
})

// ext/json cannot be loaded as a shared extension on PHP 8
#if PHP_VERSION_ID < 80000

#ifndef RUN_SHARED_EXTS_TESTS
#define ZAI_SAPI_TEST_CASE_TAGS "[.]"
#else
#define ZAI_SAPI_TEST_CASE_TAGS
#endif

#define TEST_BODY_SHARED(setup, ...)      \
{                                         \
    REQUIRE(zai_sapi_sinit());            \
    {                                     \
        setup                             \
    }                                     \
    REQUIRE(zai_sapi_minit());            \
    {                                     \
        __VA_ARGS__                       \
    }                                     \
    zai_sapi_mshutdown();                 \
    zai_sapi_sshutdown();                 \
}

#define TEST_JSON_SHARED(description, setup, ...) \
    ZAI_SAPI_TEST_CASE_WITH_TAGS_BARE(            \
        "json", description,                      \
        ZAI_SAPI_TEST_CASE_TAGS,                  \
    TEST_BODY_SHARED(setup, __VA_ARGS__))

TEST_JSON_SHARED("bindings fail when no json extension loaded", {
    // Disable all shared extensions
    zai_module.php_ini_ignore = 1;
},{
#if PHP_VERSION_ID >= 70000
    REQUIRE(!zend_hash_str_exists(&module_registry, "json", sizeof("json")-1));
#else
    REQUIRE(!zend_hash_exists(&module_registry, "json", sizeof("json")));
#endif
    REQUIRE(zai_json_setup_bindings() == false);
})

/* A fake extension called "json" to simulate the condition where ext/json is
 * loaded as a shared library but the symbol addresses did not resolve.
 */
// clang-format off
static zend_module_entry fake_json_ext = {
    STANDARD_MODULE_HEADER,
    "json",
    NULL,  // Functions
    NULL,  // MINIT
    NULL,  // MSHUTDOWN
    NULL,  // RINIT
    NULL,  // RSHUTDOWN
    NULL,  // Info function
    PHP_VERSION,
    STANDARD_MODULE_PROPERTIES
};
// clang-format on

TEST_JSON_SHARED("bindings fail when no json symbols resolve", {
    // Disable all shared extensions
    zai_module.php_ini_ignore = 1;
    zai_sapi_extension = fake_json_ext;
},{
#if PHP_VERSION_ID >= 70000
    REQUIRE(zend_hash_str_exists(&module_registry, "json", sizeof("json")-1));
#else
    REQUIRE(zend_hash_exists(&module_registry, "json", sizeof("json")));
#endif
    REQUIRE(zai_json_setup_bindings() == false);
})
#endif
