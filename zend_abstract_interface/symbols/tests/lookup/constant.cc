extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE("symbol/lookup/constant", "global, exists", {
    zai_str name = ZAI_STRL("PHP_VERSION");
    zai_str mixed = ZAI_STRL("Php_Version");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &mixed));
})

TEA_TEST_CASE("symbol/lookup/constant", "global, does not exist", {
    zai_str name = ZAI_STRL("NO_SUCH_CONSTANT");
    zai_str mixed = ZAI_STRL("No_Such_Constant");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &mixed));
})

TEA_TEST_CASE("symbol/lookup/constant", "root ns, exists", {
    zai_str ns   = ZAI_STRL("\\");
    zai_str name = ZAI_STRL("PHP_VERSION");
    zai_str mixed = ZAI_STRL("Php_Version");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &name));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed));
})

TEA_TEST_CASE("symbol/lookup/constant", "root ns fqn, exists", {
    zai_str name = ZAI_STRL("\\PHP_VERSION");

    REQUIRE(zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &name));
})

TEA_TEST_CASE("symbol/lookup/constant", "root ns, does not exist", {
    zai_str ns   = ZAI_STRL("\\");
    zai_str name  = ZAI_STRL("NO_SUCH_CONSTANT");
    zai_str mixed = ZAI_STRL("No_Such_Constant");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &name));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &mixed));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/constant", "ns, exists", "./stubs/lookup/constant/Stub.php", {
    zai_str ns   = ZAI_STRL("\\DDTraceTesting");
    zai_str name = ZAI_STRL("DD_TRACE_TESTING");

    zval *constant = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &name);

    REQUIRE(constant);
    REQUIRE(Z_TYPE_P(constant) == IS_LONG);
    REQUIRE(Z_LVAL_P(constant) == 42);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/constant", "ns, does not exists", "./stubs/lookup/constant/Stub.php", {
    zai_str ns   = ZAI_STRL("\\DDTraceTesting");
    zai_str name = ZAI_STRL("DD_TEST_CONSTANT_DOES_NOT_EXIST");

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &name));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/constant", "class, exists", "./stubs/lookup/constant/Stub.php", {
    zai_str ns   = ZAI_STRL("\\DDTraceTesting");
    zai_str cn   = ZAI_STRL("Stub");
    zai_str name = ZAI_STRL("DD_TRACE_TESTING");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    zval *constant = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_CLASS, ce, &name);

    REQUIRE(constant);
    REQUIRE(Z_TYPE_P(constant) == IS_LONG);
    REQUIRE(Z_LVAL_P(constant) == 42);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/constant", "class, does not exist", "./stubs/lookup/constant/Stub.php", {
    zai_str ns   = ZAI_STRL("\\DDTraceTesting");
    zai_str cn   = ZAI_STRL("Stub");
    zai_str name = ZAI_STRL("DD_TRACE_TESTING_DOES_NOT_EXIST");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_CLASS, ce, &name));
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/constant", "object, exists", "./stubs/lookup/constant/Stub.php", {
    zai_str ns   = ZAI_STRL("\\DDTraceTesting");
    zai_str cn   = ZAI_STRL("Stub");
    zai_str name = ZAI_STRL("DD_TRACE_TESTING");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    zval object;
    zai_symbol_new(&object, ce, 0);

    zval *constant = (zval*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name);

    REQUIRE(constant);
    REQUIRE(Z_TYPE_P(constant) == IS_LONG);
    REQUIRE(Z_LVAL_P(constant) == 42);

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_STUB("symbol/lookup/constant", "object, does not exist", "./stubs/lookup/constant/Stub.php", {
    zai_str ns   = ZAI_STRL("\\DDTraceTesting");
    zai_str cn   = ZAI_STRL("Stub");
    zai_str name = ZAI_STRL("DD_TRACE_TESTING_DOES_NOT_EXIST");

    zend_class_entry *ce = (zend_class_entry*) zai_symbol_lookup(ZAI_SYMBOL_TYPE_CLASS, ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, &cn);

    zval object;
    zai_symbol_new(&object, ce, 0);

    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_OBJECT, &object, &name));

    zval_ptr_dtor(&object);
})

TEA_TEST_CASE_WITH_TAGS("symbol/lookup/constant", "incorrect API usage", "[use][.]", {
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_STATIC, NULL, NULL));
    REQUIRE(!zai_symbol_lookup(ZAI_SYMBOL_TYPE_CONSTANT, ZAI_SYMBOL_SCOPE_FRAME, NULL, NULL));
})
