#include <tea/testing/catch2.hpp>

extern "C" {
#include <hook/hook.h>
#include <value/value.h>
#include <tea/extension.h>
#include <ext/standard/basic_functions.h>
#if PHP_VERSION_ID >= 80000
#include <interceptor/php8/interceptor.h>
#else
#include <interceptor/php7/interceptor.h>
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
        zai_interceptor_rinit();
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
static int zai_hook_test_end_invocations;
static int zai_hook_test_end_has_exception;
static zval zai_hook_test_last_rv;
static void reset_interceptor_test_globals() {
    zai_hook_test_begin_return = true;
    zai_hook_test_begin_invocations = 0;
    zai_hook_test_end_invocations = 0;
    zai_hook_test_end_has_exception = 0;
    Z_TYPE_INFO(zai_hook_test_last_rv) = 0xFF;
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

#define INTERCEPTOR_TEST_CASE(description, ...) \
    TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE(     \
        "interceptor", description,     \
        "./stubs/stub.php",                                  \
        init_interceptor_test(); reset_interceptor_test_globals();,                    \
        __VA_ARGS__)

#define INSTALL_HOOK(fn) INSTALL_CLASS_HOOK("", fn)
#define INSTALL_CLASS_HOOK(class, fn) REQUIRE(zai_hook_install( \
    ZAI_HOOK_INTERNAL, \
    ZAI_STRL_VIEW(class), \
    ZAI_STRL_VIEW(fn), \
    ZAI_HOOK_BEGIN_INTERNAL(zai_hook_test_begin), \
    ZAI_HOOK_END_INTERNAL(zai_hook_test_end), \
    ZAI_HOOK_UNUSED(aux), \
    0 TEA_TSRMLS_CC))
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
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_STRING);
});

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

    php_call_shutdown_functions();
#endif

    CHECK(zai_hook_test_begin_invocations == 1);
    CHECK(zai_hook_test_end_invocations == 1);
    CHECK(Z_TYPE(zai_hook_test_last_rv) == IS_NULL);
});

