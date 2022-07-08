extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "public static", "./stubs/lookup/property/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_string_view name = ZAI_STRL_VIEW("publicStatic");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_CLASS, ce, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "protected static", "./stubs/lookup/property/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_string_view name = ZAI_STRL_VIEW("protectedStatic");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_CLASS, ce, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "private static", "./stubs/lookup/property/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_string_view name = ZAI_STRL_VIEW("privateStatic");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_CLASS, ce, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "static access instance property", "./stubs/lookup/property/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_string_view name = ZAI_STRL_VIEW("publicProperty");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_CLASS, ce, &name));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "undeclared static", "./stubs/lookup/property/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_string_view name = ZAI_STRL_VIEW("undeclaredStaticProperty");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_CLASS, ce, &name));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "public", "./stubs/lookup/property/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval object;
    zai_symbol_new(&object, ce, 0);

    zai_string_view name = ZAI_STRL_VIEW("publicProperty");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "protected", "./stubs/lookup/property/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval object;
    zai_symbol_new(&object, ce, 0);

    zai_string_view name = ZAI_STRL_VIEW("protectedProperty");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "private", "./stubs/lookup/property/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval object;
    zai_symbol_new(&object, ce, 0);

    zai_string_view name = ZAI_STRL_VIEW("privateProperty");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "dynamic", "./stubs/lookup/property/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval object;
    zai_symbol_new(&object, ce, 0);

    zai_string_view name = ZAI_STRL_VIEW("dynamicProperty");

    zval *property = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name);

    REQUIRE(property);
    REQUIRE(Z_TYPE_P(property) == IS_LONG);
    REQUIRE(Z_LVAL_P(property) == 42);

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/property", "undeclared", "./stubs/lookup/property/Stub.php", {
    zai_string_view ns = ZAI_STRL_VIEW("\\DDTraceTesting");
    zai_string_view cn = ZAI_STRL_VIEW("Stub");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval object;
    zai_symbol_new(&object, ce, 0);

    zai_string_view name = ZAI_STRL_VIEW("undeclaredProperty");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name));

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_TAGS("symbol/lookup/property", "incorrect API usage", "[use][.]", {
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_NAMESPACE, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_STATIC, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_PROPERTY, ZAI_SYMBOL_SCOPE_FRAME, NULL, NULL));
})
