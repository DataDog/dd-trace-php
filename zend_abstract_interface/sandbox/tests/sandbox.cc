extern "C" {
#include "sandbox/sandbox.h"
#include "zai_sapi/zai_sapi.h"
}

#include <Zend/zend_exceptions.h>
#include <catch2/catch.hpp>

#include "zai_tests_common.hpp"

ZAI_SAPI_TEST_CASE("sandbox", "sandbox: exception & error", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zend_class_entry *ce;

    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        ce = zai_sapi_throw_exception("Foo exception");
        zend_error(E_NOTICE, "Foo non-fatal error");
    });

    REQUIRE(zai_sapi_unhandled_exception_eq(ce, "Foo exception"));
    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Foo non-fatal error"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sapi_fake_frame_pop(&fake_frame);
})

ZAI_SAPI_TEST_CASE("sandbox", "sandbox: existing exception & existing error", {
    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zend_class_entry *orig_exception_ce;

    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_WARNING, "Original non-fatal error");
        orig_exception_ce = zai_sapi_throw_exception("Original exception");
    });

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
    REQUIRE(zai_sapi_unhandled_exception_eq(orig_exception_ce, "Original exception"));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    {
        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        zend_class_entry *ce;

        ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
            zend_error(E_NOTICE, "Foo non-fatal error");
            ce = zai_sapi_throw_exception("Foo exception");
        });

        REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Foo non-fatal error"));
        REQUIRE(zai_sapi_unhandled_exception_eq(ce, "Foo exception"));
    }

    zai_sandbox_close(&sandbox);

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
    REQUIRE(zai_sapi_unhandled_exception_eq(orig_exception_ce, "Original exception"));
    zai_sapi_unhandled_exception_ignore();

    zai_sapi_fake_frame_pop(&fake_frame);
})
