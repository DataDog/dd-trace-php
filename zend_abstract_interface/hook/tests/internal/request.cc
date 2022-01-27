extern "C" {
    static bool  zai_hook_test_begin_return;
    static int   zai_hook_test_begin_check;
    static int   zai_hook_test_end_check;

    static void* zai_hook_test_begin_dynamic;
    static void* zai_hook_test_end_dynamic;

    static void* zai_hook_test_begin_fixed;
    static void* zai_hook_test_end_fixed;
}

#include "zai_tests_internal.hpp"

extern "C" {
    typedef struct {
        uint32_t u;
    } zai_hook_test_dynamic_t;

    typedef struct {
        uint32_t u;
    } zai_hook_test_fixed_t;

    static zai_hook_test_fixed_t zai_hook_test_fixed_first = {42};
    static zai_hook_test_fixed_t zai_hook_test_fixed_second = {42};

    static bool zai_hook_test_begin(zend_execute_data *ex, zai_hook_test_fixed_t *fixed, zai_hook_test_dynamic_t *dynamic TEA_TSRMLS_DC) {
        zai_hook_test_begin_fixed   = fixed;
        zai_hook_test_begin_dynamic = dynamic;

        zai_hook_test_begin_check++;

        return zai_hook_test_begin_return;
    }

    static void zai_hook_test_end(zend_execute_data *ex, zval *rv, zai_hook_test_fixed_t *fixed, zai_hook_test_dynamic_t *dynamic TEA_TSRMLS_DC) {
        zai_hook_test_end_fixed   = fixed;
        zai_hook_test_end_dynamic = dynamic;

        zai_hook_test_end_check++;
    }
}

static zai_string_view zai_hook_test_target = ZAI_STRL_VIEW("phpversion");

#define HOOK_TEST_CASE(description, statics, request, ...) \
    TEA_TEST_CASE_BARE("hook/internal/request", description, {  \
        REQUIRE(tea_sapi_sinit());                              \
        REQUIRE(tea_sapi_minit());                              \
        REQUIRE(zai_hook_minit());                              \
        zend_execute_internal_function = zend_execute_internal; \
        if (!zend_execute_internal_function) {                  \
            zend_execute_internal_function = execute_internal;  \
        }                                                       \
        zend_execute_internal =                                 \
            zai_hook_test_execute_internal;                     \
        TEA_TSRMLS_FETCH();                                     \
        { statics }                                             \
        REQUIRE(tea_sapi_rinit());                              \
        REQUIRE(zai_hook_rinit());                              \
        { request }                                             \
        TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()                   \
        { __VA_ARGS__ }                                         \
        TEA_TEST_CASE_WITHOUT_BAILOUT_END()                     \
        zai_hook_rshutdown();                                   \
        tea_sapi_rshutdown();                                   \
        zai_hook_mshutdown();                                   \
        tea_sapi_mshutdown();                                   \
        tea_sapi_sshutdown();                                   \
    })

HOOK_TEST_CASE("continue", { /* no static */ }, {
    zai_hook_test_reset(true);

    REQUIRE(zai_hook_install(
        ZAI_HOOK_INTERNAL,
        ZAI_STRING_EMPTY,
        zai_hook_test_target,
        ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin),
        ZAI_HOOK_END_INTERNAL(zai_hook_test_end),
        &zai_hook_test_fixed_first,
        sizeof(zai_hook_test_dynamic_t) TEA_TSRMLS_CC));
}, {
    zval *result;
    ZAI_VALUE_INIT(result);

    zai_hook_resolve(TEA_TSRMLS_C);

    CHECK(zai_symbol_call(
        ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
        ZAI_SYMBOL_FUNCTION_NAMED, &zai_hook_test_target,
        &result TEA_TSRMLS_CC, 0));

    CHECK(zai_hook_test_begin_check == 1);
    CHECK(zai_hook_test_end_check == 1);

    CHECK(zai_hook_test_begin_dynamic == zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_first);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_first);

    ZAI_VALUE_DTOR(result);
});

HOOK_TEST_CASE("stop", { /* no static */ }, {
    zai_hook_test_reset(false);

    REQUIRE(zai_hook_install(
        ZAI_HOOK_INTERNAL,
        ZAI_STRING_EMPTY,
        zai_hook_test_target,
        ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin),
        ZAI_HOOK_END_INTERNAL(zai_hook_test_end),
        &zai_hook_test_fixed_first,
        sizeof(zai_hook_test_dynamic_t) TEA_TSRMLS_CC));
}, {
    zval *result;
    ZAI_VALUE_INIT(result);

    zai_hook_resolve(TEA_TSRMLS_C);

    CHECK(!zai_symbol_call(
        ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
        ZAI_SYMBOL_FUNCTION_NAMED, &zai_hook_test_target,
        &result TEA_TSRMLS_CC, 0));

    CHECK(zai_hook_test_begin_check == 1);
    CHECK(zai_hook_test_end_check == 1);

    CHECK(zai_hook_test_begin_dynamic == zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_first);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_first);

    ZAI_VALUE_DTOR(result);
});

HOOK_TEST_CASE("multiple continue", { /* no static */ }, {
    zai_hook_test_reset(true);

    REQUIRE(zai_hook_install(
        ZAI_HOOK_INTERNAL,
        ZAI_STRING_EMPTY,
        zai_hook_test_target,
        ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin),
        ZAI_HOOK_END_INTERNAL(zai_hook_test_end),
        &zai_hook_test_fixed_first,
        sizeof(zai_hook_test_dynamic_t) TEA_TSRMLS_CC));

    REQUIRE(zai_hook_install(
        ZAI_HOOK_INTERNAL,
        ZAI_STRING_EMPTY,
        zai_hook_test_target,
        ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin),
        ZAI_HOOK_END_INTERNAL(zai_hook_test_end),
        &zai_hook_test_fixed_second,
        sizeof(zai_hook_test_dynamic_t) TEA_TSRMLS_CC));
}, {
    zval *result;
    ZAI_VALUE_INIT(result);

    zai_hook_resolve(TEA_TSRMLS_C);

    CHECK(zai_symbol_call(
        ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
        ZAI_SYMBOL_FUNCTION_NAMED, &zai_hook_test_target,
        &result TEA_TSRMLS_CC, 0));

    CHECK(zai_hook_test_begin_check == 2);
    CHECK(zai_hook_test_end_check == 2);

    CHECK(zai_hook_test_begin_dynamic == zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_second);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_second);

    ZAI_VALUE_DTOR(result);
});

HOOK_TEST_CASE("multiple stop", { /* no static */ }, {
    zai_hook_test_reset(false);

    REQUIRE(zai_hook_install(
        ZAI_HOOK_INTERNAL,
        ZAI_STRING_EMPTY,
        zai_hook_test_target,
        ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin),
        ZAI_HOOK_END_INTERNAL(zai_hook_test_end),
        &zai_hook_test_fixed_first,
        sizeof(zai_hook_test_dynamic_t) TEA_TSRMLS_CC));

    REQUIRE(zai_hook_install(
        ZAI_HOOK_INTERNAL,
        ZAI_STRING_EMPTY,
        zai_hook_test_target,
        ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin),
        ZAI_HOOK_END_INTERNAL(zai_hook_test_end),
        &zai_hook_test_fixed_second,
        sizeof(zai_hook_test_dynamic_t) TEA_TSRMLS_CC));
}, {
    zval *result;
    ZAI_VALUE_INIT(result);

    zai_hook_resolve(TEA_TSRMLS_C);

    CHECK(!zai_symbol_call(
        ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
        ZAI_SYMBOL_FUNCTION_NAMED, &zai_hook_test_target,
        &result TEA_TSRMLS_CC, 0));

    CHECK(zai_hook_test_begin_check == 1);
    CHECK(zai_hook_test_end_check == 2);

    CHECK(zai_hook_test_begin_dynamic != zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_first);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_second);

    ZAI_VALUE_DTOR(result);
});

HOOK_TEST_CASE("continue with static", {
    zai_hook_test_reset(true);

    REQUIRE(zai_hook_install(
        ZAI_HOOK_INTERNAL,
        ZAI_STRING_EMPTY,
        zai_hook_test_target,
        ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin),
        ZAI_HOOK_END_INTERNAL(zai_hook_test_end),
        &zai_hook_test_fixed_first,
        sizeof(zai_hook_test_dynamic_t) TEA_TSRMLS_CC));
}, {
    REQUIRE(zai_hook_install(
        ZAI_HOOK_INTERNAL,
        ZAI_STRING_EMPTY,
        zai_hook_test_target,
        ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin),
        ZAI_HOOK_END_INTERNAL(zai_hook_test_end),
        &zai_hook_test_fixed_second,
        sizeof(zai_hook_test_dynamic_t) TEA_TSRMLS_CC));
}, {
    zval *result;
    ZAI_VALUE_INIT(result);

    zai_hook_resolve(TEA_TSRMLS_C);

    CHECK(zai_symbol_call(
        ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
        ZAI_SYMBOL_FUNCTION_NAMED, &zai_hook_test_target,
        &result TEA_TSRMLS_CC, 0));

    CHECK(zai_hook_test_begin_check == 2);
    CHECK(zai_hook_test_end_check == 2);

    CHECK(zai_hook_test_begin_dynamic == zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_second);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_second);

    ZAI_VALUE_DTOR(result);
});

HOOK_TEST_CASE("stop with static", {
    zai_hook_test_reset(false);

    REQUIRE(zai_hook_install(
        ZAI_HOOK_INTERNAL,
        ZAI_STRING_EMPTY,
        zai_hook_test_target,
        ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin),
        ZAI_HOOK_END_INTERNAL(zai_hook_test_end),
        &zai_hook_test_fixed_first,
        sizeof(zai_hook_test_dynamic_t) TEA_TSRMLS_CC));
}, {
    REQUIRE(zai_hook_install(
        ZAI_HOOK_INTERNAL,
        ZAI_STRING_EMPTY,
        zai_hook_test_target,
        ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin),
        ZAI_HOOK_END_INTERNAL(zai_hook_test_end),
        &zai_hook_test_fixed_second,
        sizeof(zai_hook_test_dynamic_t) TEA_TSRMLS_CC));
}, {
    zval *result;
    ZAI_VALUE_INIT(result);

    zai_hook_resolve(TEA_TSRMLS_C);

    CHECK(!zai_symbol_call(
        ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
        ZAI_SYMBOL_FUNCTION_NAMED, &zai_hook_test_target,
        &result TEA_TSRMLS_CC, 0));

    CHECK(zai_hook_test_begin_check == 1);
    CHECK(zai_hook_test_end_check == 2);

    CHECK(zai_hook_test_begin_dynamic != zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_first);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_second);

    ZAI_VALUE_DTOR(result);
});
