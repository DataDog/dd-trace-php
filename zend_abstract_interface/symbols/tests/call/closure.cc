extern "C" {
#include "symbols/symbols.h"
#include "tea/extension.h"

static zval ddtrace_testing_closure_object;
static zval ddtrace_testing_closure_value;

PHP_FUNCTION(ddtrace_testing_closure_intercept) {
    zval *object, *value;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "zz", &object, &value) != SUCCESS) {
        return;
    }

    ZVAL_COPY(&ddtrace_testing_closure_object, object);
    ZVAL_COPY(&ddtrace_testing_closure_value, value);
}

ZEND_BEGIN_ARG_INFO_EX(ddtrace_testing_closure_arginfo, 0, 0, 2)
    ZEND_ARG_INFO(0, object)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

const zend_function_entry ddtrace_testing_closure_functions[] = {
    PHP_FE(ddtrace_testing_closure_intercept, ddtrace_testing_closure_arginfo)
    PHP_FE_END
};

}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

static bool zai_symbol_call_closure_test(const char *fn, size_t fnl) {
    zend_function *function = zai_symbol_lookup_function_literal_ns(ZEND_STRL("DDTraceTesting"), fn, fnl);

    if (!function) {
        return false;
    }

    zval result;

    if (zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_KNOWN, function, &result, 0)) {
        zval_ptr_dtor(&result);
        return true;
    }

    zval_ptr_dtor(&result);
    return false;
}

TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE("symbol/call/closure", "closure bound", "./stubs/call/closure/Stub.php", {
    tea_extension_functions(ddtrace_testing_closure_functions);
},{
    REQUIRE(zai_symbol_call_closure_test(ZEND_STRL("closureTestBinding")));

    zval result;

    CHECK(zai_symbol_call(
            ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
            ZAI_SYMBOL_FUNCTION_CLOSURE, &ddtrace_testing_closure_value,
            &result, 0));

    zval_ptr_dtor(&result);

zval_ptr_dtor(&ddtrace_testing_closure_object);
    zval_ptr_dtor(&ddtrace_testing_closure_value);
})

TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE("symbol/call/closure", "closure rebound", "./stubs/call/closure/Stub.php", {
    tea_extension_functions(ddtrace_testing_closure_functions);
},{
    REQUIRE(zai_symbol_call_closure_test(ZEND_STRL("closureTestRebinding")));

    zval result;

    CHECK(zai_symbol_call(
            ZAI_SYMBOL_SCOPE_OBJECT, &ddtrace_testing_closure_object,
            ZAI_SYMBOL_FUNCTION_CLOSURE, &ddtrace_testing_closure_value,
            &result, 0));

    zval_ptr_dtor(&result);

    zval_ptr_dtor(&ddtrace_testing_closure_object);
    zval_ptr_dtor(&ddtrace_testing_closure_value);
})


