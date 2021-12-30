extern "C" {
#include "json/json.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"

#include "zai_compat.h"
}

#include <catch2/catch.hpp>
#include <cstdlib>
#include <cstring>

#ifndef RUN_SHARED_EXTS_TESTS
#define SHARED_EXTS_ONLY "[.]"
#else
#define SHARED_EXTS_ONLY
#endif

#define TEST(name, code) TEST_CASE(name, "[zai_json]") { \
        REQUIRE(zai_sapi_sinit()); \
        REQUIRE(zai_sapi_minit()); \
        REQUIRE(zai_json_setup_bindings()); \
        REQUIRE(zai_sapi_rinit()); \
        ZAI_SAPI_TSRMLS_FETCH(); \
        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() \
        { code } \
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE() \
        zai_sapi_spindown(); \
    }

TEST("encode", {
    smart_str buf = {0};
    zval val;

    ZVAL_LONG(&val, 42);

    zai_json_encode(&buf, &val, 0 ZAI_TSRMLS_CC);

    REQUIRE(smart_str_length(&buf) > 0);

    smart_str_free(&buf);
})

TEST("decode", {
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
TEST_CASE("json bindings fail when no json extension loaded", "[zai_json]" SHARED_EXTS_ONLY) {
    REQUIRE(zai_sapi_sinit());
    // Disable all shared extensions
    zai_module.php_ini_ignore = 1;
    REQUIRE(zai_sapi_minit());

#if PHP_VERSION_ID >= 70000
    REQUIRE(!zend_hash_str_exists(&module_registry, "json", sizeof("json")-1));
#else
    REQUIRE(!zend_hash_exists(&module_registry, "json", sizeof("json")));
#endif
    REQUIRE(zai_json_setup_bindings() == false);

    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}

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

TEST_CASE("json bindings fail when no json symbols resolve", "[zai_json]" SHARED_EXTS_ONLY) {
    REQUIRE(zai_sapi_sinit());
    // Disable all shared extensions
    zai_module.php_ini_ignore = 1;
    zai_sapi_extension = fake_json_ext;

    REQUIRE(zai_sapi_minit());
#if PHP_VERSION_ID >= 70000
    REQUIRE(zend_hash_str_exists(&module_registry, "json", sizeof("json")-1));
#else
    REQUIRE(zend_hash_exists(&module_registry, "json", sizeof("json")));
#endif
    REQUIRE(zai_json_setup_bindings() == false);

    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}
#endif
