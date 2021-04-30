extern "C" {
#include "methods/methods.h"
#include "zai_sapi/zai_sapi.h"
}

#include <catch2/catch.hpp>
#include <cstring>
#include <ext/spl/spl_dllist.h> // For 'SplDoublyLinkedList' class entry

#define ZAI_STRL(str) (str), (sizeof(str) - 1)

#define REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE()            \
    REQUIRE(false == zai_sapi_unhandled_exception_exists()); \
    REQUIRE(zai_sapi_last_error_is_empty())

/***************************** zai_class_lookup() ****************************/

TEST_CASE("class lookup: (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("spldoublylinkedlist"));

    REQUIRE(ce == spl_ce_SplDoublyLinkedList);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\test"));

    REQUIRE(ce != NULL);
    REQUIRE(strcmp("Zai\\Methods\\Test", ce->name) == 0);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: root-scope prefix", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("\\spldoublylinkedlist"));

    REQUIRE(ce == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: wrong case", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("SplDoublyLinkedList"));

    REQUIRE(ce == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: NULL class name", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup(NULL, 42);

    REQUIRE(ce == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: 0 class len", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup("spldoublylinkedlist", 0);

    REQUIRE(ce == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/*********************** zai_call_method_without_args() **********************/

static zval *zai_instantiate_object_from_ce(zend_class_entry *ce) {
    TSRMLS_FETCH();
    zval *obj = NULL;
    ALLOC_ZVAL(obj);
    /* This can call zend_bailout. */
    object_init_ex(obj, ce);
    Z_SET_REFCOUNT_P(obj, 1);
    Z_SET_ISREF_P(obj);
    return obj;
}

TEST_CASE("call method: (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *obj = zai_instantiate_object_from_ce(spl_ce_SplDoublyLinkedList);
    zval *retval = NULL;
    // SplDoublyLinkedList::count()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("count"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(retval != NULL);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\test"));
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval *retval = NULL;
    // Zai\Methods\Test::returnsTrue()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("returnstrue"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(retval != NULL);
    REQUIRE(Z_TYPE_P(retval) == IS_BOOL);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: does not exist on object (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *obj = zai_instantiate_object_from_ce(spl_ce_SplDoublyLinkedList);
    zval *retval = NULL;
    // SplDoublyLinkedList::iDoNotExist()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("idonotexist"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: does not exist on object (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\test"));
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval *retval = NULL;
    // Zai\Methods\Test::iDoNotExist()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("idonotexist"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: accesses $this (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\test"));
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval *retval = NULL;
    // Zai\Methods\Test::usesThis()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("usesthis"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(retval != NULL);
    REQUIRE(Z_TYPE_P(retval) == IS_BOOL);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: throws exception (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/ExceptionTest.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\exceptiontest"));
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval *retval = NULL;
    // Zai\Methods\ExceptionTest::throwsException()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("throwsexception"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: throws exception with active frame (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/ExceptionTest.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\exceptiontest"));
    REQUIRE(ce != NULL);

    /* Simulate an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval *retval = NULL;
    // Zai\Methods\ExceptionTest::throwsException()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("throwsexception"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    zai_sapi_fake_frame_pop(&fake_frame);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: non-object", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *nonobj = NULL;
    MAKE_STD_ZVAL(nonobj);
    ZVAL_STRING(nonobj, "spldoublylinkedlist", 1);

    zval *retval = NULL;
    // SplDoublyLinkedList::count()
    bool result = zai_call_method_without_args(nonobj, ZAI_STRL("count"), &retval);
    zval_ptr_dtor(&nonobj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: NULL object", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *retval = NULL;
    // {NULL}::count()
    bool result = zai_call_method_without_args(NULL, ZAI_STRL("count"), &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: NULL method name", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *obj = zai_instantiate_object_from_ce(spl_ce_SplDoublyLinkedList);
    zval *retval = NULL;
    // SplDoublyLinkedList::{NULL}()
    bool result = zai_call_method_without_args(obj, NULL, 42, &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: 0 len method name", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *obj = zai_instantiate_object_from_ce(spl_ce_SplDoublyLinkedList);
    zval *retval = NULL;
    // SplDoublyLinkedList::()
    bool result = zai_call_method_without_args(obj, "count", 0, &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: non-NULL retval", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *obj = zai_instantiate_object_from_ce(spl_ce_SplDoublyLinkedList);
    zval *retval = (zval *)(void *)1;
    // SplDoublyLinkedList::count()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("count"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == (zval *)(void *)1);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: static method (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("datetime"));
    REQUIRE((ce != NULL && "ext/date required"));

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval *retval = NULL;
    // DateTime::getLastErrors()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("getlasterrors"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(retval != NULL);
    REQUIRE((Z_TYPE_P(retval) == IS_BOOL || Z_TYPE_P(retval) == IS_ARRAY));

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: static method (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\test"));
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval *retval = NULL;
    // Zai\Methods\Test::returns42()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("returns42"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(retval != NULL);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);
    REQUIRE(Z_LVAL_P(retval) == 42);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/******************* zai_call_static_method_without_args() *******************/

TEST_CASE("call static method: (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("datetime"));
    REQUIRE((ce != NULL && "ext/date required"));

    zval *retval = NULL;
    // DateTime::getLastErrors()
    bool result = zai_call_static_method_without_args(ce, ZAI_STRL("getlasterrors"), &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(retval != NULL);
    REQUIRE((Z_TYPE_P(retval) == IS_BOOL || Z_TYPE_P(retval) == IS_ARRAY));

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\test"));
    REQUIRE(ce != NULL);

    zval *retval = NULL;
    // Zai\Methods\Test::returns42()
    bool result = zai_call_static_method_without_args(ce, ZAI_STRL("returns42"), &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(retval != NULL);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);
    REQUIRE(Z_LVAL_P(retval) == 42);

    zval_ptr_dtor(&retval);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: call method on retval (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\test"));
    REQUIRE(ce != NULL);

    zval *retval_self = NULL;
    // Zai\Methods\Test::newSelf()
    bool result = zai_call_static_method_without_args(ce, ZAI_STRL("newself"), &retval_self);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(retval_self != NULL);
    REQUIRE(Z_TYPE_P(retval_self) == IS_OBJECT);
    REQUIRE(Z_OBJCE_P(retval_self) == ce);

    zval *retval_true = NULL;
    // Zai\Methods\Test::usesThis()
    bool result2 = zai_call_method_without_args(retval_self, ZAI_STRL("usesthis"), &retval_true);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result2 == true);
    REQUIRE(retval_true != NULL);
    REQUIRE(Z_TYPE_P(retval_true) == IS_BOOL);

    zval_ptr_dtor(&retval_true);
    zval_ptr_dtor(&retval_self);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: does not exist on class (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("datetime"));
    REQUIRE((ce != NULL && "ext/date required"));

    zval *retval = NULL;
    // DateTime::iDoNotExist()
    bool result = zai_call_static_method_without_args(ce, ZAI_STRL("idonotexist"), &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: does not exist on class (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\test"));
    REQUIRE(ce != NULL);

    zval *retval = NULL;
    // Zai\Methods\Test::iDoNotExist()
    bool result = zai_call_static_method_without_args(ce, ZAI_STRL("idonotexist"), &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: throws exception (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/ExceptionTest.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\exceptiontest"));
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval *retval = NULL;
    // Zai\Methods\ExceptionTest::throwsExceptionFromStatic()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("throwsexceptionfromstatic"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: throws exception with active frame (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/ExceptionTest.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\exceptiontest"));
    REQUIRE(ce != NULL);

    /* Simulate an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval *retval = NULL;
    // Zai\Methods\ExceptionTest::throwsExceptionFromStatic()
    bool result = zai_call_method_without_args(obj, ZAI_STRL("throwsexceptionfromstatic"), &retval);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    zai_sapi_fake_frame_pop(&fake_frame);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: NULL ce", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *retval = NULL;
    bool result = zai_call_static_method_without_args(NULL, ZAI_STRL("count"), &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: non-static method (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *retval = NULL;
    /* This call will fail because SplDoublyLinkedList::count() is not marked
     * with ZEND_ACC_ALLOW_STATIC.
     *
     * https://github.com/php/php-src/blob/PHP-5.4/ext/spl/spl_dllist.c#L1324
     */
    bool result = zai_call_static_method_without_args(spl_ce_SplDoublyLinkedList, ZAI_STRL("count"), &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: non-static method (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\test"));
    REQUIRE(ce != NULL);

    zval *retval = NULL;
    /* Calling the non-static method Zai\Methods\Test::returnsTrue() statically
     * is allowed here because the compiler marks non-static userland methods
     * with ZEND_ACC_ALLOW_STATIC.
     *
     * https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_compile.c#L1679
     */
    bool result = zai_call_static_method_without_args(ce, ZAI_STRL("returnstrue"), &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(retval != NULL);
    REQUIRE(Z_TYPE_P(retval) == IS_BOOL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: non-static method that accesses $this (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\test"));
    REQUIRE(ce != NULL);

    zval *retval = NULL;
    /* Although the compiler marks this non-static userland method
     * Zai\Methods\Test::usesThis() with ZEND_ACC_ALLOW_STATIC, the call will
     * fail when '$this' is accessed from a static context.
     *
     * https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_execute.c#L460-L506
     */
    bool result = zai_call_static_method_without_args(ce, ZAI_STRL("usesthis"), &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: abstract method (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/AbstractTest.php"));
    zend_class_entry *ce = zai_class_lookup(ZAI_STRL("zai\\methods\\abstracttest"));
    REQUIRE(ce != NULL);

    zval *retval = NULL;
    // Zai\Methods\AbstractTest::abstractMethod()
    bool result = zai_call_static_method_without_args(ce, ZAI_STRL("abstractmethod"), &retval);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(retval == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}
