extern "C" {
#include "sandbox/sandbox.h"
#include "tea/frame.h"
#include "tea/error.h"
#include "tea/exceptions.h"
}

#include "zai_tests_common.hpp"

TEA_TEST_CASE("sandbox", "sandbox: exception & error", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(tea_frame_push(&fake_frame));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zend_class_entry *ce;

    TEA_TEST_CODE_WITHOUT_BAILOUT({
        ce = tea_exception_throw("Foo exception");
        zend_error(E_NOTICE, "Foo non-fatal error");
    });

    REQUIRE(tea_exception_eq(ce, "Foo exception"));
    REQUIRE(tea_error_eq(E_NOTICE, "Foo non-fatal error"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    tea_frame_pop(&fake_frame);
})

TEA_TEST_CASE("sandbox", "sandbox: existing exception & existing error", {
    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(tea_frame_push(&fake_frame));

    zend_class_entry *orig_exception_ce;

    TEA_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_WARNING, "Original non-fatal error");
        orig_exception_ce = tea_exception_throw("Original exception");
    });

    REQUIRE(tea_error_eq(E_WARNING, "Original non-fatal error"));
    REQUIRE(tea_exception_eq(orig_exception_ce, "Original exception"));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    {
        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        zend_class_entry *ce;

        TEA_TEST_CODE_WITHOUT_BAILOUT({
            zend_error(E_NOTICE, "Foo non-fatal error");
            ce = tea_exception_throw("Foo exception");
        });

        REQUIRE(tea_error_eq(E_NOTICE, "Foo non-fatal error"));
        REQUIRE(tea_exception_eq(ce, "Foo exception"));
    }

    zai_sandbox_close(&sandbox);

    REQUIRE(tea_error_eq(E_WARNING, "Original non-fatal error"));
    REQUIRE(tea_exception_eq(orig_exception_ce, "Original exception"));
    tea_exception_ignore();

    tea_frame_pop(&fake_frame);
})

TEA_TEST_CASE_WITH_PROLOGUE("sandbox/bailout", "no timeout", {
    tea_sapi_module.php_ini_ignore = 1;
    tea_sapi_append_system_ini_entry("max_execution_time", "0");
}, {
    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    REQUIRE(!zai_sandbox_timed_out());

    TEA_TEST_CODE_WITHOUT_BAILOUT({
        zai_sandbox_bailout(&sandbox);
    });

    zai_sandbox_close(&sandbox);
})

TEA_TEST_CASE_WITH_PROLOGUE("sandbox/bailout", "timeout", {
    tea_sapi_module.php_ini_ignore = 1;
    tea_sapi_append_system_ini_entry("max_execution_time", "1");
}, {
    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    TEA_TEST_CODE_WITH_BAILOUT({
        TEA_EVAL_STR("while (1);");
    });

    REQUIRE(zai_sandbox_timed_out());

    TEA_TEST_CODE_WITH_BAILOUT({
        zai_sandbox_bailout(&sandbox);
    });

    zai_sandbox_close(&sandbox);
});
