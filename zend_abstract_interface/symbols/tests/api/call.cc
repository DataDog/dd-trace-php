extern "C" {
#include "symbols/symbols.h"
#include "value/value.h"
}

#include "tea/testing/catch2.hpp"

TEA_TEST_CASE("symbol/api/call", "literal", {
    zval *result;

    ZAI_VALUE_INIT(result);

    CHECK(zai_symbol_call_literal(ZEND_STRL("phpversion"), &result TEA_TSRMLS_CC, 0));

    ZAI_VALUE_DTOR(result);
})

TEA_TEST_CASE_WITH_STUB("symbol/api/call", "literal ns", "./stubs/call/user/Stub.php", {
    zval *result;

    ZAI_VALUE_INIT(result);

    CHECK(zai_symbol_call_literal_ns(ZEND_STRL("DDTraceTesting"), ZEND_STRL("noargs"), &result TEA_TSRMLS_CC, 0));

    ZAI_VALUE_DTOR(result);
})

TEA_TEST_CASE_WITH_STUB("symbol/api/call", "static", "./stubs/call/user/Stub.php", {
    zval *result;
    zend_class_entry *ce = zai_symbol_lookup_class_literal_ns(
        ZEND_STRL("DDTraceTesting"), ZEND_STRL("Stub") TEA_TSRMLS_CC);
    zai_string_view vfn = ZAI_STRL_VIEW("staticPublicFunction");

    ZAI_VALUE_INIT(result);

    CHECK(zai_symbol_call_static(ce, &vfn, &result TEA_TSRMLS_CC, 0));

    ZAI_VALUE_DTOR(result);
})

TEA_TEST_CASE_WITH_STUB("symbol/api/call", "static literal", "./stubs/call/user/Stub.php", {
    zval *result;
    zend_class_entry *ce = zai_symbol_lookup_class_literal_ns(
        ZEND_STRL("DDTraceTesting"), ZEND_STRL("Stub") TEA_TSRMLS_CC);

    ZAI_VALUE_INIT(result);

    CHECK(zai_symbol_call_static_literal(ce, ZEND_STRL("staticPublicFunction"), &result TEA_TSRMLS_CC, 0));

    ZAI_VALUE_DTOR(result);
})
