extern "C" {
#include "symbols/symbols.h"
#include "zai_sapi/zai_sapi.h"

#include "zai_compat.h"
}

#include "zai_sapi/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

ZAI_SAPI_TEST_CASE("symbol/lookup/class", "global, exists", {
    zai_string_view lower = ZAI_STRL_VIEW("stdclass");
    zai_string_view mixed = ZAI_STRL_VIEW("stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &lower ZAI_TSRMLS_CC));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &mixed ZAI_TSRMLS_CC));
})

ZAI_SAPI_TEST_CASE("symbol/lookup/class", "global, does not exist", {
    zai_string_view lower = ZAI_STRL_VIEW("nosuchclass");
    zai_string_view mixed = ZAI_STRL_VIEW("NoSuchClass");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &lower ZAI_TSRMLS_CC));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &mixed ZAI_TSRMLS_CC));
})

ZAI_SAPI_TEST_CASE("symbol/lookup/class", "empty ns, exists", {
    zai_string_view ns   = ZAI_STRL_VIEW("");
    zai_string_view lower = ZAI_STRL_VIEW("stdclass");
    zai_string_view mixed = ZAI_STRL_VIEW("stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower ZAI_TSRMLS_CC));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed ZAI_TSRMLS_CC));
})

ZAI_SAPI_TEST_CASE("symbol/lookup/class", "root ns, exists", {
    zai_string_view ns   = ZAI_STRL_VIEW("\\");
    zai_string_view lower = ZAI_STRL_VIEW("stdclass");
    zai_string_view mixed = ZAI_STRL_VIEW("stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower ZAI_TSRMLS_CC));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed ZAI_TSRMLS_CC));
})

ZAI_SAPI_TEST_CASE("symbol/lookup/class", "root ns fqcn, exists", {
    zai_string_view name = ZAI_STRL_VIEW("\\stdClass");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name ZAI_TSRMLS_CC));
})

ZAI_SAPI_TEST_CASE("symbol/lookup/class", "root ns, does not exist", {
    zai_string_view ns   = ZAI_STRL_VIEW("\\");
    zai_string_view lower = ZAI_STRL_VIEW("nosuchclass");
    zai_string_view mixed = ZAI_STRL_VIEW("NoSuchClass");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower ZAI_TSRMLS_CC));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed ZAI_TSRMLS_CC));
})

ZAI_SAPI_TEST_CASE_WITH_STUB("symbol/lookup/class", "ns, exists", "./stubs/lookup/class/Stub.php", {
    zai_string_view ns   = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view name = ZAI_STRL_VIEW("Stub");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &name ZAI_TSRMLS_CC));
})

ZAI_SAPI_TEST_CASE_WITH_STUB("symbol/lookup/class", "ns fqcn, exists", "./stubs/lookup/class/Stub.php", {
    zai_string_view name = ZAI_STRL_VIEW("\\DDTraceTesting\\Stub");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name ZAI_TSRMLS_CC));
})

ZAI_SAPI_TEST_CASE_WITH_TAGS("symbol/lookup/class", "incorrect API usage", "[use][.]", {
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_CLASS, NULL, NULL ZAI_TSRMLS_CC));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_OBJECT, NULL, NULL ZAI_TSRMLS_CC));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_FRAME, NULL, NULL ZAI_TSRMLS_CC));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_STATIC, NULL, NULL ZAI_TSRMLS_CC));
})
