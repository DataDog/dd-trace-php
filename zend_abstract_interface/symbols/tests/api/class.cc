extern "C" {
#include "symbols/symbols.h"
#include "zai_compat.h"
}

#include "zai_sapi/testing/catch2.hpp"

ZAI_SAPI_TEST_CASE_WITH_STUB("symbol/api/class", "literal, exists", "./stubs/lookup/class/Stub.php", {
    REQUIRE(zai_symbol_lookup_class_literal(ZEND_STRL("\\DDTraceTesting\\Stub") ZAI_TSRMLS_CC));
})

ZAI_SAPI_TEST_CASE_WITH_STUB("symbol/api/class", "literal ns, exists", "./stubs/lookup/class/Stub.php", {
    REQUIRE(zai_symbol_lookup_class_literal_ns(ZEND_STRL("DDTraceTesting"), ZEND_STRL("Stub") ZAI_TSRMLS_CC));
})      
