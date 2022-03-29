#include <tea/testing/catch2.hpp>

extern "C" {
#include <hook/hook.h>
#include <value/value.h>
#include <tea/extension.h>
#include <ext/standard/basic_functions.h>
#if PHP_VERSION_ID >= 80000
#include <interceptor/php8/interceptor.h>
#elif PHP_VERSION_ID >= 70000
#include <interceptor/php7/interceptor.h>
#else
#include <interceptor/php5/interceptor.h>
#endif
#if PHP_VERSION_ID < 50600
static int user_shutdown_function_call(php_shutdown_function_entry *shutdown_function_entry TSRMLS_DC) /* {{{ */
{
    zval retval;

    if (call_user_function(EG(function_table), NULL,
                           shutdown_function_entry->arguments[0],
                           &retval,
                           shutdown_function_entry->arg_count - 1,
                           shutdown_function_entry->arguments + 1
                               TSRMLS_CC ) == SUCCESS)
    {
        zval_dtor(&retval);
    }
    return 0;
}

static void php_call_shutdown_functions(TSRMLS_D) /* {{{ */
{
    zend_hash_apply(BG(user_shutdown_function_names), (apply_func_t) user_shutdown_function_call TSRMLS_CC);
}
#endif

    static PHP_MINIT_FUNCTION(ddtrace_testing_hook) {
        zai_hook_minit();
#if PHP_VERSION_ID >= 80000
        zai_interceptor_minit();
#endif
        return SUCCESS;
    }

    static PHP_RINIT_FUNCTION(ddtrace_testing_hook) {
        zai_hook_rinit();
        zai_hook_activate();
        zai_interceptor_rinit(ZAI_TSRMLS_C);
        return SUCCESS;
    }

    static PHP_RSHUTDOWN_FUNCTION(ddtrace_testing_hook) {
        zai_interceptor_rshutdown();
        zai_hook_rshutdown();
        return SUCCESS;
    }

    static PHP_MSHUTDOWN_FUNCTION(ddtrace_testing_hook) {
        zai_hook_mshutdown();
        return SUCCESS;
    }

    static zend_result_t ddtrace_testing_startup() {
#if PHP_VERSION_ID < 80000
        zai_interceptor_startup(tea_extension_dummy());
#endif
        return SUCCESS;
    }

    static void init_interceptor_test() {
#if PHP_VERSION_ID < 80000
        tea_extension_op_array_ctor(zai_interceptor_op_array_ctor);
#if PHP_VERSION_ID >= 70000
        tea_extension_op_array_handler(zai_interceptor_op_array_pass_two);
#endif
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
#if PHP_VERSION_ID < 70000
    Z_TYPE(zai_hook_test_last_rv) = 0xFF;
#else
    Z_TYPE_INFO(zai_hook_test_last_rv) = 0xFF;
#endif
}

static bool zai_hook_test_begin(zend_execute_data *ex, void *fixed, void *dynamic TEA_TSRMLS_DC) {
    ++zai_hook_test_begin_invocations;
    return zai_hook_test_begin_return;
}

static void zai_hook_test_end(zend_execute_data *ex, zval *rv, void *fixed, void *dynamic TEA_TSRMLS_DC) {
    ++zai_hook_test_end_invocations;
    ZVAL_COPY_VALUE(&zai_hook_test_last_rv, rv);
    if (EG(exception)) {
        ++zai_hook_test_end_has_exception;
    }
}

static void zai_hook_test_resume(zend_execute_data *ex, zval *sent, void *fixed, void *dynamic TEA_TSRMLS_DC) {
    ++zai_hook_test_resumption_invocations;
}

#define INTERCEPTOR_TEST_CASE(description, ...) \
    TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE(     \
        "interceptor", description,     \
        "./stubs/stub.php",                                  \
        init_interceptor_test(); reset_interceptor_test_globals();,                    \
        __VA_ARGS__)

#define INSTALL_HOOK(fn) INSTALL_CLASS_HOOK("", fn)
#define INSTALL_CLASS_HOOK(class, fn) REQUIRE(zai_hook_install( \
    ZAI_STRL_VIEW(class), \
    ZAI_STRL_VIEW(fn), \
    zai_hook_test_begin, \
    zai_hook_test_end, \
    ZAI_HOOK_AUX(NULL, NULL), \
    0 TEA_TSRMLS_CC) != -1)
#define INSTALL_GENERATOR_HOOK(fn, resume, yield) REQUIRE(zai_hook_install_generator( \
                                               ZAI_STRL_VIEW(""), \
                                               ZAI_STRL_VIEW(fn), \
                                               zai_hook_test_begin,    \
                                               resume, \
                                               yield, \
                                               zai_hook_test_end, \
                                               ZAI_HOOK_AUX(NULL, NULL), \
                                               0 TEA_TSRMLS_CC) != -1)
#define CALL_FN(fn, ...) do { \
    zval *result; \
    ZAI_VALUE_INIT(result); \
    zai_string_view _fn_name = ZAI_STRL_VIEW(fn);               \
    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_NAMED, &_fn_name, &result TEA_TSRMLS_CC, 0)); \
    __VA_ARGS__               \
    ZAI_VALUE_DTOR(result);                          \
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

#if PHP_VERSION_ID >= 50500
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
#endif

#if PHP_VERSION_ID >= 50500
// PHP 5.4 doesn't check zend_execute_internal in zend_call_function
INTERCEPTOR_TEST_CASE("direct internal function intercepting", {
    INSTALL_HOOK("time");
    CALL_FN("time");
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_LONG);
});
#endif

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

#if PHP_VERSION_ID >= 50500
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

static void zai_hook_test_yieldingGenerator_yield(zend_execute_data *ex, zval *key, zval *value, void *fixed, void *dynamic TEA_TSRMLS_DC) {
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


static void zai_hook_test_receivingGenerator_yield(zend_execute_data *ex, zval *key, zval *value, void *fixed, void *dynamic TEA_TSRMLS_DC) {
    REQUIRE(Z_TYPE_P(key) == IS_LONG);
    REQUIRE(Z_TYPE_P(value) == IS_NULL);
    ++zai_hook_test_yield_invocations;
}

static void zai_hook_test_receivingGenerator_resume(zend_execute_data *ex, zval *sent, void *fixed, void *dynamic TEA_TSRMLS_DC) {
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

// TODO check this, maybe just don't start the span if it's a generator creating span
// OR: TODO PHP 8 support, by doing the ZEND_GENERATOR_CREATE overload, which does an open followed by an immediate close if retval unused
#if PHP_VERSION_ID >= 70100 && PHP_VERSION_ID < 80000
// On PHP 7.0 the call to create a generator is completely elided and not hookable
INTERCEPTOR_TEST_CASE("unused generator function intercepting", {
    INSTALL_HOOK("generator");
    CALL_FN("createGeneratorUnused", {
        CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
        CHECK(zai_hook_test_end_invocations == 1);
    });
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
});
#endif

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
#endif

#if PHP_VERSION_ID >= 70000  // generator return values are only supported from PHP 7 on
INTERCEPTOR_TEST_CASE("generator with finally and return value intercepting", {
    INSTALL_HOOK("generatorWithFinallyReturnValue");
    CALL_FN("runGeneratorWithFinallyReturnValue", CHECK(zai_hook_test_end_invocations == 0););
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_STRING);
});
#endif

INTERCEPTOR_TEST_CASE("bailout in intercepted functions runs end handlers", {
    INSTALL_HOOK("bailout");

    zval *result;
    ZAI_VALUE_INIT(result);
    zai_string_view _fn_name = ZAI_STRL_VIEW("bailout");
    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_NAMED, &_fn_name, &result TEA_TSRMLS_CC, 0) == false);

    REQUIRE(CG(unclean_shutdown));

#if PHP_VERSION_ID < 80000
    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 0);

    php_call_shutdown_functions(ZAI_TSRMLS_C);
#endif

    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

