extern "C" {
#include "call/call.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"

#include "zai_compat.h"
}

#include <catch2/catch.hpp>
#include <cstdlib>
#include <cstring>

// clang-format off
static inline bool zai_call_test_class(zend_class_entry **ce ZAI_TSRMLS_DC) {
    zai_sapi_execute_script("./stubs/Stub.php");

    zai_string_view scope =
        ZAI_STRL_VIEW("ZendAbstractInterfaceCallStub");

    *ce = zai_call_lookup_class(
        ZAI_CALL_SCOPE_NAMED, &scope ZAI_TSRMLS_CC);

    return *ce != NULL;
}
// clang-format on

static inline bool zai_call_test_stub(zval **object ZAI_TSRMLS_DC) {
    zend_class_entry *ce;

    if (!zai_call_test_class(&ce ZAI_TSRMLS_CC)) {
        return false;
    }

#if PHP_VERSION_ID < 70000
    MAKE_STD_ZVAL(*object);
#endif

    return zai_call_new(*object, ce ZAI_TSRMLS_CC, 0);
}

#define TEST(name, code) TEST_CASE(name, "[zai_call]") { \
        REQUIRE(zai_sapi_sinit()); \
        REQUIRE(zai_sapi_minit()); \
        REQUIRE(zai_sapi_rinit()); \
        ZAI_SAPI_TSRMLS_FETCH(); \
        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() \
        { code } \
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE() \
        zai_sapi_spindown(); \
    }

TEST("lookup class [internal class exists with no case]", {
    zai_string_view name = ZAI_STRL_VIEW("stdclass");

    REQUIRE(zai_call_lookup_class(ZAI_CALL_SCOPE_NAMED, &name ZAI_TSRMLS_CC));
})

TEST("lookup class [internal class exists with mixed case]", {
    zai_string_view name = ZAI_STRL_VIEW("stdClass");

    REQUIRE(zai_call_lookup_class(ZAI_CALL_SCOPE_NAMED, &name ZAI_TSRMLS_CC));
})

TEST("lookup class [class does not exist with no case]", {
    zai_string_view name = ZAI_STRL_VIEW("nosuchclass");

    REQUIRE(!zai_call_lookup_class(ZAI_CALL_SCOPE_NAMED, &name ZAI_TSRMLS_CC));
})

TEST("lookup class [class does not exist with mixed case]", {
    zai_string_view name = ZAI_STRL_VIEW("noSuchClass");

    REQUIRE(!zai_call_lookup_class(ZAI_CALL_SCOPE_NAMED, &name ZAI_TSRMLS_CC));
})

TEST("lookup function [global with no case]", {
    zai_string_view name = ZAI_STRL_VIEW("strlen");

    REQUIRE(zai_call_lookup_function(ZAI_CALL_SCOPE_GLOBAL, NULL, ZAI_CALL_FUNCTION_NAMED, &name ZAI_TSRMLS_CC));
})

TEST("lookup function [global with mixed case]", {
    zai_string_view name = ZAI_STRL_VIEW("strLen");

    REQUIRE(zai_call_lookup_function(ZAI_CALL_SCOPE_GLOBAL, NULL, ZAI_CALL_FUNCTION_NAMED, &name ZAI_TSRMLS_CC));
})

TEST("lookup function [named scope]", {
    zai_string_view scope = ZAI_STRL_VIEW("DateTime");
    zai_string_view construct = ZAI_STRL_VIEW("__construct");

    REQUIRE(zai_call_lookup_function(ZAI_CALL_SCOPE_NAMED, &scope, ZAI_CALL_FUNCTION_NAMED, &construct ZAI_TSRMLS_CC));
})

TEST("lookup function [static scope]", {
    zai_string_view scope = ZAI_STRL_VIEW("DateTime");

    zend_class_entry *ce = zai_call_lookup_class(ZAI_CALL_SCOPE_NAMED, &scope ZAI_TSRMLS_CC);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zai_string_view construct = ZAI_STRL_VIEW("__construct");

    REQUIRE(zai_call_lookup_function(ZAI_CALL_SCOPE_STATIC, ce, ZAI_CALL_FUNCTION_NAMED, &construct ZAI_TSRMLS_CC));
})

TEST("call [constructor]", {
    zai_string_view scope = ZAI_STRL_VIEW("SplFixedArray");

    zend_class_entry *ce = zai_call_lookup_class(ZAI_CALL_SCOPE_NAMED, &scope ZAI_TSRMLS_CC);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval *object;
#if PHP_VERSION_ID < 70000
    MAKE_STD_ZVAL(object);
#else
    zval ov;

    object = &ov;

    ZVAL_UNDEF(object);
#endif

    zval *length;
#if PHP_VERSION_ID < 70000
    MAKE_STD_ZVAL(length);
#else
    zval lv;

    length = &lv;
#endif

    ZVAL_LONG(length, 42);

    REQUIRE(zai_call_new(object, ce ZAI_TSRMLS_CC, 1, &length));
    REQUIRE(Z_TYPE_P(object) == IS_OBJECT);

#if PHP_VERSION_ID < 70000
    zval_ptr_dtor(&object);
    zval_ptr_dtor(&length);
#else
    zval_ptr_dtor(object);
    zval_ptr_dtor(length);
#endif
})

TEST("call [internal, with return]", {
    zai_string_view scope = ZAI_STRL_VIEW("SplFixedArray");

    zend_class_entry *ce = zai_call_lookup_class(ZAI_CALL_SCOPE_NAMED, &scope ZAI_TSRMLS_CC);

    REQUIRE(ce);

    if (!ce) {
        return;
    }

    zval *object;
#if PHP_VERSION_ID < 70000
    MAKE_STD_ZVAL(object);
#else
    zval ov;

    object = &ov;

    ZVAL_UNDEF(object);
#endif

    zval *length;
#if PHP_VERSION_ID < 70000
    MAKE_STD_ZVAL(length);
#else
    zval lv;

    length = &lv;
#endif

    ZVAL_LONG(length, 42);

    REQUIRE(zai_call_new(object, ce ZAI_TSRMLS_CC, 1, &length));
    REQUIRE(Z_TYPE_P(object) == IS_OBJECT);

    zval *result;
#if PHP_VERSION_ID >= 70000
    zval rz;

    result = &rz;

    ZVAL_UNDEF(result);
#endif

    zai_string_view method = ZAI_STRL_VIEW("getSize");

    REQUIRE(zai_call(ZAI_CALL_SCOPE_OBJECT, object, ZAI_CALL_FUNCTION_NAMED, &method, &result ZAI_TSRMLS_CC, 0));
    REQUIRE(Z_TYPE_P(result) == IS_LONG);
    REQUIRE(Z_LVAL_P(result) == 42);

#if PHP_VERSION_ID < 70000
    zval_ptr_dtor(&object);
    zval_ptr_dtor(&length);
    zval_ptr_dtor(&result);
#else
    zval_ptr_dtor(object);
    zval_ptr_dtor(length);
    zval_ptr_dtor(result);
#endif
})

TEST("call [user, returnLong]", {
    zval *object;
#if PHP_VERSION_ID >= 70000
    zval ov;

    object = &ov;

    ZVAL_UNDEF(object);
#endif

    REQUIRE(zai_call_test_stub(&object ZAI_TSRMLS_CC));

    zval *result;
#if PHP_VERSION_ID >= 70000
    zval rv;

    result = &rv;
#endif

    zai_string_view method = ZAI_STRL_VIEW("returnLong");

    REQUIRE(zai_call(ZAI_CALL_SCOPE_OBJECT, object, ZAI_CALL_FUNCTION_NAMED, &method, &result ZAI_TSRMLS_CC, 0));
    REQUIRE(Z_TYPE_P(result) == IS_LONG);
    REQUIRE(Z_LVAL_P(result) == 42);

#if PHP_VERSION_ID < 70000
    zval_ptr_dtor(&object);
    zval_ptr_dtor(&result);
#else
    zval_ptr_dtor(object);
    zval_ptr_dtor(result);
#endif
})

TEST("methods [user, returnObject]", {
    zval *object;
#if PHP_VERSION_ID >= 70000
    zval ov;

    object = &ov;

    ZVAL_UNDEF(object);
#endif

    REQUIRE(zai_call_test_stub(&object ZAI_TSRMLS_CC));

    zval *result;
#if PHP_VERSION_ID >= 70000
    zval rv;

    result = &rv;
#endif

    zai_string_view method = ZAI_STRL_VIEW("returnObject");

    REQUIRE(zai_call(ZAI_CALL_SCOPE_OBJECT, object, ZAI_CALL_FUNCTION_NAMED, &method, &result ZAI_TSRMLS_CC, 0));
    REQUIRE(Z_TYPE_P(result) == IS_OBJECT);

#if PHP_VERSION_ID < 70000
    zval_ptr_dtor(&object);
    zval_ptr_dtor(&result);
#else
    zval_ptr_dtor(object);
    zval_ptr_dtor(result);
#endif
})

TEST("call [user, acceptLong]", {
    zval *object;
#if PHP_VERSION_ID >= 70000
    zval ov;

    object = &ov;

    ZVAL_UNDEF(object);
#endif

    REQUIRE(zai_call_test_stub(&object ZAI_TSRMLS_CC));

    zval *param;
#if PHP_VERSION_ID < 70000
    MAKE_STD_ZVAL(param);
#else
    zval pz;

    param = &pz;
#endif

    ZVAL_LONG(param, 42);

    zval *result;
#if PHP_VERSION_ID >= 70000
    zval rv;

    result = &rv;
#endif

    zai_string_view method = ZAI_STRL_VIEW("acceptLong");

    REQUIRE(zai_call(ZAI_CALL_SCOPE_OBJECT, object, ZAI_CALL_FUNCTION_NAMED, &method, &result ZAI_TSRMLS_CC, 1, &param));
    REQUIRE(Z_TYPE_P(result) == IS_LONG);
    REQUIRE(Z_LVAL_P(result) == 42);

#if PHP_VERSION_ID < 70000
    zval_ptr_dtor(&object);
    zval_ptr_dtor(&param);
    zval_ptr_dtor(&result);
#else
    zval_ptr_dtor(object);
    zval_ptr_dtor(param);
    zval_ptr_dtor(result);
#endif
})

TEST("call [user, acceptObject]", {
    zval *object;
#if PHP_VERSION_ID >= 70000
    zval ov;

    object = &ov;

    ZVAL_UNDEF(object);
#endif

    REQUIRE(zai_call_test_stub(&object ZAI_TSRMLS_CC));

    zval *result;
#if PHP_VERSION_ID >= 70000
    zval rv;

    result = &rv;
#endif

    zai_string_view method = ZAI_STRL_VIEW("acceptObject");

    REQUIRE(zai_call(ZAI_CALL_SCOPE_OBJECT, object, ZAI_CALL_FUNCTION_NAMED, &method, &result ZAI_TSRMLS_CC, 1, &object));
    REQUIRE(Z_TYPE_P(result) == IS_OBJECT);

#if PHP_VERSION_ID < 70000
    zval_ptr_dtor(&object);
    zval_ptr_dtor(&result);
#else
    zval_ptr_dtor(object);
    zval_ptr_dtor(result);
#endif
})

TEST("call [user, throwException]", {
    zval *object;
#if PHP_VERSION_ID >= 70000
    zval ov;

    object = &ov;

    ZVAL_UNDEF(object);
#endif

    REQUIRE(zai_call_test_stub(&object ZAI_TSRMLS_CC));

    zval *result;
#if PHP_VERSION_ID >= 70000
    zval rv;

    result = &rv;
#endif

    zai_string_view method = ZAI_STRL_VIEW("throwException");

    REQUIRE(!zai_call(ZAI_CALL_SCOPE_OBJECT, object, ZAI_CALL_FUNCTION_NAMED, &method, &result ZAI_TSRMLS_CC, 0));
    REQUIRE(!EG(exception));

#if PHP_VERSION_ID < 70000
    zval_ptr_dtor(&object);
#else
    zval_ptr_dtor(object);
#endif
})

TEST("call [user, declaredStatic]", {
    zend_class_entry *ce;

    REQUIRE(zai_call_test_class(&ce ZAI_TSRMLS_CC));

    zval *result;
#if PHP_VERSION_ID >= 70000
    zval rv;

    result = &rv;
#endif

    zai_string_view method = ZAI_STRL_VIEW("declaredStatic");

    REQUIRE(zai_call(ZAI_CALL_SCOPE_STATIC, ce, ZAI_CALL_FUNCTION_NAMED, &method, &result ZAI_TSRMLS_CC, 0));

#if PHP_VERSION_ID < 70000
    zval_ptr_dtor(&result);
#else
    zval_ptr_dtor(result);
#endif
})

TEST("call [user, notDeclaredStatic]", {
    zend_class_entry *ce;

    REQUIRE(zai_call_test_class(&ce ZAI_TSRMLS_CC));

    zval *result;
#if PHP_VERSION_ID >= 70000
    zval rv;

    result = &rv;
#endif

    zai_string_view method = ZAI_STRL_VIEW("notDeclaredStatic");

    REQUIRE(!zai_call(ZAI_CALL_SCOPE_STATIC, ce, ZAI_CALL_FUNCTION_NAMED, &method, &result ZAI_TSRMLS_CC, 0));
})
