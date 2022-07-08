extern "C" {
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE("symbol/call/internal", "global", {
    zval param;

    ZVAL_STRINGL(&param, "string", sizeof("string")-1);

    zval result;

    zai_string_view fn = ZAI_STRL_VIEW("\\strlen");

    zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result, 1, &param);

    REQUIRE(Z_TYPE(result) == IS_LONG);
    REQUIRE(Z_LVAL(result) == 6);

    zval_ptr_dtor(&param);
    zval_ptr_dtor(&result);
})

TEA_TEST_CASE("symbol/call/internal", "root ns", {
    zval param;

    ZVAL_STRINGL(&param, "string", sizeof("string")-1);

    zval result;

    zai_string_view ns = ZAI_STRL_VIEW("\\");
    zai_string_view fn = ZAI_STRL_VIEW("strlen");

    zai_symbol_call(ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result, 1, &param);

    REQUIRE(Z_TYPE(result) == IS_LONG);
    REQUIRE(Z_LVAL(result) == 6);

    zval_ptr_dtor(&param);
    zval_ptr_dtor(&result);
})

TEA_TEST_CASE("symbol/call/internal", "empty ns", {
    zval param;

    ZVAL_STRINGL(&param, "string", sizeof("string")-1);

    zval result;

    zai_string_view ns = ZAI_STRL_VIEW("");
    zai_string_view fn = ZAI_STRL_VIEW("strlen");

    zai_symbol_call(ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result, 1, &param);

    REQUIRE(Z_TYPE(result) == IS_LONG);
    REQUIRE(Z_LVAL(result) == 6);

    zval_ptr_dtor(&param);
    zval_ptr_dtor(&result);
})

TEA_TEST_CASE("symbol/call/internal", "named (macro)", {
    zval param;

    ZVAL_STRINGL(&param, "string", sizeof("string")-1);

    zval result;

    zai_string_view fn = ZAI_STRL_VIEW("strlen");

    zai_symbol_call_named(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &fn, &result, 1, &param);

    REQUIRE(Z_TYPE(result) == IS_LONG);
    REQUIRE(Z_LVAL(result) == 6);

    zval_ptr_dtor(&param);
    zval_ptr_dtor(&result);
})

TEA_TEST_CASE("symbol/call/internal", "known (macro)", {
    zval param;

    ZVAL_STRINGL(&param, "string", sizeof("string")-1);

    zval result;

    zai_string_view fn = ZAI_STRL_VIEW("strlen");
    zend_function *fe = zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &fn);

    REQUIRE(fe);

    zai_symbol_call_known(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, fe, &result, 1, &param);

    REQUIRE(Z_TYPE(result) == IS_LONG);
    REQUIRE(Z_LVAL(result) == 6);

    zval_ptr_dtor(&param);
    zval_ptr_dtor(&result);
})
