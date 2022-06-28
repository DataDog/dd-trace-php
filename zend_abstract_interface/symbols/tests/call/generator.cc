extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE_WITH_STUB("symbol/call/generator", "simple generator", "./stubs/call/generator/Stub.php", {
    zval result;

    zai_string_view fn = ZAI_STRL_VIEW("\\generator");

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result, 0));

    REQUIRE(Z_TYPE(result) == IS_OBJECT);

    zval_ptr_dtor(&result);
})

TEA_TEST_CASE_WITH_STUB("symbol/call/generator", "generator from closure", "./stubs/call/generator/Stub.php", {
    zval closure, generator, result, object;

    zai_string_view cn = ZAI_STRL_VIEW("GeneratorGetter");
    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &cn);
    zai_symbol_new(&object, ce, 0);

    zai_string_view fn = ZAI_STRL_VIEW("closure");
    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_OBJECT, &object, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &closure, 0));
    REQUIRE(Z_TYPE(closure) == IS_OBJECT);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_CLOSURE, &closure, &generator, 0));
    REQUIRE(Z_TYPE(generator) == IS_OBJECT);

    fn = ZAI_STRL_VIEW("current");
    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_OBJECT, &generator, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result, 0));
    REQUIRE(!zval_is_true(&result));

    zval_ptr_dtor(&closure);
    zval_ptr_dtor(&generator);
    zval_ptr_dtor(&result);
    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/call/generator", "rebound generator from closure", "./stubs/call/generator/Stub.php", {
    zval closure, generator, result, object, rebinding_target;

    zai_string_view cn = ZAI_STRL_VIEW("GeneratorGetter");
    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &cn);
    zai_symbol_new(&object, ce, 0);

    cn = ZAI_STRL_VIEW("GeneratorRebindTarget");
    ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &cn);
    zai_symbol_new(&rebinding_target, ce, 0);

    zai_string_view fn = ZAI_STRL_VIEW("closure");
    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_OBJECT, &object, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &closure, 0));
    REQUIRE(Z_TYPE(closure) == IS_OBJECT);

    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_OBJECT, &rebinding_target, ZAI_SYMBOL_FUNCTION_CLOSURE, &closure, &generator, 0));
    REQUIRE(Z_TYPE(generator) == IS_OBJECT);

    fn = ZAI_STRL_VIEW("current");
    REQUIRE(zai_symbol_call(ZAI_SYMBOL_SCOPE_OBJECT, &generator, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result, 0));
    REQUIRE(zval_is_true(&result));

    zval_ptr_dtor(&closure);
    zval_ptr_dtor(&generator);
    zval_ptr_dtor(&result);
    zval_ptr_dtor(&rebinding_target);
    zval_ptr_dtor(&object);
})
