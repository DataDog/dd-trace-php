extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"

TEA_TEST_CASE("symbol/api/constant", "literal, exists", {
    REQUIRE(zai_symbol_lookup_constant_global(ZAI_STRL("PHP_VERSION")));
})

TEA_TEST_CASE_WITH_STUB("symbol/api/constant", "literal ns, exists", "./stubs/lookup/constant/Stub.php", {
    REQUIRE(zai_symbol_lookup_constant_ns(ZAI_STRL("DDTraceTesting"), ZAI_STRL("DD_TRACE_TESTING")));
})
