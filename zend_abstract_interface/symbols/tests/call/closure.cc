extern "C" {
#include "value/value.h"
#include "symbols/symbols.h"
#include "tea/extension.h"

static zval* ddtrace_testing_closure_object;
static zval* ddtrace_testing_closure_value;

PHP_FUNCTION(ddtrace_testing_closure_intercept) {
    zval *object, *value;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TEA_TSRMLS_CC, "zz", &object, &value) != SUCCESS) {
        return;
    }

    ZAI_VALUE_COPY(ddtrace_testing_closure_object, object);
    ZAI_VALUE_COPY(ddtrace_testing_closure_value, value);
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

static bool zai_symbol_call_closure_test(const char *fn, size_t fnl ZAI_TSRMLS_DC) {
    zend_function *function = zai_symbol_lookup_function_literal_ns(ZEND_STRL("DDTraceTesting"), fn, fnl TEA_TSRMLS_CC);

    if (!function) {
        return false;
    }

    zval *result;
    ZAI_VALUE_INIT(result);

    if (zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_KNOWN, function, &result TEA_TSRMLS_CC, 0)) {
        ZAI_VALUE_DTOR(result);
        return true;
    }

    ZAI_VALUE_DTOR(result);
    return false;
}

TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE("symbol/call/closure", "closure bound", "./stubs/call/closure/Stub.php", {
    tea_extension_functions(ddtrace_testing_closure_functions);
},{
    ZAI_VALUE_MAKE(ddtrace_testing_closure_object);
    ZAI_VALUE_MAKE(ddtrace_testing_closure_value);

    REQUIRE(zai_symbol_call_closure_test(ZEND_STRL("closureTestBinding") TEA_TSRMLS_CC));

    zval *result;
    ZAI_VALUE_INIT(result);

    CHECK(zai_symbol_call(
            ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
            ZAI_SYMBOL_FUNCTION_CLOSURE, ddtrace_testing_closure_value,
            &result TEA_TSRMLS_CC, 0));

    ZAI_VALUE_DTOR(result);

    ZAI_VALUE_DTOR(ddtrace_testing_closure_object);
    ZAI_VALUE_DTOR(ddtrace_testing_closure_value);
})

TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE("symbol/call/closure", "closure rebound", "./stubs/call/closure/Stub.php", {
    tea_extension_functions(ddtrace_testing_closure_functions);
},{
    ZAI_VALUE_MAKE(ddtrace_testing_closure_object);
    ZAI_VALUE_MAKE(ddtrace_testing_closure_value);

    REQUIRE(zai_symbol_call_closure_test(ZEND_STRL("closureTestRebinding") TEA_TSRMLS_CC));

    zval *result;
    ZAI_VALUE_INIT(result);

    CHECK(zai_symbol_call(
            ZAI_SYMBOL_SCOPE_OBJECT, ddtrace_testing_closure_object,
            ZAI_SYMBOL_FUNCTION_CLOSURE, ddtrace_testing_closure_value,
            &result TEA_TSRMLS_CC, 0));

    ZAI_VALUE_DTOR(result);

    ZAI_VALUE_DTOR(ddtrace_testing_closure_object);
    ZAI_VALUE_DTOR(ddtrace_testing_closure_value);
})


