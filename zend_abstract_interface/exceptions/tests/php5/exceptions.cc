extern "C" {
#include "tea/sapi.h"
#include "exceptions/exceptions.h"
#include "functions/functions.h"

#include <ext/standard/php_smart_str.h>
}

#include "tea/testing/catch2.hpp"
#include <cstring>

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "reading message with non-string type returns a non-empty string", "./stubs/functions.php", {
    zval *ex;
    zai_call_function_literal("zai\\exceptions\\test\\broken_exception", &ex);

    zai_string_view str = zai_exception_message(ex TSRMLS_CC);
    REQUIRE(str.len > 0);

    zval_ptr_dtor(&ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "reading message from exception", "./stubs/functions.php", {
    zval *ex;
    zai_call_function_literal("zai\\exceptions\\test\\legitimate_exception", &ex);

    zai_string_view str = zai_exception_message(ex TSRMLS_CC);
    REQUIRE(strcmp(str.ptr, "msg") == 0);

    zval_ptr_dtor(&ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "reading message from exception subclass", "./stubs/functions.php", {
    zval *ex;
    zai_call_function_literal("zai\\exceptions\\test\\child_exception", &ex);

    zai_string_view str = zai_exception_message(ex TSRMLS_CC);
    REQUIRE(strcmp(str.ptr, "msg") == 0);

    zval_ptr_dtor(&ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "reading trace from exception", "./stubs/functions.php", {
    zval *ex;
    zai_call_function_literal("zai\\exceptions\\test\\legitimate_exception", &ex);

    smart_str str = zai_get_trace_without_args_from_exception(ex TSRMLS_CC);
    REQUIRE(strcmp(str.c, "#0 [internal function]: zai\\exceptions\\test\\legitimate_exception()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    zval_ptr_dtor(&ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing trace with invalid frame", "./stubs/functions.php", {
    zval *trace;
    zai_call_function_literal("zai\\exceptions\\test\\trace_with_bad_frame", &trace);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 functions.php(7): ()\n"
                                            "#1 [invalid frame]\n"
                                            "#2 {main}") == 0);

    smart_str_free(&str);
    zval_ptr_dtor(&trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing valid trace", "./stubs/functions.php", {
    zval *trace;
    zai_call_function_literal("zai\\exceptions\\test\\good_trace_with_all_values", &trace);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 functions.php(7): Foo--bar()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    zval_ptr_dtor(&trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing trace with invalid filename", "./stubs/functions.php", {
    zval *trace;
    zai_call_function_literal("zai\\exceptions\\test\\trace_with_invalid_filename", &trace);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 [unknown file]()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    zval_ptr_dtor(&trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing trace without line number", "./stubs/functions.php", {
    zval *trace;
    zai_call_function_literal("zai\\exceptions\\test\\trace_without_line_number", &trace);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 functions.php(0): ()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    zval_ptr_dtor(&trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing trace with invalid line number", "./stubs/functions.php", {
    zval *trace;
    zai_call_function_literal("zai\\exceptions\\test\\trace_with_invalid_line_number", &trace);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 functions.php(0): ()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    zval_ptr_dtor(&trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions/php5", "serializing trace with invalid class, type and function", "./stubs/functions.php", {
    zval *trace;
    zai_call_function_literal("zai\\exceptions\\test\\trace_with_invalid_class_type_function", &trace);

    smart_str str = zai_get_trace_without_args(Z_ARRVAL_P(trace));
    printf("%s", str.c);
    REQUIRE(strcmp(str.c, "#0 functions.php(7): [unknown][unknown][unknown]()\n"
                                            "#1 {main}") == 0);

    smart_str_free(&str);
    zval_ptr_dtor(&trace);
})
