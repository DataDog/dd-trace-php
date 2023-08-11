extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE_WITH_STUB("symbol/lookup/local/static", "scalar", "./stubs/lookup/local/static/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval result;

    zai_str name = ZAI_STRL("scalar");

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_SYMBOL_FUNCTION_NAMED, &name, &result, 0));

    zval_ptr_dtor(&result);

    zai_str var = ZAI_STRL("var");
    zai_str target = ZAI_STRL("target");
    zend_function* method = (zend_function*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &target);

    zval *local = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_LOCAL, ZAI_SYMBOL_SCOPE_STATIC, method, &var);

    REQUIRE(local);
    REQUIRE(Z_TYPE_P(local) == IS_LONG);
    REQUIRE(Z_LVAL_P(local) == 42);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/local/static", "refcounted", "./stubs/lookup/local/static/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval result;

    zai_str name = ZAI_STRL("refcounted");

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_SYMBOL_FUNCTION_NAMED, &name, &result, 0));

    zval_ptr_dtor(&result);

    zai_str var = ZAI_STRL("var");
    zai_str target = ZAI_STRL("target");
    zend_function* method = (zend_function*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &target);

    zval *local = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_LOCAL, ZAI_SYMBOL_SCOPE_STATIC, method, &var);

    REQUIRE(local);
    REQUIRE(Z_TYPE_P(local) == IS_OBJECT);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/local/static", "reference", "./stubs/lookup/local/static/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval result;

    zai_str name = ZAI_STRL("reference");

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_SYMBOL_FUNCTION_NAMED, &name, &result, 0));

    zval_ptr_dtor(&result);

    zai_str var = ZAI_STRL("var");
    zai_str target = ZAI_STRL("targetWithReference");
    zend_function* method = (zend_function*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &target);

    zval *local = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_LOCAL, ZAI_SYMBOL_SCOPE_STATIC, method, &var);

    REQUIRE(local);
    /* This may seem counter intuitive, this is how we expect zend (and so zai) to behave though ... */
    REQUIRE(Z_TYPE_P(local) == IS_NULL);
})
