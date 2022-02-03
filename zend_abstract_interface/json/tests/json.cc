extern "C" {
#include "json/json.h"
#include "tea/extension.h"

#include "zai_compat.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

#define TEST_BODY(...)                           \
{                                                \
    REQUIRE(tea_sapi_sinit());                   \
    REQUIRE(tea_sapi_minit());                   \
    REQUIRE(zai_json_setup_bindings());          \
    REQUIRE(tea_sapi_rinit());                   \
    TEA_TSRMLS_FETCH();                          \
    TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()        \
    { __VA_ARGS__ }                              \
    TEA_TEST_CASE_WITHOUT_BAILOUT_END()          \
    tea_sapi_spindown();                         \
}

#define TEST_JSON(description, ...) TEA_TEST_CASE_BARE("json", description, TEST_BODY(__VA_ARGS__))

TEST_JSON("encode", {
    smart_str buf = {0};
    zval val;

    ZVAL_LONG(&val, 42);

    zai_json_encode(&buf, &val, 0 TEA_TSRMLS_CC);

    REQUIRE(smart_str_length(&buf) > 0);

    smart_str_free(&buf);
})

TEST_JSON("decode", {
    smart_str buf = {0};
    zval val;

    ZVAL_LONG(&val, 42);

    zai_json_encode(&buf, &val, 0 TEA_TSRMLS_CC);
    smart_str_0(&buf);

    REQUIRE(smart_str_length(&buf));

    ZVAL_NULL(&val);

    zai_json_decode_ex(&val, smart_str_value(&buf), smart_str_length(&buf), 0, 1 TEA_TSRMLS_CC);

    REQUIRE(Z_TYPE(val) == IS_LONG);
    REQUIRE(Z_LVAL(val) == 42);

    smart_str_free(&buf);
})

// ext/json cannot be loaded as a shared extension on PHP 8
#if PHP_VERSION_ID < 80000

#ifndef RUN_SHARED_EXTS_TESTS
#define TEA_TEST_CASE_TAGS "[.]"
#else
#define TEA_TEST_CASE_TAGS
#endif

#define TEST_BODY_SHARED(setup, ...)      \
{                                         \
    REQUIRE(tea_sapi_sinit());            \
    {                                     \
        setup                             \
    }                                     \
    REQUIRE(tea_sapi_minit());            \
    {                                     \
        __VA_ARGS__                       \
    }                                     \
    tea_sapi_mshutdown();                 \
    tea_sapi_sshutdown();                 \
}

#define TEST_JSON_SHARED(description, setup, ...) \
    TEA_TEST_CASE_WITH_TAGS_BARE(            \
        "json", description,                      \
        TEA_TEST_CASE_TAGS,                  \
    TEST_BODY_SHARED(setup, __VA_ARGS__))

TEST_JSON_SHARED("bindings fail when no json extension loaded", {
    // Disable all shared extensions
    tea_sapi_module.php_ini_ignore = 1;
},{
#if PHP_VERSION_ID >= 70000
    REQUIRE(!zend_hash_str_exists(&module_registry, "json", sizeof("json")-1));
#else
    REQUIRE(!zend_hash_exists(&module_registry, "json", sizeof("json")));
#endif
    REQUIRE(zai_json_setup_bindings() == false);
})

TEST_JSON_SHARED("bindings fail when no json symbols resolve", {
    // Disable all shared extensions
    tea_sapi_module.php_ini_ignore = 1;
    // Simulate condition where json is loaded as an extension but symbols cannot be resolved
    tea_extension_name("json", sizeof("json")-1);
},{
#if PHP_VERSION_ID >= 70000
    REQUIRE(zend_hash_str_exists(&module_registry, "json", sizeof("json")-1));
#else
    REQUIRE(zend_hash_exists(&module_registry, "json", sizeof("json")));
#endif
    REQUIRE(zai_json_setup_bindings() == false);
})
#endif
