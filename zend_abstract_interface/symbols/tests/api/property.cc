extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"

TEA_TEST_CASE_WITH_STUB("symbol/api/property", "literal", "./stubs/lookup/property/Stub.php", {
    zend_class_entry *ce = zai_symbol_lookup_class_ns(
        ZAI_STRL_VIEW("DDTraceTesting"), ZAI_STRL_VIEW("Stub"));

    REQUIRE(ce);

    REQUIRE(zai_symbol_lookup_property(ZAI_SYMBOL_SCOPE_CLASS, ce, ZAI_STRL_VIEW("publicStatic")));
})
