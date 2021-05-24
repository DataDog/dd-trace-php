extern "C" {
#include "functions/functions.h"
#include "zai_sapi/zai_sapi.h"
}

#include <catch2/catch.hpp>
#include <cstring>

#define REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE()            \
    REQUIRE(false == zai_sapi_unhandled_exception_exists()); \
    REQUIRE(zai_sapi_last_error_is_empty())

#ifndef NDEBUG
#define SKIP_TEST_IN_DEBUG_MODE "[.]"
#else
#define SKIP_TEST_IN_DEBUG_MODE
#endif

#define MT_MIN 0
#define MT_MAX 42

/************************* zai_call_function_literal() ************************/

TEST_CASE("call function: int args (internal)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval min = {0};
    zval max = {0};
    ZVAL_LONG(&min, MT_MIN);
    ZVAL_LONG(&max, MT_MAX);

    zval retval = {0};
    // mt_rand($min, $max)
    bool result = zai_call_function_literal("mt_rand", &retval, &min, &max);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE(retval) == IS_LONG);
    REQUIRE((Z_LVAL(retval) >= MT_MIN && Z_LVAL(retval) <= MT_MAX));

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: array arg (internal)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval arg = {0};
    ZVAL_NEW_ARR(&arg);
    zend_hash_init(Z_ARRVAL(arg), 8, NULL, NULL, /* persistent */ 0);

    zval item0 = {0};
    zval item1 = {0};
    ZVAL_LONG(&item0, 2);
    ZVAL_LONG(&item1, 40);
    zend_hash_next_index_insert(Z_ARRVAL(arg), &item0);
    zend_hash_next_index_insert(Z_ARRVAL(arg), &item1);

    zval retval = {0};
    // array_sum($arg)
    bool result = zai_call_function_literal("array_sum", &retval, &arg);
    zval_ptr_dtor(&arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE(retval) == IS_LONG);
    REQUIRE(Z_LVAL(retval) == 42);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: int arg (userland)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/basic.php"));

    zval arg = {0};
    ZVAL_LONG(&arg, 42);

    zval retval = {0};
    // Zai\Functions\Test\return_arg($arg)
    bool result = zai_call_function_literal("zai\\functions\\test\\return_arg", &retval, &arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE(retval) == IS_LONG);
    REQUIRE(Z_LVAL(retval) == 42);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: bool arg (userland)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/basic.php"));

    zval arg = {0};
    ZVAL_TRUE(&arg);

    zval retval = {0};
    // Zai\Functions\Test\return_arg($arg)
    bool result = zai_call_function_literal("zai\\functions\\test\\return_arg", &retval, &arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE(retval) == IS_TRUE);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: string arg (userland)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/basic.php"));

    zval arg = {0};
    zend_string *str = zend_string_init(ZEND_STRL("foo string"), /* persistent */ 0);
    ZVAL_STR(&arg, str);

    zval retval = {0};
    // Zai\Functions\Test\return_arg($arg)
    bool result = zai_call_function_literal("zai\\functions\\test\\return_arg", &retval, &arg);
    zval_ptr_dtor(&arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE(retval) == IS_STRING);
    REQUIRE(strcmp("foo string", Z_STRVAL(retval)) == 0);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: NULL arg", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retval = {0};
    bool result = zai_call_function_literal("array_sum", &retval, NULL);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: NULL args after refcounted arg", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval arg = {0};
    zend_string *str = zend_string_init(ZEND_STRL("foo string"), /* persistent */ 0);
    ZVAL_STR(&arg, str);

    zval retval = {0};
    bool result = zai_call_function_literal("array_sum", &retval, &arg, NULL, NULL);
    zval_ptr_dtor(&arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: more than MAX_ARGS", "[zai_functions]" SKIP_TEST_IN_DEBUG_MODE) {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval arg = {0};
    zend_string *str = zend_string_init(ZEND_STRL("foo string"), /* persistent */ 0);
    ZVAL_STR(&arg, str);

    zval retval = {0};
    bool result = zai_call_function_literal("array_sum", &retval, &arg, &arg, &arg, &arg);
    zval_ptr_dtor(&arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/***************** zai_call_function_literal() (without args) *****************/

TEST_CASE("call function no args: (internal)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retval = {0};
    // mt_rand()
    bool result = zai_call_function_literal("mt_rand", &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE(retval) == IS_LONG);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: (userland)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/basic.php"));

    zval retval = {0};
    // Zai\Functions\Test\returns_true()
    bool result = zai_call_function_literal("zai\\functions\\test\\returns_true", &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE(retval) == IS_TRUE);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: does not exist", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retval = {0};
    // Foo\iDoNotExist()
    bool result = zai_call_function_literal("foo\\idonotexist", &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: root-scope prefix", "[zai_functions]" SKIP_TEST_IN_DEBUG_MODE) {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retval = {0};
    bool result = zai_call_function_literal("\\mt_rand", &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: wrong case", "[zai_functions]" SKIP_TEST_IN_DEBUG_MODE) {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retval = {0};
    bool result = zai_call_function_literal("MT_RAND", &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/* The 'disable_functions' INI setting only disables internal functions.
 * https://www.php.net/manual/en/ini.core.php#ini.disable-functions
 */
TEST_CASE("call function no args: disable_functions INI", "[zai_functions]") {
    REQUIRE(zai_sapi_sinit());

    REQUIRE(zai_sapi_append_system_ini_entry("disable_functions", "mt_rand"));

    REQUIRE(zai_sapi_minit());
    REQUIRE(zai_sapi_rinit());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retval = {0};
    // mt_rand()
    bool result = zai_call_function_literal("mt_rand", &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: throws exception (userland)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/basic.php"));

    /* Add a fake base/main frame to prevent the uncaught exception from
     * bubbling all the way up and raising a fatal error (zend_bailout).
     */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zval retval = {0};
    // Zai\Functions\Test\throws_exception()
    bool result = zai_call_function_literal("zai\\functions\\test\\throws_exception", &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    zai_sapi_fake_frame_pop(&fake_frame);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: NULL retval", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    bool result = zai_call_function_literal("mt_rand", NULL);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/**************************** zai_call_function() *****************************/

TEST_CASE("call function: int args (non-literal function name)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval min = {0};
    zval max = {0};
    ZVAL_LONG(&min, MT_MIN);
    ZVAL_LONG(&max, MT_MAX);

    zend_string *fn = zend_string_init(ZEND_STRL("mt_rand"), /* persistent */ 0);

    zval retval = {0};
    // mt_rand($min, $max)
    bool result = zai_call_function(ZSTR_VAL(fn), ZSTR_LEN(fn), &retval, &min, &max);

    zend_string_release(fn);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE(retval) == IS_LONG);
    REQUIRE((Z_LVAL(retval) >= MT_MIN && Z_LVAL(retval) <= MT_MAX));

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: NULL name", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retval = {0};
    bool result = zai_call_function(NULL, 42, &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: zero-len name", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retval = {0};
    bool result = zai_call_function("mt_rand", 0, &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/*************************** zai_call_function_ex() ****************************/

TEST_CASE("call function: -1 args", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval arg = {0};
    zend_string *str = zend_string_init(ZEND_STRL("foo string"), /* persistent */ 0);
    ZVAL_STR(&arg, str);

    zval retval = {0};
    bool result = zai_call_function_ex(ZEND_STRL("array_sum"), &retval, -1, &arg);
    zval_ptr_dtor(&arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE(retval) == IS_UNDEF);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}
