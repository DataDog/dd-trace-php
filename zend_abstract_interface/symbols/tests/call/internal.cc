extern "C" {
#include "value/value.h"
#include "symbols/symbols.h"
}

#include "tea/testing/catch2.hpp"
#include <cstdlib>
#include <cstring>

TEA_TEST_CASE("symbol/call/internal", "global", {
    zval *param;

    ZAI_VALUE_MAKE(param);
    ZAI_VALUE_STRINGL(param, "string", sizeof("string")-1);

    zval *result;
    ZAI_VALUE_INIT(result);

    zai_string_view fn = ZAI_STRL_VIEW("\\strlen");

    zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result TEA_TSRMLS_CC, 1, &param);

    REQUIRE(Z_TYPE_P(result) == IS_LONG);
    REQUIRE(Z_LVAL_P(result) == 6);

    ZAI_VALUE_DTOR(param);
    ZAI_VALUE_DTOR(result);
})

TEA_TEST_CASE("symbol/call/internal", "root ns", {
    zval *param;

    ZAI_VALUE_MAKE(param);
    ZAI_VALUE_STRINGL(param, "string", sizeof("string")-1);

    zval *result;
    ZAI_VALUE_INIT(result);

    zai_string_view ns = ZAI_STRL_VIEW("\\");
    zai_string_view fn = ZAI_STRL_VIEW("strlen");

    zai_symbol_call(ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result TEA_TSRMLS_CC, 1, &param);

    REQUIRE(Z_TYPE_P(result) == IS_LONG);
    REQUIRE(Z_LVAL_P(result) == 6);

    ZAI_VALUE_DTOR(param);
    ZAI_VALUE_DTOR(result);
})

TEA_TEST_CASE("symbol/call/internal", "empty ns", {
    zval *param;

    ZAI_VALUE_MAKE(param);
    ZAI_VALUE_STRINGL(param, "string", sizeof("string")-1);

    zval *result;
    ZAI_VALUE_INIT(result);

    zai_string_view ns = ZAI_STRL_VIEW("");
    zai_string_view fn = ZAI_STRL_VIEW("strlen");

    zai_symbol_call(ZAI_SYMBOL_SCOPE_NAMESPACE, &ns, ZAI_SYMBOL_FUNCTION_NAMED, &fn, &result TEA_TSRMLS_CC, 1, &param);

    REQUIRE(Z_TYPE_P(result) == IS_LONG);
    REQUIRE(Z_LVAL_P(result) == 6);

    ZAI_VALUE_DTOR(param);
    ZAI_VALUE_DTOR(result);
})

TEA_TEST_CASE("symbol/call/internal", "named (macro)", {
    zval *param;

    ZAI_VALUE_MAKE(param);
    ZAI_VALUE_STRINGL(param, "string", sizeof("string")-1);

    zval *result;

    ZAI_VALUE_INIT(result);

    zai_string_view fn = ZAI_STRL_VIEW("strlen");

    zai_symbol_call_named(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &fn, &result TEA_TSRMLS_CC, 1, &param);

    REQUIRE(Z_TYPE_P(result) == IS_LONG);
    REQUIRE(Z_LVAL_P(result) == 6);

    ZAI_VALUE_DTOR(param);
    ZAI_VALUE_DTOR(result);
})

TEA_TEST_CASE("symbol/call/internal", "known (macro)", {
    zval *param;

    ZAI_VALUE_MAKE(param);
    ZAI_VALUE_STRINGL(param, "string", sizeof("string")-1);

    zval *result;

    ZAI_VALUE_INIT(result);

    zai_string_view fn = ZAI_STRL_VIEW("strlen");
    zend_function *fe = zai_symbol_lookup_function(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, &fn TEA_TSRMLS_CC);

    REQUIRE(fe);

    zai_symbol_call_known(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, fe, &result TEA_TSRMLS_CC, 1, &param);

    REQUIRE(Z_TYPE_P(result) == IS_LONG);
    REQUIRE(Z_LVAL_P(result) == 6);

    ZAI_VALUE_DTOR(param);
    ZAI_VALUE_DTOR(result);
})
