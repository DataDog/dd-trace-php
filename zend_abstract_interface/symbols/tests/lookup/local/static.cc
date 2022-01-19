extern "C" {
#include "value/value.h"
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE_WITH_STUB("symbol/lookup/local/static", "scalar", "./stubs/lookup/local/static/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn TEA_TSRMLS_CC);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval *result;
    ZAI_VALUE_INIT(result);

    zai_string_view name = ZAI_STRL_VIEW("scalar");
    zend_function* method = (zend_function*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &name TEA_TSRMLS_CC);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_SYMBOL_FUNCTION_KNOWN, method, &result TEA_TSRMLS_CC, 0));

    zai_string_view var = ZAI_STRL_VIEW("var");

    zval *local = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_LOCAL, ZAI_SYMBOL_SCOPE_STATIC, method, &var TEA_TSRMLS_CC);

    REQUIRE(local);
    REQUIRE(Z_TYPE_P(local) == IS_LONG);
    REQUIRE(Z_LVAL_P(local) == 42);

    ZAI_VALUE_DTOR(result);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/local/static", "refcounted", "./stubs/lookup/local/static/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn TEA_TSRMLS_CC);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval *result;
    ZAI_VALUE_INIT(result);

    zai_string_view name = ZAI_STRL_VIEW("refcounted");
    zend_function* method = (zend_function*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &name TEA_TSRMLS_CC);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_SYMBOL_FUNCTION_KNOWN, method, &result TEA_TSRMLS_CC, 0));

    zai_string_view var = ZAI_STRL_VIEW("var");

    zval *local = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_LOCAL, ZAI_SYMBOL_SCOPE_STATIC, method, &var TEA_TSRMLS_CC);

    REQUIRE(local);
    REQUIRE(Z_TYPE_P(local) == IS_OBJECT);

    ZAI_VALUE_DTOR(result);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/local/static", "reference", "./stubs/lookup/local/static/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn TEA_TSRMLS_CC);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval *result;
    ZAI_VALUE_INIT(result);

    zai_string_view name = ZAI_STRL_VIEW("reference");
    zend_function* method = (zend_function*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &name TEA_TSRMLS_CC);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_SYMBOL_FUNCTION_KNOWN, method, &result TEA_TSRMLS_CC, 0));

    zai_string_view var = ZAI_STRL_VIEW("var");

    zval *local = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_LOCAL, ZAI_SYMBOL_SCOPE_STATIC, method, &var TEA_TSRMLS_CC);

    REQUIRE(local);
    /* This may seem counter intuitive, this is how we expect zend (and so zai) to behave though ... */
    REQUIRE(Z_TYPE_P(local) == IS_NULL);

    ZAI_VALUE_DTOR(result);
})
