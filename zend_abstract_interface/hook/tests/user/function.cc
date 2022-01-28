extern "C" {
    static bool  zai_hook_test_begin_return;
    static int   zai_hook_test_begin_check;
    static int   zai_hook_test_end_check;
}

#include "zai_tests_user.hpp"

extern "C" {
    static void ddtrace_testing_hook_auxiliary(zval *auxiliary) {

    }

    static PHP_FUNCTION(ddtrace_testing_hook_function) {
        zval *function,
             *begin,
             *end;

        if (zend_parse_parameters(ZEND_NUM_ARGS() ZAI_TSRMLS_CC, "zzz", &function, &begin, &end) != SUCCESS) {
            return;
        }

        zai_string_view fn = {.len = Z_STRLEN_P(function), .ptr = Z_STRVAL_P(function)};

        zai_hook_install(
            ZAI_HOOK_USER,
            ZAI_STRING_EMPTY,
            fn,
            ZAI_HOOK_BEGIN_USER(*begin),
            ZAI_HOOK_END_USER(*end),
            ZAI_HOOK_UNUSED(aux),
            0 ZAI_TSRMLS_CC);
    }

    ZEND_BEGIN_ARG_INFO_EX(ddtrace_testing_hook_function_arginfo, 0, 0, 2)
        ZEND_ARG_INFO(0, function)
        ZEND_ARG_INFO(0, begin)
        ZEND_ARG_INFO(0, end)
    ZEND_END_ARG_INFO()

    zend_function_entry zai_hook_test_functions[] = {
        PHP_FE(ddtrace_testing_hook_function,     ddtrace_testing_hook_function_arginfo)
        PHP_FE(ddtrace_testing_hook_begin_return, ddtrace_testing_hook_arginfo)
        PHP_FE(ddtrace_testing_hook_begin_check,  ddtrace_testing_hook_arginfo)
        PHP_FE(ddtrace_testing_hook_end_check,    ddtrace_testing_hook_arginfo)
        PHP_FE_END
    };
}

static zai_string_view zai_hook_test_target = ZAI_STRL_VIEW("\\DDTraceTesting\\target");

#define HOOK_TEST_PROLOGUE { \
    tea_extension_functions(zai_hook_test_functions);             \
    tea_extension_minit(PHP_MINIT(ddtrace_testing_hook));         \
    tea_extension_rinit(PHP_RINIT(ddtrace_testing_hook));         \
    tea_extension_mshutdown(PHP_MSHUTDOWN(ddtrace_testing_hook)); \
    tea_extension_rshutdown(PHP_RSHUTDOWN(ddtrace_testing_hook)); \
}

#define HOOK_TEST_CASE(description, stub, ...) \
    TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE(     \
        "hook/user/function", description,     \
        stub,                                  \
        HOOK_TEST_PROLOGUE,                    \
        __VA_ARGS__)

HOOK_TEST_CASE("continue", "./stubs/function/Stub.php", {
    zai_hook_test_reset(true);

    zval *result;
    ZAI_VALUE_INIT(result);

    CHECK(zai_symbol_call(
        ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
        ZAI_SYMBOL_FUNCTION_NAMED, &zai_hook_test_target,
        &result TEA_TSRMLS_CC, 0));

    CHECK(zai_hook_test_begin_check == 1);
    CHECK(zai_hook_test_end_check == 1);

    ZAI_VALUE_DTOR(result);
});

HOOK_TEST_CASE("stop", "./stubs/function/Stub.php", {
    zai_hook_test_reset(false);

    zval *result;
    ZAI_VALUE_INIT(result);

    CHECK(!zai_symbol_call(
        ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
        ZAI_SYMBOL_FUNCTION_NAMED, &zai_hook_test_target,
        &result TEA_TSRMLS_CC, 0));

    CHECK(zai_hook_test_begin_check == 1);
    CHECK(zai_hook_test_end_check == 1);
});
