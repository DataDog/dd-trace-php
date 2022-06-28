extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"

TEA_TEST_CASE("symbol/api/function", "literal, exists", {
    REQUIRE(zai_symbol_lookup_function_literal(ZEND_STRL("strlen")));
})

TEA_TEST_CASE_WITH_STUB("symbol/api/function", "literal ns, exists", "./stubs/lookup/function/Stub.php", {
    REQUIRE(zai_symbol_lookup_function_literal_ns(ZEND_STRL("DDTraceTesting"), ZEND_STRL("StubFunction")));
})
