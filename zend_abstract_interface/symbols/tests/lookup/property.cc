extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "public static", "./stubs/lookup/property/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_str name = ZAI_STRL("publicStatic");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_CLASS, ce, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "protected static", "./stubs/lookup/property/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_str name = ZAI_STRL("protectedStatic");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_CLASS, ce, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "private static", "./stubs/lookup/property/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_str name = ZAI_STRL("privateStatic");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_CLASS, ce, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "static access instance property", "./stubs/lookup/property/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_str name = ZAI_STRL("publicProperty");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_CLASS, ce, &name));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "undeclared static", "./stubs/lookup/property/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_str name = ZAI_STRL("undeclaredStaticProperty");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_CLASS, ce, &name));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "public", "./stubs/lookup/property/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval object;
    zai_symbol_new(&object, ce, 0);

    zai_str name = ZAI_STRL("publicProperty");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "protected", "./stubs/lookup/property/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval object;
    zai_symbol_new(&object, ce, 0);

    zai_str name = ZAI_STRL("protectedProperty");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "private", "./stubs/lookup/property/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval object;
    zai_symbol_new(&object, ce, 0);

    zai_str name = ZAI_STRL("privateProperty");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "dynamic", "./stubs/lookup/property/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval object;
    zai_symbol_new(&object, ce, 0);

    zai_str name = ZAI_STRL("dynamicProperty");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "undeclared", "./stubs/lookup/property/Stub.php", {
    zai_str ns = ZAI_STRL("\\DDTraceTesting");
    zai_str cn = ZAI_STRL("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval object;
    zai_symbol_new(&object, ce, 0);

    zai_str name = ZAI_STRL("undeclaredProperty");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name));

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_TAGS("symbol/lookup/property", "incorrect API usage", "[use][.]", {
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_NAMESPACE, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_STATIC, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_FRAME, NULL, NULL));
})
