extern "C" {
#include "zai_sapi/zai_sapi.h"
#include "properties/properties.h"
#include "functions/functions.h"
}

#include <catch2/catch.hpp>
#include <cstring>

#define TEST(name, code) TEST_CASE(name, "[zai properties]") { \
        REQUIRE(zai_sapi_spinup()); \
        ZAI_SAPI_TSRMLS_FETCH(); \
        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() \
        REQUIRE(zai_sapi_execute_script("./stubs/classes.php")); \
        { code } \
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE() \
        zai_sapi_spindown(); \
    }

TEST("access private property", {
    zval super;
    zai_call_function_literal("zai\\properties\\test\\super", &super);

    zval *val = zai_read_property_direct_literal(Z_OBJCE(super), Z_OBJ(super), "private");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(val), "private"));

    zval_ptr_dtor(&super);
})

TEST("access protected property", {
    zval super;
    zai_call_function_literal("zai\\properties\\test\\super", &super);

    zval *val = zai_read_property_direct_literal(Z_OBJCE(super), Z_OBJ(super), "protected");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(val), "protected"));

    zval_ptr_dtor(&super);
})

TEST("access public property", {
    zval super;
    zai_call_function_literal("zai\\properties\\test\\super", &super);

    zval *val = zai_read_property_direct_literal(Z_OBJCE(super), Z_OBJ(super), "public");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(val), "public"));

    zval_ptr_dtor(&super);
})

TEST("access overridden private property on child", {
    zval child;
    zai_call_function_literal("zai\\properties\\test\\child", &child);

    zval *val = zai_read_property_direct_literal(Z_OBJCE(child), Z_OBJ(child), "private");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(val), "private from child"));

    zval_ptr_dtor(&child);
})

TEST("access overridden private property on parent", {
    zval child;
    zai_call_function_literal("zai\\properties\\test\\child", &child);

    zval *val = zai_read_property_direct_literal(Z_OBJCE(child)->parent, Z_OBJ(child), "private");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(val), "private"));

    zval_ptr_dtor(&child);
})

TEST("access private property on parent", {
    zval child;
    zai_call_function_literal("zai\\properties\\test\\child", &child);

    zval *val = zai_read_property_direct_literal(Z_OBJCE(child)->parent, Z_OBJ(child), "superPrivate");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(val), "superPrivate"));

    zval_ptr_dtor(&child);
})

TEST("access public property of parent", {
    zval child;
    zai_call_function_literal("zai\\properties\\test\\child", &child);

    zval *val = zai_read_property_direct_literal(Z_OBJCE(child), Z_OBJ(child), "public");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(val), "public"));

    zval_ptr_dtor(&child);
})

TEST("access dynamic property", {
    zval child;
    zai_call_function_literal("zai\\properties\\test\\child", &child);

    zval *val = zai_read_property_direct_literal(Z_OBJCE(child), Z_OBJ(child), "dynamicPrivate");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zend_string_equals_literal(Z_STR_P(val), "dynamicPrivate from child"));

    zval_ptr_dtor(&child);
})

TEST("access inexistent property is null", {
    zval child;
    zai_call_function_literal("zai\\properties\\test\\child", &child);

    zval *val = zai_read_property_direct_literal(Z_OBJCE(child), Z_OBJ(child), "undefined");
    REQUIRE(val == &EG(uninitialized_zval));

    zval_ptr_dtor(&child);
})

TEST("access property with invalid name is error", {
    zval child;
    zai_call_function_literal("zai\\properties\\test\\child", &child);

    zval *val = zai_read_property_direct_literal(Z_OBJCE(child), Z_OBJ(child), "\0with zero byte");
    REQUIRE(val == &EG(error_zval));

    zval_ptr_dtor(&child);
})
