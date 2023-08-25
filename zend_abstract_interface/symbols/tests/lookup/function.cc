extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE("symbol/lookup/function", "global, exists", {
    zai_str lower = ZAI_STRL("strlen");
    zai_str mixed = ZAI_STRL("strLen");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &lower));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &mixed));
})

TEA_TEST_CASE("symbol/lookup/function", "global, does not exist", {
    zai_str lower = ZAI_STRL("nosuchfunction");
    zai_str mixed = ZAI_STRL("NoSuchFunction");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &lower));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &mixed));
})

TEA_TEST_CASE("symbol/lookup/function", "root ns, exists", {
    zai_str ns   = ZAI_STRL("\\");
    zai_str lower = ZAI_STRL("strlen");
    zai_str mixed = ZAI_STRL("strLen");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower));
    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed));
})

TEA_TEST_CASE("symbol/lookup/function", "root ns fqcn, exists", {
    zai_str name = ZAI_STRL("\\strlen");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name));
})

TEA_TEST_CASE("symbol/lookup/function", "root ns, does not exist", {
    zai_str ns   = ZAI_STRL("\\");
    zai_str lower = ZAI_STRL("nosuchfunction");
    zai_str mixed = ZAI_STRL("NoSuchFunction");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &lower));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/function", "ns, exists", "./stubs/lookup/function/Stub.php", {
    zai_str ns   = ZAI_STRL("\\DDTraceTesting");
    zai_str name = ZAI_STRL("StubFunction");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &name));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/function", "ns fqcn, exists", "./stubs/lookup/function/Stub.php", {
    zai_str name = ZAI_STRL("\\DDTraceTesting\\StubFunction");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/function", "class method exists, public", "./stubs/lookup/function/Stub.php", {
    zai_str scope = ZAI_STRL("\\DDTraceTesting\\Stub");
    zai_str method = ZAI_STRL("existsPublic");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &scope);

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &method));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/function", "class method exists, protected", "./stubs/lookup/function/Stub.php", {
    zai_str scope = ZAI_STRL("\\DDTraceTesting\\Stub");
    zai_str method = ZAI_STRL("existsProtected");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &scope);

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &method));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/function", "class method exists, private", "./stubs/lookup/function/Stub.php", {
    zai_str scope = ZAI_STRL("\\DDTraceTesting\\Stub");
    zai_str method = ZAI_STRL("existsPrivate");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &scope);

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_CLASS, ce, &method));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/function", "object method exists, public", "./stubs/lookup/function/Stub.php", {
    zai_str scope = ZAI_STRL("\\DDTraceTesting\\Stub");
    zai_str method = ZAI_STRL("existsPublic");

    zval object;

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &scope);

    zai_symbol_new(&object, ce, 0);

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_OBJECT, &object, &method));

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/function", "object method exists, protected", "./stubs/lookup/function/Stub.php", {
    zai_str scope = ZAI_STRL("\\DDTraceTesting\\Stub");
    zai_str method = ZAI_STRL("existsProtected");

    zval object;

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &scope);

    zai_symbol_new(&object, ce, 0);

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_OBJECT, &object, &method));

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/function", "object method exists, private", "./stubs/lookup/function/Stub.php", {
    zai_str scope = ZAI_STRL("\\DDTraceTesting\\Stub");
    zai_str method = ZAI_STRL("existsPrivate");

    zval object;

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &scope);

    zai_symbol_new(&object, ce, 0);

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_OBJECT, &object, &method));

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_TAGS("symbol/lookup/function", "incorrect API usage", "[use][.]", {
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_STATIC, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_FUNCTION, ZAI_SYMBOL_SCOPE_FRAME, NULL, NULL));
})
