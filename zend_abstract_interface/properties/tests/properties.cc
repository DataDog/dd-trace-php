extern "C" {
#include "tea/sapi.h"
#include "properties/properties.h"
#include "functions/functions.h"
}

#include <catch2/catch.hpp>
#include <cstring>

#define TEST(name, code) TEST_CASE(name, "[zai properties]") { \
        REQUIRE(tea_sapi_spinup()); \
        TEA_TSRMLS_FETCH(); \
        TEA_ABORT_ON_BAILOUT_OPEN() \
        REQUIRE(tea_execute_script("./stubs/classes.php" TEA_TSRMLS_CC)); \
        { code } \
        TEA_ABORT_ON_BAILOUT_CLOSE() \
        tea_sapi_spindown(); \
    }

static inline bool zval_string_equals(zval *value, const char *str) {
    return Z_STRLEN_P(value) == strlen(str) && !strcmp(Z_STRVAL_P(value), str);
}

#if PHP_VERSION_ID < 70000
#define INIT_RETVAL(name) zval *name
#define RETPTR(name) &name
#define Z_OBJ_P(zv) zv
#else
#define INIT_RETVAL(name) zval name##_zv, *name = &name##_zv
#define RETPTR(name) name
#endif

TEST("access private property", {
    INIT_RETVAL(super);
    zai_call_function_literal("zai\\properties\\test\\super", RETPTR(super));

    zval *val = zai_read_property_direct_literal(Z_OBJCE_P(super), Z_OBJ_P(super), "private");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zval_string_equals(val, "private"));

    zval_ptr_dtor(RETPTR(super));
})

TEST("access protected property", {
    INIT_RETVAL(super);
    zai_call_function_literal("zai\\properties\\test\\super", RETPTR(super));

    zval *val = zai_read_property_direct_literal(Z_OBJCE_P(super), Z_OBJ_P(super), "protected");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zval_string_equals(val, "protected"));

    zval_ptr_dtor(RETPTR(super));
})

TEST("access public property", {
    INIT_RETVAL(super);
    zai_call_function_literal("zai\\properties\\test\\super", RETPTR(super));

    zval *val = zai_read_property_direct_literal(Z_OBJCE_P(super), Z_OBJ_P(super), "public");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zval_string_equals(val, "public"));

    zval_ptr_dtor(RETPTR(super));
})

TEST("access overridden private property on child", {
    INIT_RETVAL(child);
    zai_call_function_literal("zai\\properties\\test\\child", RETPTR(child));

    zval *val = zai_read_property_direct_literal(Z_OBJCE_P(child), Z_OBJ_P(child), "private");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zval_string_equals(val, "private from child"));

    zval_ptr_dtor(RETPTR(child));
})

TEST("access overridden private property on parent", {
    INIT_RETVAL(child);
    zai_call_function_literal("zai\\properties\\test\\child", RETPTR(child));

    zval *val = zai_read_property_direct_literal(Z_OBJCE_P(child)->parent, Z_OBJ_P(child), "private");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zval_string_equals(val, "private"));

    zval_ptr_dtor(RETPTR(child));
})

TEST("access private property on parent", {
    INIT_RETVAL(child);
    zai_call_function_literal("zai\\properties\\test\\child", RETPTR(child));

    zval *val = zai_read_property_direct_literal(Z_OBJCE_P(child)->parent, Z_OBJ_P(child), "superPrivate");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zval_string_equals(val, "superPrivate"));

    zval_ptr_dtor(RETPTR(child));
})

TEST("access public property of parent", {
    INIT_RETVAL(child);
    zai_call_function_literal("zai\\properties\\test\\child", RETPTR(child));

    zval *val = zai_read_property_direct_literal(Z_OBJCE_P(child), Z_OBJ_P(child), "public");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zval_string_equals(val, "public"));

    zval_ptr_dtor(RETPTR(child));
})

TEST("access dynamic property", {
    INIT_RETVAL(child);
    zai_call_function_literal("zai\\properties\\test\\child", RETPTR(child));

    zval *val = zai_read_property_direct_literal(Z_OBJCE_P(child), Z_OBJ_P(child), "dynamicPrivate");
    REQUIRE(Z_TYPE_P(val) == IS_STRING);
    REQUIRE(zval_string_equals(val, "dynamicPrivate from child"));

    zval_ptr_dtor(RETPTR(child));
})

TEST("access inexistent property is null", {
    INIT_RETVAL(child);
    zai_call_function_literal("zai\\properties\\test\\child", RETPTR(child));

    zval *val = zai_read_property_direct_literal(Z_OBJCE_P(child), Z_OBJ_P(child), "undefined");
    REQUIRE(val == &EG(uninitialized_zval));

    zval_ptr_dtor(RETPTR(child));
})

// PHP 5 has no concept of invalid name, only "property does not exist"
#if PHP_VERSION_ID > 70000
TEST("access property with invalid name is error", {
    INIT_RETVAL(child);
    zai_call_function_literal("zai\\properties\\test\\child", RETPTR(child));

    zval *val = zai_read_property_direct_literal(Z_OBJCE_P(child), Z_OBJ_P(child), "\0with zero byte");
    REQUIRE(val == &EG(error_zval));

    zval_ptr_dtor(RETPTR(child));
})
#endif
