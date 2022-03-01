extern "C" {
#include "value/value.h"
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE_WITH_STUB("symbol/call/generator", "simple generator", "./stubs/call/generator/Stub.php", {
    zval *result;
    ZAI_VALUE_INIT(result);

    zai_string_view fn = ZAI_STRL_VIEW("\\generator");

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result TEA_TSRMLS_CC, 0));

    REQUIRE(Z_TYPE_P(result) == IS_OBJECT);

    ZAI_VALUE_DTOR(result);
})

TEA_TEST_CASE_WITH_STUB("symbol/call/generator", "generator from closure", "./stubs/call/generator/Stub.php", {
    zval *closure;
    ZAI_VALUE_INIT(closure);

    zval *generator;
    ZAI_VALUE_INIT(generator);

    zval *result;
    ZAI_VALUE_INIT(result);

    zval *object;
    ZAI_VALUE_MAKE(object);

    zai_string_view cn = ZAI_STRL_VIEW("GeneratorGetter");
    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &cn TEA_TSRMLS_CC);
    zai_symbol_new(object, ce TEA_TSRMLS_CC, 0);


    zai_string_view fn = ZAI_STRL_VIEW("closure");
    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_OBJECT, object, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &closure TEA_TSRMLS_CC, 0));
    REQUIRE(Z_TYPE_P(closure) == IS_OBJECT);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_CLOSURE, closure, &generator TEA_TSRMLS_CC, 0));
    REQUIRE(Z_TYPE_P(generator) == IS_OBJECT);

    fn = ZAI_STRL_VIEW("current");
    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_OBJECT, generator, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result TEA_TSRMLS_CC, 0));
    REQUIRE(!zval_is_true(result));

    ZAI_VALUE_DTOR(closure);
    ZAI_VALUE_DTOR(generator);
    ZAI_VALUE_DTOR(result);
    ZAI_VALUE_DTOR(object);
})

TEA_TEST_CASE_WITH_STUB("symbol/call/generator", "rebound generator from closure", "./stubs/call/generator/Stub.php", {
    zval *closure;
    ZAI_VALUE_INIT(closure);

    zval *generator;
    ZAI_VALUE_INIT(generator);

    zval *result;
    ZAI_VALUE_INIT(result);

    zval *object;
    ZAI_VALUE_MAKE(object);

    zval *rebinding_target;
    ZAI_VALUE_MAKE(rebinding_target);

    zai_string_view cn = ZAI_STRL_VIEW("GeneratorGetter");
    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &cn TEA_TSRMLS_CC);
    zai_symbol_new(object, ce TEA_TSRMLS_CC, 0);

    cn = ZAI_STRL_VIEW("GeneratorRebindTarget");
    ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &cn TEA_TSRMLS_CC);
    zai_symbol_new(rebinding_target, ce TEA_TSRMLS_CC, 0);

    zai_string_view fn = ZAI_STRL_VIEW("closure");
    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_OBJECT, object, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &closure TEA_TSRMLS_CC, 0));
    REQUIRE(Z_TYPE_P(closure) == IS_OBJECT);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_OBJECT, rebinding_target, ZAI_SYMBOL_FUNCTION_CLOSURE, closure, &generator TEA_TSRMLS_CC, 0));
    REQUIRE(Z_TYPE_P(generator) == IS_OBJECT);

    fn = ZAI_STRL_VIEW("current");
    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_OBJECT, generator, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result TEA_TSRMLS_CC, 0));
    REQUIRE(zval_is_true(result));

    ZAI_VALUE_DTOR(closure);
    ZAI_VALUE_DTOR(generator);
    ZAI_VALUE_DTOR(result);
    ZAI_VALUE_DTOR(rebinding_target);
    ZAI_VALUE_DTOR(object);
})
