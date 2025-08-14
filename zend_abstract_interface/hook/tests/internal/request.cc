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

    zai_str zai_hook_test_nonexistent = ZAI_STRL("zai_hook_test_nonexistent");

    static zai_hook_test_fixed_t zai_hook_test_fixed_first = {42};
    static zai_hook_test_fixed_t zai_hook_test_fixed_second = {42};

    static bool zai_hook_test_begin(zend_ulong invocation, zend_execute_data *ex, void *fixed, void *dynamic) {
        zai_hook_test_begin_fixed   = fixed;
        zai_hook_test_begin_dynamic = dynamic;

        CHECK(((zai_hook_test_dynamic_t *)dynamic)->u == 0);

        zai_hook_test_begin_check++;

        return zai_hook_test_begin_return;
    }

    static void zai_hook_test_end(zend_ulong invocation, zend_execute_data *ex, zval *rv, void *fixed, void *dynamic) {
        zai_hook_test_end_fixed   = fixed;
        zai_hook_test_end_dynamic = dynamic;

        zai_hook_test_end_check++;
    }
}

static zai_str zai_hook_test_target = ZAI_STRL("phpversion");
static zend_long zai_hook_test_index;

#define HOOK_TEST_CASE(description, statics, request, ...) \
    TEA_TEST_CASE_BARE("hook/internal/request", description, {  \
        REQUIRE(tea_sapi_sinit());                              \
        REQUIRE(tea_sapi_minit());                              \
        REQUIRE(zai_hook_minit());                              \
        REQUIRE(zai_hook_ginit());                              \
        zend_execute_internal_function = zend_execute_internal; \
        if (!zend_execute_internal_function) {                  \
            zend_execute_internal_function = execute_internal;  \
        }                                                       \
        zend_execute_internal =                                 \
            zai_hook_test_execute_internal;                     \
        { statics }                                             \
        REQUIRE(tea_sapi_rinit());                              \
        REQUIRE(zai_hook_rinit());                              \
        zai_hook_activate();                                    \
        { request }                                             \
        TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()                   \
        { __VA_ARGS__ }                                         \
        TEA_TEST_CASE_WITHOUT_BAILOUT_END()                     \
        zai_hook_rshutdown();                                   \
        tea_sapi_rshutdown();                                   \
        zai_hook_gshutdown();                                   \
        zai_hook_mshutdown();                                   \
        tea_sapi_mshutdown();                                   \
        tea_sapi_sshutdown();                                   \
    })

HOOK_TEST_CASE("continue", { /* no static */ }, {
    zai_hook_test_reset(true);

    REQUIRE(zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_first, NULL),
        sizeof(zai_hook_test_dynamic_t)) != -1);
}, {
    zval result;

    CHECK(zai_test_call_global_with_0_params(&zai_hook_test_target, &result));

    CHECK(zai_hook_test_begin_check == 1);
    CHECK(zai_hook_test_end_check == 1);

    CHECK(zai_hook_test_begin_dynamic == zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_first);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_first);

    zval_ptr_dtor(&result);
});

HOOK_TEST_CASE("stop", { /* no static */ }, {
    zai_hook_test_reset(false);

    REQUIRE(zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_first, NULL),
        sizeof(zai_hook_test_dynamic_t)) != -1);
}, {
    zval result;

    CHECK(!zai_test_call_global_with_0_params(&zai_hook_test_target, &result));

    CHECK(zai_hook_test_begin_check == 1);
    CHECK(zai_hook_test_end_check == 1);

    CHECK(zai_hook_test_begin_dynamic == zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_first);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_first);

    zval_ptr_dtor(&result);
});

HOOK_TEST_CASE("multiple continue", { /* no static */ }, {
    zai_hook_test_reset(true);

    REQUIRE(zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_first, NULL),
        sizeof(zai_hook_test_dynamic_t)) != -1);

    REQUIRE(zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_second, NULL),
        sizeof(zai_hook_test_dynamic_t)) != -1);
}, {
    zval result;

    CHECK(zai_test_call_global_with_0_params(&zai_hook_test_target, &result));

    CHECK(zai_hook_test_begin_check == 2);
    CHECK(zai_hook_test_end_check == 2);

    CHECK(zai_hook_test_begin_dynamic != zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_second);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_first);

    zval_ptr_dtor(&result);
});

HOOK_TEST_CASE("multiple stop", { /* no static */ }, {
    zai_hook_test_reset(false);

    REQUIRE(zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_first, NULL),
        sizeof(zai_hook_test_dynamic_t)) != -1);

    REQUIRE(zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_second, NULL),
        sizeof(zai_hook_test_dynamic_t)) != -1);
}, {
    zval result;

    CHECK(!zai_test_call_global_with_0_params(&zai_hook_test_target, &result));

    CHECK(zai_hook_test_begin_check == 1);
    CHECK(zai_hook_test_end_check == 1);

    CHECK(zai_hook_test_begin_dynamic == zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_first);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_first);

    zval_ptr_dtor(&result);
});

HOOK_TEST_CASE("continue with static", {
    zai_hook_test_reset(true);

    REQUIRE(zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_first, NULL),
        sizeof(zai_hook_test_dynamic_t)) != -1);
}, {
    REQUIRE(zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_second, NULL),
        sizeof(zai_hook_test_dynamic_t)) != -1);
}, {
    zval result;

    CHECK(zai_test_call_global_with_0_params(&zai_hook_test_target, &result));

    CHECK(zai_hook_test_begin_check == 2);
    CHECK(zai_hook_test_end_check == 2);

    CHECK(zai_hook_test_begin_dynamic != zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_second);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_first);

    zval_ptr_dtor(&result);
});

HOOK_TEST_CASE("stop with static", {
    zai_hook_test_reset(false);

    REQUIRE(zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_first, NULL),
        sizeof(zai_hook_test_dynamic_t)) != -1);
}, {
    REQUIRE(zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_second, NULL),
        sizeof(zai_hook_test_dynamic_t)) != -1);
}, {
    zval result;

    CHECK(!zai_test_call_global_with_0_params(&zai_hook_test_target, &result));

    CHECK(zai_hook_test_begin_check == 1);
    CHECK(zai_hook_test_end_check == 1);

    CHECK(zai_hook_test_begin_dynamic == zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_first);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_first);

    zval_ptr_dtor(&result);
});

HOOK_TEST_CASE("nonexistent removal", {
    zai_hook_test_reset(false);
}, {
}, {
    REQUIRE(!zai_hook_remove(ZAI_STR_EMPTY, zai_hook_test_target, 0));
});

HOOK_TEST_CASE("resolved removal", {
    zai_hook_test_reset(false);
}, {
    zai_hook_test_index = zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_first, NULL),
        sizeof(zai_hook_test_dynamic_t));

    REQUIRE(zai_hook_test_index != -1);
}, {
    REQUIRE(zai_hook_remove(ZAI_STR_EMPTY, zai_hook_test_target, zai_hook_test_index));

    zval result;

    CHECK(zai_test_call_global_with_0_params(&zai_hook_test_target, &result));

    CHECK(zai_hook_test_begin_check == 0);
    CHECK(zai_hook_test_end_check == 0);

    zval_ptr_dtor(&result);
});

HOOK_TEST_CASE("unresolved removal", {
    zai_hook_test_reset(false);
}, {
    zai_hook_test_index = zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_nonexistent,
        zai_hook_test_begin,
        zai_hook_test_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_first, NULL),
        sizeof(zai_hook_test_dynamic_t));

    REQUIRE(zai_hook_test_index != -1);
}, {
    REQUIRE(zai_hook_remove(ZAI_STR_EMPTY, zai_hook_test_nonexistent, zai_hook_test_index));
});

extern "C" {
    static zai_hook_test_dynamic_t zai_hook_add_test_begin_dynamic = {42};

    static bool zai_hook_test_hook_add_begin(zend_ulong invocation, zend_execute_data *ex, void *fixed, void *dynamic_ptr) {
        zai_hook_test_dynamic_t *dynamic = (zai_hook_test_dynamic_t *)dynamic_ptr;
        CHECK(dynamic->u == 0);
        dynamic->u = 1;

        REQUIRE(zai_hook_install(
            ZAI_STR_EMPTY,
            zai_hook_test_target,
            zai_hook_test_begin,
            zai_hook_test_end,
            ZAI_HOOK_AUX(&zai_hook_test_fixed_first, NULL),
            sizeof(zai_hook_test_dynamic_t)) != -1);

        REQUIRE(zai_hook_install(
            ZAI_STR_EMPTY,
            zai_hook_test_target,
            zai_hook_test_begin,
            zai_hook_test_end,
            ZAI_HOOK_AUX(&zai_hook_test_fixed_second, NULL),
            sizeof(zai_hook_test_dynamic_t)) != -1);

        CHECK(dynamic->u == 1);
        dynamic->u = 2;

        zai_hook_test_begin_check++;
        return true;
    }

    static void zai_hook_test_hook_add_end(zend_ulong invocation, zend_execute_data *ex, zval *rv, void *fixed, void *dynamic) {
        zai_hook_add_test_begin_dynamic = *(zai_hook_test_dynamic_t *)dynamic;

        CHECK(zai_hook_test_end_check == 2); // called last
        zai_hook_test_end_check++;
    }
}

HOOK_TEST_CASE("hook add during begin", {
    zai_hook_test_reset(true);
}, {
    zai_hook_test_index = zai_hook_install(
        ZAI_STR_EMPTY,
        zai_hook_test_target,
        zai_hook_test_hook_add_begin,
        zai_hook_test_hook_add_end,
        ZAI_HOOK_AUX(&zai_hook_test_fixed_first, NULL),
        sizeof(zai_hook_test_dynamic_t));

    REQUIRE(zai_hook_test_index != -1);
}, {
    zval result;

    CHECK(zai_test_call_global_with_0_params(&zai_hook_test_target, &result));

    CHECK(zai_hook_test_begin_check == 3);
    CHECK(zai_hook_test_end_check == 3);

    CHECK(zai_hook_test_begin_dynamic != zai_hook_test_end_dynamic);

    CHECK(zai_hook_test_begin_fixed == &zai_hook_test_fixed_second);
    CHECK(zai_hook_test_end_fixed == &zai_hook_test_fixed_first);

    CHECK(zai_hook_add_test_begin_dynamic.u == 2);

    zval_ptr_dtor(&result);
});
