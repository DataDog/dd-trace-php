extern "C" {
#include "methods/methods.h"
#include "zai_sapi/zai_sapi.h"
}

#include <catch2/catch.hpp>
#include <cstring>
#include <ext/spl/spl_dllist.h> // For 'SplDoublyLinkedList' class entry

#define REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE()            \
    REQUIRE(false == zai_sapi_unhandled_exception_exists()); \
    REQUIRE(zai_sapi_last_error_is_empty())

#ifndef NDEBUG
#define SKIP_TEST_IN_DEBUG_MODE "[.]"
#else
#define SKIP_TEST_IN_DEBUG_MODE
#endif

#if PHP_VERSION_ID >= 70000
zval zval_used_for_init = {0};
#define RETPTR &retzv
#else
#define IS_UNDEF IS_NULL
#define RETPTR &retval
#undef ZVAL_STRING
#define ZVAL_STRING(z, s) ZVAL_STRINGL(z, s, strlen(s), 1)
#endif

static zval *zai_instantiate_object_from_ce(zend_class_entry *ce) {
    TSRMLS_FETCH();
    zval *obj = NULL;
    ALLOC_ZVAL(obj);
    /* This can call zend_bailout. */
    object_init_ex(obj, ce);
    Z_SET_REFCOUNT_P(obj, 1);
    Z_SET_ISREF_P(obj);

    // Only calls constructors without passing any args
    if (ce->constructor && (ce->constructor->common.fn_flags & ZEND_ACC_PUBLIC)) {
        zval *retval_ptr = NULL;

        zend_fcall_info fci = empty_fcall_info;
        fci.size = sizeof(zend_fcall_info);
        fci.object_ptr = obj;
        fci.retval_ptr_ptr = &retval_ptr;
        fci.no_separation = 1;

        zend_fcall_info_cache fcc = empty_fcall_info_cache;
        fcc.initialized = 1;
        fcc.function_handler = ce->constructor;
        fcc.calling_scope = ce;
        fcc.called_scope = Z_OBJCE_P(obj);
        fcc.object_ptr = obj;

        // TODO Use zend_call_known_function on PHP 8
        int status = zend_call_function(&fci, &fcc TSRMLS_CC);
        if (retval_ptr) {
            zval_ptr_dtor(&retval_ptr);
        }
        REQUIRE(status == SUCCESS);
    }

    return obj;
}

/************************** zai_class_lookup_literal() ************************/

TEST_CASE("class lookup: (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_literal("spldoublylinkedlist");

    REQUIRE(ce == spl_ce_SplDoublyLinkedList);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");

    REQUIRE(ce != NULL);
    REQUIRE(strcmp("Zai\\Methods\\Test", ce->name) == 0);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: root-scope prefix", "[zai_methods]" SKIP_TEST_IN_DEBUG_MODE) {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_literal("\\spldoublylinkedlist");

    REQUIRE(ce == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: wrong case", "[zai_methods]" SKIP_TEST_IN_DEBUG_MODE) {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_literal("SplDoublyLinkedList");

    REQUIRE(ce == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: disable_classes INI", "[zai_methods]") {
    REQUIRE(zai_sapi_sinit());
    REQUIRE(zai_sapi_append_system_ini_entry("disable_classes", "SplDoublyLinkedList"));
    REQUIRE((zai_sapi_minit() && zai_sapi_rinit()));
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_literal("spldoublylinkedlist");
    REQUIRE(ce == spl_ce_SplDoublyLinkedList);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    zval *obj = zai_instantiate_object_from_ce(spl_ce_SplDoublyLinkedList);
    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "SplDoublyLinkedList() has been disabled for security reasons"));
    zval_ptr_dtor(&obj);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: outside of request context", "[zai_methods]") {
    REQUIRE((zai_sapi_sinit() && zai_sapi_minit()));
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_literal("spldoublylinkedlist");
    REQUIRE(ce == spl_ce_SplDoublyLinkedList);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}

TEST_CASE("class lookup: NULL class name", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_ex(NULL, 42 ZAI_TSRMLS_CC);

    REQUIRE(ce == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("class lookup: 0 class len", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_ex("spldoublylinkedlist", 0 ZAI_TSRMLS_CC);

    REQUIRE(ce == NULL);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/************************* zai_call_method_literal() **************************/

TEST_CASE("call method: (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *obj = zai_instantiate_object_from_ce(spl_ce_SplDoublyLinkedList);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // SplDoublyLinkedList::count()
    bool result = zai_call_method_literal(obj, "count", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: int args (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_literal("datetime");
    REQUIRE((ce != NULL && "ext/date required"));
    zval *obj = zai_instantiate_object_from_ce(ce);

    zval retzv = zval_used_for_init, *retval = &retzv;
    zval year = zval_used_for_init;
    zval month = zval_used_for_init;
    zval day = zval_used_for_init;
    ZVAL_LONG(&year, 2021);
    ZVAL_LONG(&month, 11);
    ZVAL_LONG(&day, 29);

    // DateTime::setDate()
    bool result = zai_call_method_literal(obj, "setdate", RETPTR, &year, &month, &day);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_OBJECT);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\Test::returnsTrue()
    bool result = zai_call_method_literal(obj, "returnstrue", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_BOOL);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: int args (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    zval arg = zval_used_for_init;
    ZVAL_LONG(&arg, 42);

    // Zai\Methods\Test::returnsArg()
    bool result = zai_call_method_literal(obj, "returnsarg", RETPTR, &arg);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);
    REQUIRE(Z_LVAL_P(retval) == 42);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: string args (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    zval arg = zval_used_for_init;
    ZVAL_STRING(&arg, "foo string");

    // Zai\Methods\Test::returnsArg()
    bool result = zai_call_method_literal(obj, "returnsarg", RETPTR, &arg);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_STRING);
    REQUIRE(strcmp("foo string", Z_STRVAL_P(retval)) == 0);

    zval_dtor(&arg);
    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: more than MAX_ARGS", "[zai_methods]" SKIP_TEST_IN_DEBUG_MODE) {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    zval arg = zval_used_for_init;
    ZVAL_STRING(&arg, "foo string");

    // Zai\Methods\Test::returnsTrue()
    bool result = zai_call_method_literal(obj, "returnstrue", RETPTR, &arg, &arg, &arg, &arg);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);

    zval_dtor(&arg);
    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: does not exist on object (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *obj = zai_instantiate_object_from_ce(spl_ce_SplDoublyLinkedList);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // SplDoublyLinkedList::iDoNotExist()
    bool result = zai_call_method_literal(obj, "idonotexist", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: does not exist on object (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\Test::iDoNotExist()
    bool result = zai_call_method_literal(obj, "idonotexist", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: accesses $this (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\Test::usesThis()
    bool result = zai_call_method_literal(obj, "usesthis", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_BOOL);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: throws exception (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/ExceptionTest.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\exceptiontest");
    REQUIRE(ce != NULL);

    /* Add a fake base/main frame to prevent the uncaught exception from
     * bubbling all the way up and raising a fatal error (zend_bailout).
     */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\ExceptionTest::throwsException()
    bool result = zai_call_method_literal(obj, "throwsexception", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    zai_sapi_fake_frame_pop(&fake_frame);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: throws exception with active frame (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/ExceptionTest.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\exceptiontest");
    REQUIRE(ce != NULL);

    /* Simulate an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\ExceptionTest::throwsException()
    bool result = zai_call_method_literal(obj, "throwsexception", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

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
    ZVAL_STRING(nonobj, "spldoublylinkedlist");

    zval retzv = zval_used_for_init, *retval = &retzv;
    // SplDoublyLinkedList::count()
    bool result = zai_call_method_literal(nonobj, "count", RETPTR);
    zval_ptr_dtor(&nonobj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: NULL object", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retzv = zval_used_for_init, *retval = &retzv;
    // {NULL}::count()
    bool result = zai_call_method_literal(NULL, "count", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: NULL method name", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *obj = zai_instantiate_object_from_ce(spl_ce_SplDoublyLinkedList);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // SplDoublyLinkedList::{NULL}()
    bool result = zai_call_method_ex(obj, NULL, 42, RETPTR ZAI_TSRMLS_CC, 0);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: 0 len method name", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval *obj = zai_instantiate_object_from_ce(spl_ce_SplDoublyLinkedList);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // SplDoublyLinkedList::()
    bool result = zai_call_method_ex(obj, "count", 0, RETPTR ZAI_TSRMLS_CC, 0);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: static method (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_literal("datetime");
    REQUIRE((ce != NULL && "ext/date required"));

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // DateTime::getLastErrors()
    bool result = zai_call_method_literal(obj, "getlasterrors", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE((Z_TYPE_P(retval) == IS_BOOL || Z_TYPE_P(retval) == IS_ARRAY));

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call method: static method (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\Test::returns42()
    bool result = zai_call_method_literal(obj, "returns42", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);
    REQUIRE(Z_LVAL_P(retval) == 42);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

/********************** zai_call_static_method_literal() **********************/

TEST_CASE("call static method: (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_literal("datetime");
    REQUIRE((ce != NULL && "ext/date required"));

    zval retzv = zval_used_for_init, *retval = &retzv;
    // DateTime::getLastErrors()
    bool result = zai_call_static_method_literal(ce, "getlasterrors", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE((Z_TYPE_P(retval) == IS_BOOL || Z_TYPE_P(retval) == IS_ARRAY));

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\Test::returns42()
    bool result = zai_call_static_method_literal(ce, "returns42", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_LONG);
    REQUIRE(Z_LVAL_P(retval) == 42);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: call method on retval (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval *retval_self = NULL;
    // Zai\Methods\Test::newSelf()
    bool result = zai_call_static_method_literal(ce, "newself", &retval_self);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(retval_self != NULL);
    REQUIRE(Z_TYPE_P(retval_self) == IS_OBJECT);
    REQUIRE(Z_OBJCE_P(retval_self) == ce);

    zval *retval_true = NULL;
    // Zai\Methods\Test::usesThis()
    bool result2 = zai_call_method_literal(retval_self, "usesthis", &retval_true);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result2 == true);
    REQUIRE(retval_true != NULL);
    REQUIRE(Z_TYPE_P(retval_true) == IS_BOOL);

    zval_ptr_dtor(&retval_true);
    zval_ptr_dtor(&retval_self);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: outside of request context", "[zai_methods]") {
    REQUIRE((zai_sapi_sinit() && zai_sapi_minit()));
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_literal("datetime");
    REQUIRE((ce != NULL && "ext/date required"));

    zval retzv = zval_used_for_init, *retval = &retzv;
    // DateTime::getLastErrors()
    bool result = zai_call_static_method_literal(ce, "getlasterrors", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}

TEST_CASE("call static method: does not exist on class (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zend_class_entry *ce = zai_class_lookup_literal("datetime");
    REQUIRE((ce != NULL && "ext/date required"));

    zval retzv = zval_used_for_init, *retval = &retzv;
    // DateTime::iDoNotExist()
    bool result = zai_call_static_method_literal(ce, "idonotexist", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: does not exist on class (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\Test::iDoNotExist()
    bool result = zai_call_static_method_literal(ce, "idonotexist", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: throws exception (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/ExceptionTest.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\exceptiontest");
    REQUIRE(ce != NULL);

    /* Add a fake base/main frame to prevent the uncaught exception from
     * bubbling all the way up and raising a fatal error (zend_bailout).
     */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\ExceptionTest::throwsExceptionFromStatic()
    bool result = zai_call_method_literal(obj, "throwsexceptionfromstatic", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    zai_sapi_fake_frame_pop(&fake_frame);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: throws exception with active frame (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/ExceptionTest.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\exceptiontest");
    REQUIRE(ce != NULL);

    /* Simulate an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zval *obj = zai_instantiate_object_from_ce(ce);
    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\ExceptionTest::throwsExceptionFromStatic()
    bool result = zai_call_method_literal(obj, "throwsexceptionfromstatic", RETPTR);
    zval_ptr_dtor(&obj);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    zai_sapi_fake_frame_pop(&fake_frame);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: NULL ce", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retzv = zval_used_for_init, *retval = &retzv;
    bool result = zai_call_static_method_literal(NULL, "count", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: non-static method (internal)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zval retzv = zval_used_for_init, *retval = &retzv;
    /* This call will fail because SplDoublyLinkedList::count() is not marked
     * with ZEND_ACC_ALLOW_STATIC.
     *
     * https://github.com/php/php-src/blob/PHP-5.4/ext/spl/spl_dllist.c#L1324
     */
    bool result = zai_call_static_method_literal(spl_ce_SplDoublyLinkedList, "count", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: non-static method (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\test");
    REQUIRE(ce != NULL);

    zval retzv = zval_used_for_init, *retval = &retzv;
    /* Calling the non-static method Zai\Methods\Test::returnsTrue() statically
     * is allowed here because the compiler marks non-static userland methods
     * with ZEND_ACC_ALLOW_STATIC.
     *
     * https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_compile.c#L1679
     */
    bool result = zai_call_static_method_literal(ce, "returnstrue", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == true);
    REQUIRE(Z_TYPE_P(retval) == IS_BOOL);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}

TEST_CASE("call static method: non-static method that accesses $this (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    zend_class_entry *ce;
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    REQUIRE(zai_sapi_execute_script("./stubs/Test.php"));
    ce = zai_class_lookup_literal("zai\\methods\\test");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    REQUIRE(ce != NULL);

    /* Since this call causes a fatal error, we expect the zend_bailout to
     * bubble up after closing the sandbox.
     */
    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
    zval retzv = zval_used_for_init, *retval = &retzv;
    /* Although the compiler marks this non-static userland method
     * Zai\Methods\Test::usesThis() with ZEND_ACC_ALLOW_STATIC, the call will
     * fail when '$this' is accessed from a static context.
     *
     * https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_execute.c#L460-L506
     */
    (void)zai_call_static_method_literal(ce, "usesthis", RETPTR);
    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sapi_spindown();
}

TEST_CASE("call static method: abstract method (userland)", "[zai_methods]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE(zai_sapi_execute_script("./stubs/AbstractTest.php"));
    zend_class_entry *ce = zai_class_lookup_literal("zai\\methods\\abstracttest");
    REQUIRE(ce != NULL);

    zval retzv = zval_used_for_init, *retval = &retzv;
    // Zai\Methods\AbstractTest::abstractMethod()
    bool result = zai_call_static_method_literal(ce, "abstractmethod", RETPTR);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    REQUIRE(result == false);
    REQUIRE(Z_TYPE_P(retval) == IS_UNDEF);

    zval_ptr_dtor(RETPTR);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_spindown();
}
