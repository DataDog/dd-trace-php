extern "C" {
#include "sandbox/sandbox.h"
#include "tea/frame.h"
#include "tea/error.h"
#include "tea/exceptions.h"
}

#include "zai_tests_common.hpp"

TEA_TEST_CASE("sandbox/exception", "state: throw exception", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Exceptions thrown with zend_throw_exception will generate a fatal error
     * if there is no active PHP frame. That is why we have to insert a fake
     * frame before throwing the exception.
     */
    zend_execute_data fake_frame;
    REQUIRE(tea_frame_push(&fake_frame));

    zai_exception_state es;
    zai_sandbox_exception_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zend_class_entry *ce;

    TEA_TEST_CODE_WITHOUT_BAILOUT({
        ce = tea_exception_throw("Foo exception");
    });

    REQUIRE(tea_exception_eq(ce, "Foo exception"));

    zai_sandbox_exception_state_restore(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    tea_frame_pop(&fake_frame);
})

TEA_TEST_CASE("sandbox/exception", "state: existing unhandled exception", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(tea_frame_push(&fake_frame));

    zend_class_entry *orig_exception_ce = tea_exception_throw("Original exception");
    REQUIRE(tea_exception_eq(orig_exception_ce, "Original exception"));

    zai_exception_state es;
    zai_sandbox_exception_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zend_class_entry *ce;

    TEA_TEST_CODE_WITHOUT_BAILOUT({
        ce = tea_exception_throw("Foo exception");
    })

    REQUIRE(tea_exception_eq(ce, "Foo exception"));

    zai_sandbox_exception_state_restore(&es);

    REQUIRE(tea_exception_eq(orig_exception_ce, "Original exception"));
    tea_exception_ignore();

    tea_frame_pop(&fake_frame);
})

/* TODO Test 'EG(prev_exception)' handling. The previous exception in the
 * executor globals is set via zend_exception_save and is used in the VM when
 * throwing exceptions and also for autoloading. This is different than
 * 'Exception::$previous' which contains the previously caught exception. In
 * order to test the former, the TEA SAPI needs support VM runtime hooks like
 * custom opcode handlers.
 */
//TEA_TEST_CASE_BARE("sandbox/exception", "prev_exception", {}

TEA_TEST_CASE("sandbox/exception", "state: throw exception (userland)", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_exception_state es;
    zai_sandbox_exception_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

#if PHP_VERSION_ID >= 80000
    /* Starting in PHP 8, uncaught exceptions have a clean shutdown with the
     * introduction of E_DONT_BAIL.
     *
     * https://github.com/php/php-src/blob/php-8.0.0/Zend/zend_exceptions.c#L976-L978
     */
    TEA_TEST_CODE_WITHOUT_BAILOUT({
        tea_execute_script("./stubs/throw_exception.php");
    });
#else
    TEA_TEST_CODE_WITH_BAILOUT({
        tea_execute_script("./stubs/throw_exception.php");
    });
#endif

    /* TODO The exception thrown in userland is handled and freed before the
     * zend_bailout so we cannot access the exception after the zend_bailout.
     * To access the userland exception, the TEA SAPI must support arbitrary
     * code execution during runtime (e.g. a custom opcode handler) that will
     * fire after the exception is thrown and before ZEND_HANDLE_EXCEPTION is
     * called.
     */
    //REQUIRE(tea_exception_eq(userland_ce, "My foo exception"));

    zai_sandbox_exception_state_restore(&es);

    REQUIRE(false == tea_exception_exists());
    /* An uncaught exception changes the error state.
     *
     * TODO Support scanf-style formatted errors.
     */
    //REQUIRE(tea_error_eq(E_ERROR, "Fatal error - Uncaught Exception: My foo exception in %s:%d"));
})

static int zai_throw_exception_hook_calls_count = 0;

#if PHP_VERSION_ID >= 80000
static void zai_throw_exception_hook(zend_object *exception) {
    zai_throw_exception_hook_calls_count++;
}
#else
static void zai_throw_exception_hook(zval *exception TSRMLS_DC) {
    zai_throw_exception_hook_calls_count++;
}
#endif

TEA_TEST_CASE_BARE("sandbox/exception", "zend_throw_exception_hook called once", {
    REQUIRE((tea_sapi_sinit() && tea_sapi_minit()));

    zai_throw_exception_hook_calls_count = 0;
    zend_throw_exception_hook = zai_throw_exception_hook;

    REQUIRE(tea_sapi_rinit());

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(tea_frame_push(&fake_frame));

    zend_class_entry *orig_exception_ce = tea_exception_throw("Original exception");
    REQUIRE(tea_exception_eq(orig_exception_ce, "Original exception"));
    REQUIRE(zai_throw_exception_hook_calls_count == 1);

    zai_exception_state es;
    zai_sandbox_exception_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zend_class_entry *ce;

    TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()
    ce = tea_exception_throw("Foo exception");
    TEA_TEST_CASE_WITHOUT_BAILOUT_END()

    REQUIRE(tea_exception_eq(ce, "Foo exception"));
    REQUIRE(zai_throw_exception_hook_calls_count == 2);

    zai_sandbox_exception_state_restore(&es);

    REQUIRE(tea_exception_eq(orig_exception_ce, "Original exception"));
    /* The sandbox should not invoke zend_throw_exception_hook a third time
     * when restoring the original exception.
     */
    REQUIRE(zai_throw_exception_hook_calls_count == 2);
    tea_exception_ignore();

    tea_frame_pop(&fake_frame);

    tea_sapi_spindown();
})

TEA_TEST_CASE("sandbox/exception", "throw exception", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(tea_frame_push(&fake_frame));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zend_class_entry *ce;

    TEA_TEST_CODE_WITHOUT_BAILOUT({
        ce = tea_exception_throw("Foo exception");
    });

    REQUIRE(tea_exception_eq(ce, "Foo exception"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    tea_frame_pop(&fake_frame);
})

TEA_TEST_CASE("sandbox/exception", "existing unhandled exception", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(tea_frame_push(&fake_frame));

    zend_class_entry *orig_exception_ce;
    TEA_TEST_CODE_WITHOUT_BAILOUT({
        orig_exception_ce = tea_exception_throw("Original exception");
    });
    REQUIRE(tea_exception_eq(orig_exception_ce, "Original exception"));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    {
        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        zend_class_entry *ce;

        TEA_TEST_CODE_WITHOUT_BAILOUT({
            ce = tea_exception_throw("Foo exception");
        });

        REQUIRE(tea_exception_eq(ce, "Foo exception"));
    }

    zai_sandbox_close(&sandbox);

    REQUIRE(tea_exception_eq(orig_exception_ce, "Original exception"));
    tea_exception_ignore();

    tea_frame_pop(&fake_frame);
})

TEA_TEST_CASE("sandbox/exception", "throw exception (userland)", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

#if PHP_VERSION_ID >= 80000
    /* Uncaught exceptions have a clean shutdown in PHP 8. */
    TEA_TEST_CODE_WITHOUT_BAILOUT({
        tea_execute_script("./stubs/throw_exception.php");
    });
#else
    TEA_TEST_CODE_WITH_BAILOUT({
        tea_execute_script("./stubs/throw_exception.php");
    });
#endif

    /* TODO See comment from "exception state: throw exception (userland)". */
    //REQUIRE(tea_exception_eq(userland_ce, "My foo exception"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
})
