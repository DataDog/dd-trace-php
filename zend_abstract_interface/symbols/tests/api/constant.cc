extern "C" {
#include "symbols/symbols.h"
#include "zai_compat.h"
}

#include "zai_sapi/testing/catch2.hpp"

ZAI_SAPI_TEST_CASE("symbol/api/constant", "literal, exists", {
    REQUIRE(zai_symbol_lookup_constant_literal(ZEND_STRL("PHP_VERSION") ZAI_TSRMLS_CC));
})

ZAI_SAPI_TEST_CASE_WITH_STUB("symbol/api/constant", "literal ns, exists", "./stubs/lookup/constant/Stub.php", {
    REQUIRE(zai_symbol_lookup_constant_literal_ns(ZEND_STRL("DDTraceTesting"), ZEND_STRL("DD_TRACE_TESTING") ZAI_TSRMLS_CC));
})      
