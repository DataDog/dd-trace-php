extern "C" {
#include "symbols/symbols.h"
#include "zai_compat.h"
}

#include "zai_sapi/testing/catch2.hpp"

ZAI_SAPI_TEST_CASE_WITH_STUB("symbol/api/property", "literal", "./stubs/lookup/property/Stub.php", {
    zend_class_entry *ce = zai_symbol_lookup_class_literal_ns(
        ZEND_STRL("DDTraceTesting"), ZEND_STRL("Stub") ZAI_TSRMLS_CC);

    REQUIRE(ce);

    REQUIRE(zai_symbol_lookup_property_literal(ZAI_SYMBOL_SCOPE_CLASS, ce, ZEND_STRL("publicStatic") ZAI_TSRMLS_CC));
})
