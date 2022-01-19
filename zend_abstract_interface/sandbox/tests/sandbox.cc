extern "C" {
#include "sandbox/sandbox.h"
#include "tea/sapi.h"
#include "tea/frame.h"
#include "tea/error.h"
#include "tea/exceptions.h"
}

#include <catch2/catch.hpp>

#include "zai_tests_common.hpp"

TEA_TEST_CASE("sandbox", "sandbox: exception & error", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(tea_frame_push(&fake_frame TEA_TSRMLS_CC));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zend_class_entry *ce;

    TEA_TEST_CODE_WITHOUT_BAILOUT({
        ce = tea_exception_throw("Foo exception" TEA_TSRMLS_CC);
        zend_error(E_NOTICE, "Foo non-fatal error");
    });

    REQUIRE(tea_exception_eq(ce, "Foo exception" TEA_TSRMLS_CC));
    REQUIRE(tea_error_eq(E_NOTICE, "Foo non-fatal error" TEA_TSRMLS_CC));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    tea_frame_pop(&fake_frame TEA_TSRMLS_CC);
})

TEA_TEST_CASE("sandbox", "sandbox: existing exception & existing error", {
    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(tea_frame_push(&fake_frame TEA_TSRMLS_CC));

    zend_class_entry *orig_exception_ce;

    TEA_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_WARNING, "Original non-fatal error");
        orig_exception_ce = tea_exception_throw("Original exception" TEA_TSRMLS_CC);
    });

    REQUIRE(tea_error_eq(E_WARNING, "Original non-fatal error" TEA_TSRMLS_CC));
    REQUIRE(tea_exception_eq(orig_exception_ce, "Original exception" TEA_TSRMLS_CC));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    {
        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        zend_class_entry *ce;

        TEA_TEST_CODE_WITHOUT_BAILOUT({
            zend_error(E_NOTICE, "Foo non-fatal error");
            ce = tea_exception_throw("Foo exception" TEA_TSRMLS_CC);
        });

        REQUIRE(tea_error_eq(E_NOTICE, "Foo non-fatal error" TEA_TSRMLS_CC));
        REQUIRE(tea_exception_eq(ce, "Foo exception" TEA_TSRMLS_CC));
    }

    zai_sandbox_close(&sandbox);

    REQUIRE(tea_error_eq(E_WARNING, "Original non-fatal error" TEA_TSRMLS_CC));
    REQUIRE(tea_exception_eq(orig_exception_ce, "Original exception" TEA_TSRMLS_CC));
    tea_exception_ignore(TEA_TSRMLS_C);

    tea_frame_pop(&fake_frame TEA_TSRMLS_CC);
})
