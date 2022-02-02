extern "C" {
#include "value/value.h"
#include "symbols/symbols.h"
#include "tea/extension.h"

static zval* ddtrace_testing_frame_result;

ZEND_BEGIN_ARG_INFO_EX(ddtrace_testing_frame_intercept_arginfo, 0, 0, 0)
ZEND_END_ARG_INFO()

// clang-format off
static PHP_FUNCTION(ddtrace_testing_frame_intercept) {
#if PHP_VERSION_ID >= 70000
    zend_execute_data* frame = EX(prev_execute_data);
#else
    zend_execute_data* frame =
        EG(current_execute_data)
            ->prev_execute_data;
#endif
    zai_string_view name = ZAI_STRL_VIEW("var");

    zval* result = (zval*) zai_symbol_lookup(
        ZAI_SYMBOL_TYPE_LOCAL,
        ZAI_SYMBOL_SCOPE_FRAME,
        frame, &name TEA_TSRMLS_CC);

    ZAI_VALUE_COPY(ddtrace_testing_frame_result, result);

    RETURN_NULL();
}

static zend_function_entry ddtrace_testing_frame_extension_functions[] = {
    PHP_FE(ddtrace_testing_frame_intercept, ddtrace_testing_frame_intercept_arginfo)
    PHP_FE_END
};
// clang-format on
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE("symbol/lookup/local/frame", "scalar", "./stubs/lookup/local/frame/Stub.php", {
    tea_sapi_module.php_ini_ignore = 1;
    tea_extension_functions(ddtrace_testing_frame_extension_functions);
},{
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn TEA_TSRMLS_CC);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    ZAI_VALUE_MAKE(ddtrace_testing_frame_result);

    zval *result;
    ZAI_VALUE_INIT(result);

    zai_string_view name = ZAI_STRL_VIEW("scalar");
    zend_function* method = (zend_function*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &name TEA_TSRMLS_CC);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_SYMBOL_FUNCTION_KNOWN, method, &result TEA_TSRMLS_CC, 0));

    REQUIRE(Z_TYPE_P(ddtrace_testing_frame_result) == IS_LONG);

    ZAI_VALUE_DTOR(result);
    ZAI_VALUE_DTOR(ddtrace_testing_frame_result);
})

TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE("symbol/lookup/local/frame", "refcounted", "./stubs/lookup/local/frame/Stub.php", {
    tea_sapi_module.php_ini_ignore = 1;
    tea_extension_functions(ddtrace_testing_frame_extension_functions);
},{
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn TEA_TSRMLS_CC);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    ZAI_VALUE_MAKE(ddtrace_testing_frame_result);

    zval *result;
    ZAI_VALUE_INIT(result);

    zai_string_view name = ZAI_STRL_VIEW("refcounted");
    zend_function* method = (zend_function*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &name TEA_TSRMLS_CC);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_SYMBOL_FUNCTION_KNOWN, method, &result TEA_TSRMLS_CC, 0));

    REQUIRE(Z_TYPE_P(ddtrace_testing_frame_result) == IS_OBJECT);

    ZAI_VALUE_DTOR(result);
    ZAI_VALUE_DTOR(ddtrace_testing_frame_result);
})

TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE("symbol/lookup/local/frame", "reference", "./stubs/lookup/local/frame/Stub.php", {
    tea_sapi_module.php_ini_ignore = 1;
    tea_extension_functions(ddtrace_testing_frame_extension_functions);
},{
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn TEA_TSRMLS_CC);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    ZAI_VALUE_MAKE(ddtrace_testing_frame_result);

    zval *result;
    ZAI_VALUE_INIT(result);

    zai_string_view name = ZAI_STRL_VIEW("reference");
    zend_function* method = (zend_function*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &name TEA_TSRMLS_CC);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_SYMBOL_FUNCTION_KNOWN, method, &result TEA_TSRMLS_CC, 0));

    REQUIRE(Z_TYPE_P(ddtrace_testing_frame_result) == IS_OBJECT);

    ZAI_VALUE_DTOR(result);
    ZAI_VALUE_DTOR(ddtrace_testing_frame_result);
})

TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE("symbol/lookup/local/frame", "param", "./stubs/lookup/local/frame/Stub.php", {
    tea_sapi_module.php_ini_ignore = 1;
    tea_extension_functions(ddtrace_testing_frame_extension_functions);
},{
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn TEA_TSRMLS_CC);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    ZAI_VALUE_MAKE(ddtrace_testing_frame_result);

    zval *result;
    ZAI_VALUE_INIT(result);

    zval *param;
    ZAI_VALUE_MAKE(param);

    ZVAL_LONG(param, 42);

    zai_string_view name = ZAI_STRL_VIEW("param");
    zend_function* method = (zend_function*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &name TEA_TSRMLS_CC);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_SYMBOL_FUNCTION_KNOWN, method, &result TEA_TSRMLS_CC, 1, &param));

    REQUIRE(Z_TYPE_P(ddtrace_testing_frame_result) == IS_LONG);

    ZAI_VALUE_DTOR(result);
    ZAI_VALUE_DTOR(param);
    ZAI_VALUE_DTOR(ddtrace_testing_frame_result);
})

