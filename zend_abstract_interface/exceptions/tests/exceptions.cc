extern "C" {
#include "symbols/symbols.h"
#include "exceptions/exceptions.h"
}

#include "tea/testing/catch2.hpp"
#include <cstring>

TEA_TEST_CASE_WITH_STUB("exceptions", "reading message with non-string type returns a non-empty string", "./stubs/functions.php", {
    zval ex;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\broken_exception"), &ex, 0);

    zend_string *str = zai_exception_message(Z_OBJ(ex));
    REQUIRE(ZSTR_LEN(str) > 0);

    zval_ptr_dtor(&ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "reading message from exception", "./stubs/functions.php", {
    zval ex;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\legitimate_exception"), &ex, 0);

    zend_string *str = zai_exception_message(Z_OBJ(ex));
    REQUIRE(zend_string_equals_literal(str, "msg"));

    zval_ptr_dtor(&ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "reading message from exception subclass", "./stubs/functions.php", {
    zval ex;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\child_exception"), &ex, 0);

    zend_string *str = zai_exception_message(Z_OBJ(ex));
    REQUIRE(zend_string_equals_literal(str, "msg"));

    zval_ptr_dtor(&ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "reading message from error", "./stubs/functions.php", {
    zval ex;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\legitimate_error"), &ex, 0);

    zend_string *str = zai_exception_message(Z_OBJ(ex));
    REQUIRE(zend_string_equals_literal(str, "msg"));

    zval_ptr_dtor(&ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "reading message from error subclass", "./stubs/functions.php", {
    zval ex;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\child_error"), &ex, 0);

    zend_string *str = zai_exception_message(Z_OBJ(ex));
    REQUIRE(zend_string_equals_literal(str, "msg"));

    zval_ptr_dtor(&ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "reading trace from exception", "./stubs/functions.php", {
    zval ex;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\legitimate_exception"), &ex, 0);

    zend_string *str = zai_get_trace_without_args_from_exception(Z_OBJ(ex));
    REQUIRE(zend_string_equals_literal(str, "#0 [internal function]: zai\\exceptions\\test\\legitimate_exception()\n"
                                            "#1 {main}"));

    zend_string_release(str);
    zval_ptr_dtor(&ex);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "serializing trace with invalid frame", "./stubs/functions.php", {
    zval trace;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\trace_with_bad_frame"), &trace, 0);

    zend_string *str = zai_get_trace_without_args(Z_ARR(trace));
    printf("%s", ZSTR_VAL(str));
    REQUIRE(zend_string_equals_literal(str, "#0 functions.php(7): ()\n"
                                            "#1 [invalid frame]\n"
                                            "#2 {main}"));

    zend_string_release(str);
    zval_ptr_dtor(&trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "serializing valid trace", "./stubs/functions.php", {
    zval trace;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\good_trace_with_all_values"), &trace, 0);

    zend_string *str = zai_get_trace_without_args(Z_ARR(trace));
    printf("%s", ZSTR_VAL(str));
    REQUIRE(zend_string_equals_literal(str, "#0 functions.php(7): Foo--bar()\n"
                                            "#1 {main}"));

    zend_string_release(str);
    zval_ptr_dtor(&trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "serializing trace with invalid filename", "./stubs/functions.php", {
    zval trace;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\trace_with_invalid_filename"), &trace, 0);

    zend_string *str = zai_get_trace_without_args(Z_ARR(trace));
    printf("%s", ZSTR_VAL(str));
    REQUIRE(zend_string_equals_literal(str, "#0 [unknown file]()\n"
                                            "#1 {main}"));

    zend_string_release(str);
    zval_ptr_dtor(&trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "serializing trace without line number", "./stubs/functions.php", {
    zval trace;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\trace_without_line_number"), &trace, 0);

    zend_string *str = zai_get_trace_without_args(Z_ARR(trace));
    printf("%s", ZSTR_VAL(str));
    REQUIRE(zend_string_equals_literal(str, "#0 functions.php(0): ()\n"
                                            "#1 {main}"));

    zend_string_release(str);
    zval_ptr_dtor(&trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "serializing trace with invalid line number", "./stubs/functions.php", {
    zval trace;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\trace_with_invalid_line_number"), &trace, 0);

    zend_string *str = zai_get_trace_without_args(Z_ARR(trace));
    printf("%s", ZSTR_VAL(str));
    REQUIRE(zend_string_equals_literal(str, "#0 functions.php(0): ()\n"
                                            "#1 {main}"));

    zend_string_release(str);
    zval_ptr_dtor(&trace);
})

TEA_TEST_CASE_WITH_STUB("exceptions", "serializing trace with invalid class, type and function", "./stubs/functions.php", {
    zval trace;

    zai_symbol_call_literal(ZEND_STRL("zai\\exceptions\\test\\trace_with_invalid_class_type_function"), &trace, 0);

    zend_string *str = zai_get_trace_without_args(Z_ARR(trace));
    printf("%s", ZSTR_VAL(str));
    REQUIRE(zend_string_equals_literal(str, "#0 functions.php(7): [unknown][unknown][unknown]()\n"
                                            "#1 {main}"));

    zend_string_release(str);
    zval_ptr_dtor(&trace);
})
