extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"

TEA_TEST_CASE_WITH_STUB("symbol/api/class", "literal, exists", "./stubs/lookup/class/Stub.php", {
    REQUIRE(zai_symbol_lookup_class_global(ZAI_STRL("\\DDTraceTesting\\Stub")));
})

TEA_TEST_CASE_WITH_STUB("symbol/api/class", "literal ns, exists", "./stubs/lookup/class/Stub.php", {
    REQUIRE(zai_symbol_lookup_class_ns(ZAI_STRL("DDTraceTesting"), ZAI_STRL("Stub")));
})
