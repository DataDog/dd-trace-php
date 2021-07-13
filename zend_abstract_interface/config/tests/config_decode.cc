extern "C" {
#include "config/config_decode.h"
#include "ext_zai_config.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
}

#include <catch2/catch.hpp>
#include <cstdio>
#include <cstring>

/************************* zai_config_decode_value() **************************/

TEST_CASE("decode bool", "[zai_config_decode]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_BOOL;

    // ---

    zai_string_view truthy[] = {
        ZAI_STRL_VIEW("1"),
        ZAI_STRL_VIEW("true"),
        ZAI_STRL_VIEW("TRUE"),
        ZAI_STRL_VIEW("True"),
    };

    for (zai_string_view name : truthy) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_TRUE);
    }

    // ---

    zai_string_view falsey[] = {
        ZAI_STRL_VIEW("0"),
        ZAI_STRL_VIEW("false"),
        ZAI_STRL_VIEW("FALSE"),
        ZAI_STRL_VIEW("False"),
    };

    for (zai_string_view name : falsey) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_FALSE);
    }

    // ---

    zai_string_view errors[] = {
        ZAI_STRL_VIEW(""),
        ZAI_STRL_VIEW("yes"),
        ZAI_STRL_VIEW("no"),
        ZAI_STRL_VIEW("on"),
        ZAI_STRL_VIEW("off"),
        ZAI_STRL_VIEW("42"),
        ZAI_STRL_VIEW("-1"),
    };

    for (zai_string_view name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, ZAI_CONFIG_TYPE_BOOL, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) == IS_UNDEF);
    }

    // ---

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

typedef struct expected_double_s {
    zai_string_view name;
    double value;
} expected_double;

TEST_CASE("decode double", "[zai_config_decode]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_DOUBLE;

    // ---

    expected_double successes[] = {
        {ZAI_STRL_VIEW("0"), 0.0},
        {ZAI_STRL_VIEW("1"), 1.0},
        {ZAI_STRL_VIEW("4.2"), 4.2},
        {ZAI_STRL_VIEW("    4.2    "), 4.2},
        {ZAI_STRL_VIEW("4.   2"), 4.0},  // It's weird, but ¯\_(ツ)_/¯
    };

    for (expected_double expected : successes) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(expected.name, type, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_DOUBLE);
        REQUIRE(Z_DVAL(value) == expected.value);
    }

    // ---

    zai_string_view errors[] = {
        ZAI_STRL_VIEW(""),
        ZAI_STRL_VIEW("NaN"),
        ZAI_STRL_VIEW("INF"),
        ZAI_STRL_VIEW("foo"),
        ZAI_STRL_VIEW("4foo"),
        ZAI_STRL_VIEW("0x0"),
        ZAI_STRL_VIEW("0.0.0"),
        ZAI_STRL_VIEW("4.2.0"),
    };

    for (zai_string_view name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) == IS_UNDEF);
    }

    // ---

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

typedef struct expected_int_s {
    zai_string_view name;
    int value;
} expected_int;

TEST_CASE("decode int", "[zai_config_decode]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval value;
    bool ret;
    zai_config_type type = ZAI_CONFIG_TYPE_INT;

    // ---

    expected_int successes[] = {
        {ZAI_STRL_VIEW("0"), 0},
        {ZAI_STRL_VIEW("1"), 1},
        {ZAI_STRL_VIEW("42"), 42},
        {ZAI_STRL_VIEW("    42    "), 42},
        {ZAI_STRL_VIEW("4   2"), 4},  // It's weird, but ¯\_(ツ)_/¯
    };

    for (expected_int expected : successes) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(expected.name, type, &value, false);

        REQUIRE(ret == true);
        REQUIRE(Z_TYPE(value) == IS_LONG);
        REQUIRE(Z_LVAL(value) == expected.value);
    }

    // ---

    zai_string_view errors[] = {
        ZAI_STRL_VIEW(""),
        ZAI_STRL_VIEW("NaN"),
        ZAI_STRL_VIEW("INF"),
        ZAI_STRL_VIEW("foo"),
        ZAI_STRL_VIEW("4foo"),
        ZAI_STRL_VIEW("0x0"),
        ZAI_STRL_VIEW("0.0.0"),
        ZAI_STRL_VIEW("4.2.0"),
    };

    for (zai_string_view name : errors) {
        ZVAL_UNDEF(&value);
        ret = zai_config_decode_value(name, type, &value, false);

        REQUIRE(ret == false);
        REQUIRE(Z_TYPE(value) == IS_UNDEF);
    }

    // ---

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/******************* zai_config_decode_value() (persistent) *******************/
