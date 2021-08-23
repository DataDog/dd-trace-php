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

#if PHP_VERSION_ID >= 70000
#define ZVAL_IS_TRUE(z) (Z_TYPE_P(z) == IS_TRUE)
zval zval_used_for_init = {0};
#define RETPTR &retzv
#else
#define ZVAL_IS_TRUE(z) (Z_TYPE_P(z) == IS_BOOL && Z_BVAL_P(z))
#define IS_UNDEF IS_NULL
#undef zend_hash_next_index_insert
int zend_hash_next_index_insert(HashTable *ht, zval *zv) {
    zval *zptr;
    ALLOC_ZVAL(zptr);
    INIT_PZVAL_COPY(zptr, zv);
    return _zend_hash_index_update_or_next_insert(ht, 0, &zptr, sizeof(zval *), NULL, HASH_NEXT_INSERT ZEND_FILE_LINE_CC);
}
#define RETPTR &retval
#undef ZVAL_STRING
#define ZVAL_STRING(z, s) ZVAL_STRINGL(z, s, strlen(s), 1)
#endif

/************************* zai_call_function_literal() ************************/

TEST_CASE("call function: int args (internal)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval min = zval_used_for_init;
    zval max = zval_used_for_init;
    ZVAL_LONG(&min, MT_MIN);
    ZVAL_LONG(&max, MT_MAX);

    zval retzv = {0}, *retval = &retzv;
    // mt_rand($min, $max)
    bool result = zai_call_function_literal("mt_rand", RETPTR, &min, &max);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);
    REQUIRE((Z_LVAL_P(retval) >= MT_MIN && Z_LVAL_P(retval) <= MT_MAX));

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: array arg (internal)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval arg = zval_used_for_init;
    array_init(&arg);

    zval item0 = zval_used_for_init;
    zval item1 = zval_used_for_init;
    ZVAL_LONG(&item0, 2);
    ZVAL_LONG(&item1, 40);
    zend_hash_next_index_insert(Z_ARRVAL(arg), &item0);
    zend_hash_next_index_insert(Z_ARRVAL(arg), &item1);

    zval retzv = {0}, *retval = &retzv;
    // array_sum($arg)
    bool result = zai_call_function_literal("array_sum", RETPTR, &arg);
    zval_dtor(&arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);
    REQUIRE(Z_LVAL_P(retval) == 42);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: int arg (userland)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/basic.php"));

    zval arg = zval_used_for_init;
    ZVAL_LONG(&arg, 42);

    zval retzv = {0}, *retval = &retzv;
    // Zai\Functions\Test\return_arg($arg)
    bool result = zai_call_function_literal("zai\\functions\\test\\return_arg", RETPTR, &arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);
    REQUIRE(Z_LVAL_P(retval) == 42);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: bool arg (userland)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/basic.php"));

    zval arg = zval_used_for_init;
    ZVAL_TRUE(&arg);

    zval retzv = {0}, *retval = &retzv;
    // Zai\Functions\Test\return_arg($arg)
    bool result = zai_call_function_literal("zai\\functions\\test\\return_arg", RETPTR, &arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(ZVAL_IS_TRUE(retval));

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: string arg (userland)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/basic.php"));

    zval arg = zval_used_for_init;
    ZVAL_STRING(&arg, "foo string");

    zval retzv = {0}, *retval = &retzv;
    // Zai\Functions\Test\return_arg($arg)
    bool result = zai_call_function_literal("zai\\functions\\test\\return_arg", RETPTR, &arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_STRING);
    REQUIRE(strcmp("foo string", Z_STRVAL_P(retval)) == 0);

    zval_ptr_dtor(RETPTR);
    zval_dtor(&arg);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: NULL arg", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retzv = {0}, *retval = &retzv;
    bool result = zai_call_function_literal("array_sum", RETPTR, NULL);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: NULL args after refcounted arg", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval arg = zval_used_for_init;
    ZVAL_STRING(&arg, "foo string");

    zval retzv = {0}, *retval = &retzv;
    bool result = zai_call_function_literal("array_sum", RETPTR, &arg, NULL, NULL);
    zval_dtor(&arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function: more than MAX_ARGS", "[zai_functions]" SKIP_TEST_IN_DEBUG_MODE) {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval arg = zval_used_for_init;
    ZVAL_STRING(&arg, "foo string");

    zval retzv = {0}, *retval = &retzv;
    bool result = zai_call_function_literal("array_sum", RETPTR, &arg, &arg, &arg, &arg);
    zval_dtor(&arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/***************** zai_call_function_literal() (without args) *****************/

TEST_CASE("call function no args: (internal)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retzv = {0}, *retval = &retzv;
    // mt_rand()
    bool result = zai_call_function_literal("mt_rand", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: (userland)", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/basic.php"));

    zval retzv = {0}, *retval = &retzv;
    // Zai\Functions\Test\returns_true()
    bool result = zai_call_function_literal("zai\\functions\\test\\returns_true", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(ZVAL_IS_TRUE(retval));

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: does not exist", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retzv = {0}, *retval = &retzv;
    // Foo\iDoNotExist()
    bool result = zai_call_function_literal("foo\\idonotexist", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: root-scope prefix", "[zai_functions]" SKIP_TEST_IN_DEBUG_MODE) {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retzv = {0}, *retval = &retzv;
    bool result = zai_call_function_literal("\\mt_rand", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: wrong case", "[zai_functions]" SKIP_TEST_IN_DEBUG_MODE) {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retzv = {0}, *retval = &retzv;
    bool result = zai_call_function_literal("MT_RAND", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

#if PHP_VERSION_ID >= 70000
/* The 'disable_functions' INI setting only disables internal functions.
 * https://www.php.net/manual/en/ini.core.php#ini.disable-functions
 * This test is disabled on PHP5 as it there only is threated like a trivial function call emitting a warning
 */
TEST_CASE("call function no args: disable_functions INI", "[zai_functions]") {
    REQUIRE(zai_sapi_sinit());

    REQUIRE(zai_sapi_append_system_ini_entry("disable_functions", "mt_rand"));

    REQUIRE(zai_sapi_minit());
    REQUIRE(zai_sapi_rinit());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    /* Add a fake base/main frame to prevent the uncaught exception from
     * bubbling all the way up and raising a fatal error (zend_bailout).
     */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zval retzv = {0}, *retval = &retzv;
    // mt_rand()
    bool result = zai_call_function_literal("mt_rand", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zai_sapi_fake_frame_pop(&fake_frame);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}
#endif

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

    zval retzv = {0}, *retval = &retzv;
    // Zai\Functions\Test\throws_exception()
    bool result = zai_call_function_literal("zai\\functions\\test\\throws_exception", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

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

    zval min = zval_used_for_init;
    zval max = zval_used_for_init;
    ZVAL_LONG(&min, MT_MIN);
    ZVAL_LONG(&max, MT_MAX);

    zval retzv = {0}, *retval = &retzv;
    // mt_rand($min, $max)
    bool result = zai_call_function("mt_rand", sizeof("mt_rand") - 1, RETPTR, &min, &max);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);
    REQUIRE((Z_LVAL_P(retval) >= MT_MIN && Z_LVAL_P(retval) <= MT_MAX));

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: NULL name", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retzv = {0}, *retval = &retzv;
    bool result = zai_call_function(NULL, 42, RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call function no args: zero-len name", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retzv = {0}, *retval = &retzv;
    bool result = zai_call_function("mt_rand", 0, RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/*************************** zai_call_function_ex() ****************************/

TEST_CASE("call function: -1 args", "[zai_functions]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval arg = zval_used_for_init;
    ZVAL_STRING(&arg, "foo string");

    zval retzv = {0}, *retval = &retzv;
    bool result = zai_call_function_ex(ZEND_STRL("array_sum"), RETPTR ZAI_TSRMLS_CC, -1, &arg);
    zval_dtor(&arg);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}
