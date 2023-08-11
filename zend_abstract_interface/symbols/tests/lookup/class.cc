extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE("symbol/lookup/class", "global, exists", {
    zai_str lower = ZAI_STRL("stdclass");
    zai_str mixed = ZAI_STRL("stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &lower));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &mixed));
})

TEA_TEST_CASE("symbol/lookup/class", "global, does not exist", {
    zai_str lower = ZAI_STRL("nosuchclass");
    zai_str mixed = ZAI_STRL("NoSuchClass");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &lower));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &mixed));
})

TEA_TEST_CASE("symbol/lookup/class", "empty ns, exists", {
    zai_str ns   = ZAI_STRL("");
    zai_str lower = ZAI_STRL("stdclass");
    zai_str mixed = ZAI_STRL("stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed));
})

TEA_TEST_CASE("symbol/lookup/class", "root ns, exists", {
    zai_str ns   = ZAI_STRL("\\");
    zai_str lower = ZAI_STRL("stdclass");
    zai_str mixed = ZAI_STRL("stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed));
})

TEA_TEST_CASE("symbol/lookup/class", "root ns fqcn, exists", {
    zai_str name = ZAI_STRL("\\stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name));
})

TEA_TEST_CASE("symbol/lookup/class", "root ns, does not exist", {
    zai_str ns   = ZAI_STRL("\\");
    zai_str lower = ZAI_STRL("nosuchclass");
    zai_str mixed = ZAI_STRL("NoSuchClass");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/class", "ns, exists", "./stubs/lookup/class/Stub.php", {
    zai_str ns   = ZAI_STRL("\\DDTraceTesting");
    zai_str name = ZAI_STRL("Stub");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &name));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/class", "ns fqcn, exists", "./stubs/lookup/class/Stub.php", {
    zai_str name = ZAI_STRL("\\DDTraceTesting\\Stub");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name));
})

TEA_TEST_CASE_WITH_TAGS("symbol/lookup/class", "incorrect API usage", "[use][.]", {
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_CLASS, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_OBJECT, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_FRAME, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_STATIC, NULL, NULL));
})
