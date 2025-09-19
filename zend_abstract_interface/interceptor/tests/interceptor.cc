#include <tea/testing/catch2.hpp>
#include <sandbox/tests/zai_tests_common.hpp>

extern "C" {
#include <hook/hook.h>
#include <sandbox/sandbox.h>
#include <tea/extension.h>
#include <ext/standard/basic_functions.h>
#if PHP_VERSION_ID >= 80000
#include <interceptor/php8/interceptor.h>
#else
#include <interceptor/php7/interceptor.h>
#endif

    static PHP_MINIT_FUNCTION(ddtrace_testing_hook) {
        zai_hook_minit();
        zai_hook_ginit();
        return SUCCESS;
    }

    static PHP_RINIT_FUNCTION(ddtrace_testing_hook) {
        zai_hook_rinit();
        /* activates should be done in zend_extension's activate handler, but
         * for this test it doesn't matter.
         */
        zai_hook_activate();
        zai_interceptor_activate();
#if PHP_VERSION_ID < 80000
        zai_interceptor_rinit();
#endif
        return SUCCESS;
    }

    static PHP_RSHUTDOWN_FUNCTION(ddtrace_testing_hook) {
        zai_hook_rshutdown();
        /* deactivate should be done in zend_extension's dactivate handler,
         * but for this test it doesn't matter.
         */
        zai_interceptor_deactivate();
        return SUCCESS;
    }

    static PHP_MSHUTDOWN_FUNCTION(ddtrace_testing_hook) {
        zai_hook_gshutdown();
        zai_hook_mshutdown();
        return SUCCESS;
    }

    static int ddtrace_testing_startup() {
#if PHP_VERSION_ID < 80000
        zai_interceptor_startup(tea_extension_dummy());
#else
        zai_interceptor_startup();
#endif
        return SUCCESS;
    }

    static void init_interceptor_test() {
#if PHP_VERSION_ID < 80000
        tea_extension_op_array_ctor(zai_interceptor_op_array_ctor);
        tea_extension_op_array_handler(zai_interceptor_op_array_pass_two);
#endif
        tea_extension_startup(ddtrace_testing_startup);
        tea_extension_minit(PHP_MINIT(ddtrace_testing_hook));
        tea_extension_rinit(PHP_RINIT(ddtrace_testing_hook));
        tea_extension_mshutdown(PHP_MSHUTDOWN(ddtrace_testing_hook));
        tea_extension_rshutdown(PHP_RSHUTDOWN(ddtrace_testing_hook));
    }
}

static bool zai_hook_test_begin_return;
static int zai_hook_test_begin_invocations;
static int zai_hook_test_resumption_invocations;
static int zai_hook_test_yield_invocations;
static int zai_hook_test_end_invocations;
static int zai_hook_test_end_has_exception;
static zval zai_hook_test_last_rv;
static void reset_interceptor_test_globals() {
    zai_hook_test_begin_return = true;
    zai_hook_test_begin_invocations = 0;
    zai_hook_test_resumption_invocations = 0;
    zai_hook_test_yield_invocations = 0;
    zai_hook_test_end_invocations = 0;
    zai_hook_test_end_has_exception = 0;
    Z_TYPE_INFO(zai_hook_test_last_rv) = 0xFF;
}

static bool zai_hook_test_begin(zend_ulong invocation, zend_execute_data *ex, void *fixed, void *dynamic) {
    if (dynamic) {
        *(int32_t *)dynamic = 0;
    }
    ++zai_hook_test_begin_invocations;
    return zai_hook_test_begin_return;
}

static void zai_hook_test_end(zend_ulong invocation, zend_execute_data *ex, zval *rv, void *fixed, void *dynamic) {
    ++zai_hook_test_end_invocations;
    ZVAL_COPY_VALUE(&zai_hook_test_last_rv, rv);
    if (EG(exception)) {
        ++zai_hook_test_end_has_exception;
    }
}

static void zai_hook_test_resume(zend_ulong invocation, zend_execute_data *ex, zval *sent, void *fixed, void *dynamic) {
    ++zai_hook_test_resumption_invocations;
}

static void zai_hook_test_yield_ascending(zend_ulong invocation, zend_execute_data *ex, zval *key, zval *value, void *fixed, void *dynamic) {
    REQUIRE(Z_TYPE_P(key) == IS_LONG);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == zai_hook_test_yield_invocations);
    ++zai_hook_test_yield_invocations;
}

#define INTERCEPTOR_TEST_CASE(description, ...) \
    TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE(     \
        "interceptor", description,     \
        "./stubs/stub.php",                                  \
        init_interceptor_test(); reset_interceptor_test_globals();,                    \
        __VA_ARGS__)

#define INSTALL_HOOK(fn) INSTALL_CLASS_HOOK("", fn)
#define INSTALL_CLASS_HOOK(class, fn) REQUIRE(zai_hook_install( \
    ZAI_STRL(class), \
    ZAI_STRL(fn), \
    zai_hook_test_begin, \
    zai_hook_test_end, \
    ZAI_HOOK_AUX(NULL, NULL), \
    4) != -1)
#define INSTALL_GENERATOR_HOOK(fn, resume, yield) REQUIRE(zai_hook_install_generator( \
                                               ZAI_STR_EMPTY, \
                                               ZAI_STRL(fn), \
                                               zai_hook_test_begin,    \
                                               resume, \
                                               yield, \
                                               zai_hook_test_end, \
                                               ZAI_HOOK_AUX(NULL, NULL), \
                                               4) != -1)
#define CALL_FN(fn, ...) do { \
    zval result; \
    zai_str _fn_name = ZAI_STRL(fn);               \
    REQUIRE(zai_test_call_global_with_0_params(_fn_name, &result)); \
    __VA_ARGS__               \
    zval_ptr_dtor(&result);                          \
} while (0)

INTERCEPTOR_TEST_CASE("empty user function intercepting", {
    INSTALL_HOOK("to_intercept");
    CALL_FN("to_intercept");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("function intercepting after initial call", {
    CALL_FN("to_intercept");
    INSTALL_HOOK("to_intercept");
    CHECK(zai_hook_test_begin_invocations == 0);
    CHECK(zai_hook_test_end_invocations == 0);
    CALL_FN("to_intercept");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("user function intercepting returns value", {
    INSTALL_HOOK("returns");
    CALL_FN("returns");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_STRING);
});

INTERCEPTOR_TEST_CASE("user function throws", {
    INSTALL_HOOK("throws");
    CALL_FN("wrap_throws");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(zai_hook_test_end_has_exception == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("user function with caught exception", {
    INSTALL_HOOK("functionDoesNotThrow");
    CALL_FN("functionDoesNotThrow");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(zai_hook_test_end_has_exception == 0);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_LONG);
});

INTERCEPTOR_TEST_CASE("user function throws despite catch blocks", {
    INSTALL_HOOK("functionDoesThrow");
    CALL_FN("runFunctionDoesThrow");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(zai_hook_test_end_has_exception == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("user function throws despite finally blocks", {
    INSTALL_HOOK("functionWithFinallyDoesThrow");
    CALL_FN("runFunctionWithFinallyDoesThrow");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(zai_hook_test_end_has_exception == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("user function with finally-discarded exception", {
    INSTALL_HOOK("functionWithFinallyReturning");
    CALL_FN("functionWithFinallyReturning");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(zai_hook_test_end_has_exception == 0);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_LONG);
});

INTERCEPTOR_TEST_CASE("direct internal function intercepting", {
    INSTALL_HOOK("time");
    CALL_FN("time");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_LONG);
});

INTERCEPTOR_TEST_CASE("user calls internal function intercepting", {
    INSTALL_HOOK("time");
    CALL_FN("callInternalTimeFunction");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_LONG);
});

INTERCEPTOR_TEST_CASE("internal function throws", {
    INSTALL_CLASS_HOOK("SplPriorityQueue", "extract");
    CALL_FN("callThrowingInternalFunction");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(zai_hook_test_end_has_exception == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("generator function intercepting from internal call", {
    INSTALL_HOOK("generator");
    CALL_FN("generator", CHECK(zai_hook_test_end_invocations == 0););
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("generator function intercepting from userland call", {
    INSTALL_HOOK("generator");
    CALL_FN("createGenerator", CHECK(zai_hook_test_end_invocations == 0););
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

static void zai_hook_test_yieldingGenerator_yield(zend_ulong invocation, zend_execute_data *ex, zval *key, zval *value, void *fixed, void *dynamic) {
    REQUIRE(Z_TYPE_P(key) == IS_LONG);
    switch (++zai_hook_test_yield_invocations) {
        case 1:
            REQUIRE(Z_LVAL_P(key) == 0);
            REQUIRE(Z_TYPE_P(value) == IS_NULL);
            break;
        case 2:
            REQUIRE(Z_LVAL_P(key) == 1);
            REQUIRE(Z_TYPE_P(value) == IS_LONG);
            REQUIRE(Z_LVAL_P(value) == 1);
            break;
        case 3:
            REQUIRE(Z_LVAL_P(key) == 10);
            REQUIRE(Z_TYPE_P(value) == IS_LONG);
            REQUIRE(Z_LVAL_P(value) == 2);
            break;
    }
}

INTERCEPTOR_TEST_CASE("generator yield intercepting from userland call", {
    INSTALL_GENERATOR_HOOK("yieldingGenerator", zai_hook_test_resume, zai_hook_test_yieldingGenerator_yield);
    CALL_FN("runYieldingGenerator");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_resumption_invocations == 4);
    CHECK(zai_hook_test_yield_invocations == 3);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});


static void zai_hook_test_receivingGenerator_yield(zend_ulong invocation, zend_execute_data *ex, zval *key, zval *value, void *fixed, void *dynamic) {
    REQUIRE(Z_TYPE_P(key) == IS_LONG);
    REQUIRE(Z_TYPE_P(value) == IS_NULL);
    ++zai_hook_test_yield_invocations;
}

static void zai_hook_test_receivingGenerator_resume(zend_ulong invocation, zend_execute_data *ex, zval *sent, void *fixed, void *dynamic) {
    switch (++zai_hook_test_resumption_invocations) {
        case 1:
            // Initial start, always NULL
            REQUIRE(EG(exception) == NULL);
            REQUIRE(Z_TYPE_P(sent) == IS_NULL);
            break;
        case 2:
            REQUIRE(EG(exception) != NULL);
            REQUIRE(Z_TYPE_P(sent) == IS_NULL);
            break;
        case 3:
            REQUIRE(EG(exception) == NULL);
            REQUIRE(Z_TYPE_P(sent) == IS_NULL);
            break;
        case 4:
            REQUIRE(EG(exception) == NULL);
            REQUIRE(Z_TYPE_P(sent) == IS_LONG);
            REQUIRE(Z_LVAL_P(sent) == 123);
            break;
    }
}

INTERCEPTOR_TEST_CASE("generator sending intercepting from userland call", {
    INSTALL_GENERATOR_HOOK("receivingGenerator", zai_hook_test_receivingGenerator_resume, zai_hook_test_receivingGenerator_yield);
    CALL_FN("runReceivingGenerator");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_resumption_invocations == 4);
    CHECK(zai_hook_test_yield_invocations == 3);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("generator yield intercepting of yield from array", {
    INSTALL_GENERATOR_HOOK("yieldFromArrayGenerator", zai_hook_test_resume, zai_hook_test_yield_ascending);
    CALL_FN("runYieldFromArrayGenerator");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_resumption_invocations == 6);
    CHECK(zai_hook_test_yield_invocations == 5);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("generator yield intercepting of yield from array with thrown in exception", {
    INSTALL_GENERATOR_HOOK("yieldFromArrayGeneratorThrows", zai_hook_test_resume, zai_hook_test_yield_ascending);
    CALL_FN("runYieldFromArrayGeneratorThrows");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_resumption_invocations == 3);
    CHECK(zai_hook_test_yield_invocations == 2);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("generator yield intercepting of yield from iterator", {
    INSTALL_GENERATOR_HOOK("yieldFromIteratorGenerator", zai_hook_test_resume, zai_hook_test_yield_ascending);
    CALL_FN("runYieldFromIteratorGenerator");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_resumption_invocations == 3);
    CHECK(zai_hook_test_yield_invocations == 2);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

/* broken in earlier versions: https://github.com/php/php-src/issues/8289 */
#if (PHP_VERSION_ID >= 80019 && PHP_VERSION_ID < 80100) || PHP_VERSION_ID >= 80106 || PHP_VERSION_ID < 70200
INTERCEPTOR_TEST_CASE("generator yield intercepting of yield from throwing iterator", {
    INSTALL_GENERATOR_HOOK("yieldFromIteratorGeneratorThrows", zai_hook_test_resume, zai_hook_test_yield_ascending);
    CALL_FN("runYieldFromIteratorGeneratorThrows");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_resumption_invocations == 4);
    CHECK(zai_hook_test_yield_invocations == 3);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});
#endif

INTERCEPTOR_TEST_CASE("generator yield intercepting of simple yield from generator", {
    INSTALL_GENERATOR_HOOK("yieldFromGenerator", zai_hook_test_resume, zai_hook_test_yield_ascending);
    CALL_FN("runYieldFromGenerator");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_resumption_invocations == 4);
    CHECK(zai_hook_test_yield_invocations == 3);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("generator yield intercepting of yielded from generator", {
    INSTALL_GENERATOR_HOOK("yieldFromInnerGenerator", zai_hook_test_resume, zai_hook_test_yield_ascending);
    ++zai_hook_test_yield_invocations;
    CALL_FN("runYieldFromGenerator");
    --zai_hook_test_yield_invocations;

    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_resumption_invocations == 3);
    CHECK(zai_hook_test_yield_invocations == 2);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

static void zai_hook_test_yield_multi_gen(zend_ulong invocation, zend_execute_data *ex, zval *key, zval *value, void *fixed, void *dynamic) {
    int32_t *zai_hook_test_multi_yield_invocations = (int32_t*)fixed;
    int32_t *zai_hook_test_local_yield_invocations = (int32_t*)dynamic;
    REQUIRE(Z_TYPE_P(key) == IS_LONG);
    REQUIRE(Z_TYPE_P(value) == IS_LONG);
    REQUIRE(Z_LVAL_P(value) == *zai_hook_test_local_yield_invocations);
    ++*zai_hook_test_local_yield_invocations;
    ++*zai_hook_test_multi_yield_invocations;
}

INTERCEPTOR_TEST_CASE("generator yield intercepting of yield from multi-generator", {
    int32_t *zai_hook_test_multi_yield_invocations = (int32_t *)calloc(1, 4);
    INSTALL_GENERATOR_HOOK("yieldFromInnerGenerator", zai_hook_test_resume, zai_hook_test_yield_ascending);
    REQUIRE(zai_hook_install_generator(ZAI_STR_EMPTY,ZAI_STRL("yieldFromMultiGenerator"),
                zai_hook_test_begin, zai_hook_test_resume, zai_hook_test_yield_multi_gen, zai_hook_test_end,
                ZAI_HOOK_AUX(zai_hook_test_multi_yield_invocations, free), 4) != -1);
    ++zai_hook_test_yield_invocations;
    CALL_FN("runYieldFromMultiGenerator");
    --zai_hook_test_yield_invocations;

    CHECK(zai_hook_test_begin_invocations == 3);
    CHECK(zai_hook_test_resumption_invocations == 2 /* gen 1 is primed + 1x next */ + 4 /* gen 2 is primed + 3x next */ + 3 /* inner gen is run to end */);
    CHECK(zai_hook_test_yield_invocations == 2);
    CHECK(*zai_hook_test_multi_yield_invocations == 2 + 3 /* gen 2 yields the current state of the inner generator first */);
    CHECK(zai_hook_test_end_invocations == 3);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("generator yield intercepting of nested yield from generator", {
    INSTALL_GENERATOR_HOOK("yieldFromGenerator", zai_hook_test_resume, zai_hook_test_yield_ascending);
    CALL_FN("runYieldFromNestedGenerator");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_resumption_invocations == 4);
    CHECK(zai_hook_test_yield_invocations == 3);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

// On PHP 7.0 the call to create a generator is completely elided and not hookable - on PHP 8 it's shortcicuited, but could be detected
// We decide to not trace that edge case at all, for consistency.
INTERCEPTOR_TEST_CASE("unused generator function intercepting", {
    INSTALL_HOOK("generator");
    CALL_FN("createGeneratorUnused", {
        CHECK(zai_hook_test_end_invocations == 0);
    });
    CHECK(zai_hook_test_begin_invocations == 0);
    CHECK(zai_hook_test_end_invocations == 0);
});

INTERCEPTOR_TEST_CASE("throwing generator intercepting", {
    INSTALL_HOOK("throwingGenerator");
    CALL_FN("runThrowingGenerator");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(zai_hook_test_end_has_exception == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("generator with finally intercepting", {
    INSTALL_HOOK("to_intercept");
    INSTALL_HOOK("generatorWithFinally");
    CALL_FN("runGeneratorWithFinally", CHECK(zai_hook_test_end_invocations == 0););
    CHECK(zai_hook_test_begin_invocations == 2);
    CHECK(zai_hook_test_end_invocations == 2);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("generator with finally and return intercepting", {
    INSTALL_HOOK("generatorWithFinallyReturn");
    CALL_FN("runGeneratorWithFinallyReturn", CHECK(zai_hook_test_end_invocations == 0););
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

INTERCEPTOR_TEST_CASE("generator with finally and return value intercepting", {
    INSTALL_HOOK("generatorWithFinallyReturnValue");
    CALL_FN("runGeneratorWithFinallyReturnValue", CHECK(zai_hook_test_end_invocations == 0););
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_STRING);
});

INTERCEPTOR_TEST_CASE("bailout in intercepted functions runs end handlers", {
    INSTALL_HOOK("bailout");

    zval result;
    zai_str fn_name = ZAI_STRL("bailout");
    REQUIRE(!zai_test_call_global_with_0_params(fn_name, &result));
    REQUIRE(CG(unclean_shutdown));

#if PHP_VERSION_ID < 80000
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 0);

    php_call_shutdown_functions();
#endif

    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

