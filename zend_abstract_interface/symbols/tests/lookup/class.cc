extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE("symbol/lookup/class", "global, exists", {
    zai_string_view lower = ZAI_STRL_VIEW("stdclass");
    zai_string_view mixed = ZAI_STRL_VIEW("stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &lower TEA_TSRMLS_CC));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &mixed TEA_TSRMLS_CC));
})

TEA_TEST_CASE("symbol/lookup/class", "global, does not exist", {
    zai_string_view lower = ZAI_STRL_VIEW("nosuchclass");
    zai_string_view mixed = ZAI_STRL_VIEW("NoSuchClass");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &lower TEA_TSRMLS_CC));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &mixed TEA_TSRMLS_CC));
})

TEA_TEST_CASE("symbol/lookup/class", "empty ns, exists", {
    zai_string_view ns   = ZAI_STRL_VIEW("");
    zai_string_view lower = ZAI_STRL_VIEW("stdclass");
    zai_string_view mixed = ZAI_STRL_VIEW("stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower TEA_TSRMLS_CC));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed TEA_TSRMLS_CC));
})

TEA_TEST_CASE("symbol/lookup/class", "root ns, exists", {
    zai_string_view ns   = ZAI_STRL_VIEW("\\");
    zai_string_view lower = ZAI_STRL_VIEW("stdclass");
    zai_string_view mixed = ZAI_STRL_VIEW("stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower TEA_TSRMLS_CC));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed TEA_TSRMLS_CC));
})

TEA_TEST_CASE("symbol/lookup/class", "root ns fqcn, exists", {
    zai_string_view name = ZAI_STRL_VIEW("\\stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name TEA_TSRMLS_CC));
})

TEA_TEST_CASE("symbol/lookup/class", "root ns, does not exist", {
    zai_string_view ns   = ZAI_STRL_VIEW("\\");
    zai_string_view lower = ZAI_STRL_VIEW("nosuchclass");
    zai_string_view mixed = ZAI_STRL_VIEW("NoSuchClass");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower TEA_TSRMLS_CC));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed TEA_TSRMLS_CC));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/class", "ns, exists", "./stubs/lookup/class/Stub.php", {
    zai_string_view ns   = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view name = ZAI_STRL_VIEW("Stub");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &name TEA_TSRMLS_CC));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/class", "ns fqcn, exists", "./stubs/lookup/class/Stub.php", {
    zai_string_view name = ZAI_STRL_VIEW("\\DDTraceTesting\\Stub");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name TEA_TSRMLS_CC));
})

TEA_TEST_CASE_WITH_TAGS("symbol/lookup/class", "incorrect API usage", "[use][.]", {
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_CLASS, NULL, NULL TEA_TSRMLS_CC));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_OBJECT, NULL, NULL TEA_TSRMLS_CC));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_FRAME, NULL, NULL TEA_TSRMLS_CC));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_STATIC, NULL, NULL TEA_TSRMLS_CC));
})
