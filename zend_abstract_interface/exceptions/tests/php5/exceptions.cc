extern "C" {
#include "value/value.h"
#include "symbols/symbols.h"
#include "exceptions/exceptions.h"

#include <ext/standard/php_smart_str.h>
}

#include "tea/testing/catch2.hpp"
#include <cstring>

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "reading message with non-string type returns a non-empty string", "./stubs/functions.php", {
    zval *ex;
    ZAI_VALUE_INIT(ex);

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\broken_exception"), &ex TEA_TSRMLS_CC, 0);

    zai_string_view str = zai_exception_message(ex TEA_TSRMLS_CC);
    REQUIRE(str.len > 0);

    ZAI_VALUE_DTOR(ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "reading message from exception", "./stubs/functions.php", {
    zval *ex;
    ZAI_VALUE_INIT(ex);
    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\legitimate_exception"), &ex TEA_TSRMLS_CC, 0);

    zai_string_view str = zai_exception_message(ex TEA_TSRMLS_CC);
    REQUIRE(strcmp(str.ptr, "msg") == 0);

    ZAI_VALUE_DTOR(ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "reading message from exception subclass", "./stubs/functions.php", {
    zval *ex;
    ZAI_VALUE_INIT(ex);

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\child_exception"), &ex TEA_TSRMLS_CC, 0);

    zai_string_view str = zai_exception_message(ex TEA_TSRMLS_CC);
    REQUIRE(strcmp(str.ptr, "msg") == 0);

    ZAI_VALUE_DTOR(ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "reading trace from exception", "./stubs/functions.php", {
    zval *ex;
    ZAI_VALUE_INIT(ex);

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\legitimate_exception"), &ex TEA_TSRMLS_CC, 0);

    smart_str str = zai_get_trace_without_args_from_exception(ex TEA_TSRMLS_CC);
    REQUIRE(strcmp(str.c, "#0 [internal function]: zai\\exceptions\\test\\legitimate_exception()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    ZAI_VALUE_DTOR(ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing trace with invalid frame", "./stubs/functions.php", {
    zval *trace;
    ZAI_VALUE_INIT(trace);

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\trace_with_bad_frame"), &trace TEA_TSRMLS_CC, 0);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 functions.php(7): ()\n"
                                            "#1 [invalid frame]\n"
                                            "#2 {main}") == 0);

    smart_str_free(&str);
    ZAI_VALUE_DTOR(trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing valid trace", "./stubs/functions.php", {
    zval *trace;
    ZAI_VALUE_INIT(trace);

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\good_trace_with_all_values"), &trace TEA_TSRMLS_CC, 0);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 functions.php(7): Foo--bar()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    ZAI_VALUE_DTOR(trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing trace with invalid filename", "./stubs/functions.php", {
    zval *trace;
    ZAI_VALUE_INIT(trace);

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\trace_with_invalid_filename"), &trace TEA_TSRMLS_CC, 0);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 [unknown file]()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    ZAI_VALUE_DTOR(trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing trace without line number", "./stubs/functions.php", {
    zval *trace;
    ZAI_VALUE_INIT(trace);

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\trace_without_line_number"), &trace TEA_TSRMLS_CC, 0);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 functions.php(0): ()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    ZAI_VALUE_DTOR(trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing trace with invalid line number", "./stubs/functions.php", {
    zval *trace;
    ZAI_VALUE_INIT(trace);

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\trace_with_invalid_line_number"), &trace TEA_TSRMLS_CC, 0);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 functions.php(0): ()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    ZAI_VALUE_DTOR(trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing trace with invalid class, type and function", "./stubs/functions.php", {
    zval *trace;
    ZAI_VALUE_INIT(trace);

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\trace_with_invalid_class_type_function"), &trace TEA_TSRMLS_CC, 0);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 functions.php(7): [unknown][unknown][unknown]()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    ZAI_VALUE_DTOR(trace);
})
