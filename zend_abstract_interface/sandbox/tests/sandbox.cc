extern "C" {
#include "sandbox/sandbox.h"
#include "zai_sapi/zai_sapi.h"
}

#include <Zend/zend_exceptions.h>
#include <catch2/catch.hpp>

#define REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE()            \
    REQUIRE(false == zai_sapi_unhandled_exception_exists()); \
    REQUIRE(zai_sapi_last_error_is_empty());

static int fatal_errors[] = {
    E_COMPILE_ERROR,
    E_CORE_ERROR,
    E_ERROR,
#if PHP_VERSION_ID >= 80000
    /* Starting in PHP 8, E_PARSE and E_RECOVERABLE_ERROR were considered true
     * fatal errors.
     */
    E_PARSE,
    E_RECOVERABLE_ERROR,
#endif
    E_USER_ERROR,
};

static int non_fatal_errors[] = {
    E_DEPRECATED,
    E_NOTICE,
    E_STRICT,
    E_USER_DEPRECATED,
    E_USER_NOTICE,
};

/* On PHP 7.0+ we set the error handler to EH_THROW but not all errors can
 * convert to exceptions. These are the non-fatal errors that will throw.
 */
static int non_fatal_throwable_errors[] = {
    E_COMPILE_WARNING,
    E_CORE_WARNING,
#if PHP_VERSION_ID <= 70000
    /* Prior to PHP 8, E_RECOVERABLE_ERROR and E_PARSE were not treated as true
     * fatal errors.
     */
    E_PARSE,
    E_RECOVERABLE_ERROR,
#endif
    E_USER_WARNING,
    E_WARNING,
};

/**************** zai_sandbox_error_state_{backup|restore}() *****************/

TEST_CASE("error state: fatal errors", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
        zend_error(error_type, "Foo fatal error");
        ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }

    zai_sapi_spindown();
}

TEST_CASE("error state: fatal errors restore to existing error", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zend_error(E_WARNING, "Original non-fatal error");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));

    for (int error_type : fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
        zend_error(error_type, "Foo fatal error");
        ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
    }

    zai_sapi_spindown();
}

TEST_CASE("error state: non-fatal errors", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : non_fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
        zend_error(error_type, "Foo non-fatal error");
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo non-fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }

    zai_sapi_spindown();
}

TEST_CASE("error state: non-fatal errors restore to existing error", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zend_error(E_NOTICE, "Original non-fatal error");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Original non-fatal error"));

    for (int error_type : non_fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
        zend_error(error_type, "Foo non-fatal error");
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo non-fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Original non-fatal error"));
    }

    zai_sapi_spindown();
}

TEST_CASE("error state: fatal-error (userland)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_error_state es;
    zai_sandbox_error_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
    zai_sapi_execute_script("./stubs/trigger_error_E_ERROR.php");
    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_ERROR, "My E_ERROR"));

    zai_sandbox_error_state_restore(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sapi_spindown();
}

TEST_CASE("error state: non-fatal error (userland)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    zai_error_state es;
    zai_sandbox_error_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    REQUIRE(zai_sapi_execute_script("./stubs/trigger_error_E_NOTICE.php"));
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "My E_NOTICE"));

    zai_sandbox_error_state_restore(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sapi_spindown();
}

#if PHP_VERSION_ID >= 70000
/* Although these are non-fatal errors, on PHP 7.0+ we have to set the error
 * handler to EH_THROW since EH_SUPPRESS was removed from core in PHP 7.3. This
 * means zend_bailout is expected for these non-fatal errors on PHP 7+ because
 * they are converted into exceptions.
 */
TEST_CASE("error state: throwable non-fatal errors (PHP 7+)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : non_fatal_throwable_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
        zend_error(error_type, "Foo throwable non-fatal error");
        ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

        REQUIRE(zai_sapi_last_error_eq(E_ERROR, "Uncaught Exception: Foo throwable non-fatal error in [no active file]:0\nStack trace:\n#0 {main}\n  thrown"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }

    zai_sapi_spindown();
}

TEST_CASE("error state: throwable non-fatal errors restore to existing error (PHP 7+)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zend_error(E_WARNING, "Original non-fatal error");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));

    for (int error_type : non_fatal_throwable_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
        zend_error(error_type, "Foo throwable non-fatal error");
        ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

        REQUIRE(zai_sapi_last_error_eq(E_ERROR, "Uncaught Exception: Foo throwable non-fatal error in [no active file]:0\nStack trace:\n#0 {main}\n  thrown"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
    }

    zai_sapi_spindown();
}
#else
/* In PHP 5 we set the error handler to EH_SUPPRESS so throwable non-fatal
 * errors are just treated as normal non-fatal errors.
 */
TEST_CASE("error state: throwable non-fatal errors (PHP 5)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : non_fatal_throwable_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
        zend_error(error_type, "Foo throwable non-fatal error");
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo throwable non-fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }

    zai_sapi_spindown();
}

TEST_CASE("error state: throwable non-fatal errors restore to existing error (PHP 5)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zend_error(E_WARNING, "Original non-fatal error");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));

    for (int error_type : non_fatal_throwable_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
        zend_error(error_type, "Foo throwable non-fatal error");
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo throwable non-fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
    }

    zai_sapi_spindown();
}
#endif

/************** zai_sandbox_exception_state_{backup|restore}() ***************/

TEST_CASE("exception state: throw exception", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Exceptions thrown with zend_throw_exception will generate a fatal error
     * if there is no active PHP frame. That is why we have to insert a fake
     * frame before throwing the exception.
     */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zai_exception_state es;
    zai_sandbox_exception_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zend_class_entry *ce;

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    ce = zai_sapi_throw_exception("Foo exception");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_unhandled_exception_eq(ce, "Foo exception"));

    zai_sandbox_exception_state_restore(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sapi_fake_frame_pop(&fake_frame);

    zai_sapi_spindown();
}

TEST_CASE("exception state: existing unhandled exception", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zend_class_entry *orig_exception_ce = zai_sapi_throw_exception("Original exception");
    REQUIRE(zai_sapi_unhandled_exception_eq(orig_exception_ce, "Original exception"));

    zai_exception_state es;
    zai_sandbox_exception_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zend_class_entry *ce;

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    ce = zai_sapi_throw_exception("Foo exception");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_unhandled_exception_eq(ce, "Foo exception"));

    zai_sandbox_exception_state_restore(&es);

    REQUIRE(zai_sapi_unhandled_exception_eq(orig_exception_ce, "Original exception"));

    zai_sapi_fake_frame_pop(&fake_frame);

    zai_sapi_spindown();
}

/* TODO Test 'EG(prev_exception)' handling. The previous exception in the
 * executor globals is set via zend_exception_save and is used in the VM when
 * throwing exceptions and also for autoloading. This is different than
 * 'Exception::$previous' which contains the previously caught exception. In
 * order to test the former, the ZAI SAPI needs support VM runtime hooks like
 * custom opcode handlers.
 */
//TEST_CASE("exception state: prev_exception", "[zai_sandbox]") {}

TEST_CASE("exception state: throw exception (userland)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

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
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_sapi_execute_script("./stubs/throw_exception.php");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
#else
    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
    zai_sapi_execute_script("./stubs/throw_exception.php");
    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()
#endif

    /* TODO The exception thrown in userland is handled and freed before the
     * zend_bailout so we cannot access the exception after the zend_bailout.
     * To access the userland exception, the ZAI SAPI must support arbitrary
     * code execution during runtime (e.g. a custom opcode handler) that will
     * fire after the exception is thrown and before ZEND_HANDLE_EXCEPTION is
     * called.
     */
    //REQUIRE(zai_sapi_unhandled_exception_eq(userland_ce, "My foo exception"));

    zai_sandbox_exception_state_restore(&es);

    REQUIRE(false == zai_sapi_unhandled_exception_exists());
    /* An uncaught exception changes the error state.
     *
     * TODO Support scanf-style formatted errors.
     */
    //REQUIRE(zai_sapi_last_error_eq(E_ERROR, "Fatal error - Uncaught Exception: My foo exception in %s:%d"));

    zai_sapi_spindown();
}

static int zai_throw_exception_hook_calls_count = 0;

#if PHP_VERSION_ID >= 80000
static void zai_throw_exception_hook(zend_object *exception) {
    zai_throw_exception_hook_calls_count++;
}
#else
static void zai_throw_exception_hook(zval *exception) {
    zai_throw_exception_hook_calls_count++;
}
#endif

TEST_CASE("exception state: zend_throw_exception_hook called once", "[zai_sandbox]") {
    REQUIRE((zai_sapi_sinit() && zai_sapi_minit()));

    zai_throw_exception_hook_calls_count = 0;
    zend_throw_exception_hook = zai_throw_exception_hook;

    REQUIRE(zai_sapi_rinit());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zend_class_entry *orig_exception_ce = zai_sapi_throw_exception("Original exception");
    REQUIRE(zai_sapi_unhandled_exception_eq(orig_exception_ce, "Original exception"));
    REQUIRE(zai_throw_exception_hook_calls_count == 1);

    zai_exception_state es;
    zai_sandbox_exception_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zend_class_entry *ce;

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    ce = zai_sapi_throw_exception("Foo exception");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_unhandled_exception_eq(ce, "Foo exception"));
    REQUIRE(zai_throw_exception_hook_calls_count == 2);

    zai_sandbox_exception_state_restore(&es);

    REQUIRE(zai_sapi_unhandled_exception_eq(orig_exception_ce, "Original exception"));
    /* The sandbox should not invoke zend_throw_exception_hook a third time
     * when restoring the original exception.
     */
    REQUIRE(zai_throw_exception_hook_calls_count == 2);

    zai_sapi_fake_frame_pop(&fake_frame);

    zai_sapi_spindown();
}

/************************ zai_sandbox_{open|close}() *************************/

TEST_CASE("sandbox error: fatal error (userland)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
    zai_sapi_execute_script("./stubs/trigger_error_E_ERROR.php");
    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_ERROR, "My E_ERROR"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sapi_spindown();
}

TEST_CASE("sandbox error: fatal error with existing error (userland)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zend_error(E_NOTICE, "Original non-fatal error");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Original non-fatal error"));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
    zai_sapi_execute_script("./stubs/trigger_error_E_ERROR.php");
    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_ERROR, "My E_ERROR"));

    zai_sandbox_close(&sandbox);

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Original non-fatal error"));

    zai_sapi_spindown();
}

TEST_CASE("sandbox error: non-fatal error (userland)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_sapi_execute_script("./stubs/trigger_error_E_NOTICE.php");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "My E_NOTICE"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sapi_spindown();
}

TEST_CASE("sandbox error: non-fatal error with existing error (userland)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zend_error(E_WARNING, "Original non-fatal error");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_sapi_execute_script("./stubs/trigger_error_E_NOTICE.php");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "My E_NOTICE"));

    zai_sandbox_close(&sandbox);

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));

    zai_sapi_spindown();
}

TEST_CASE("sandbox exception: throw exception", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zend_class_entry *ce;

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    ce = zai_sapi_throw_exception("Foo exception");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_unhandled_exception_eq(ce, "Foo exception"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sapi_fake_frame_pop(&fake_frame);

    zai_sapi_spindown();
}

TEST_CASE("sandbox exception: existing unhandled exception", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zend_class_entry *orig_exception_ce;
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    orig_exception_ce = zai_sapi_throw_exception("Original exception");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    REQUIRE(zai_sapi_unhandled_exception_eq(orig_exception_ce, "Original exception"));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zend_class_entry *ce;

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    ce = zai_sapi_throw_exception("Foo exception");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_unhandled_exception_eq(ce, "Foo exception"));

    zai_sandbox_close(&sandbox);

    REQUIRE(zai_sapi_unhandled_exception_eq(orig_exception_ce, "Original exception"));

    zai_sapi_fake_frame_pop(&fake_frame);

    zai_sapi_spindown();
}

TEST_CASE("sandbox exception: throw exception (userland)", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

#if PHP_VERSION_ID >= 80000
    /* Uncaught exceptions have a clean shutdown in PHP 8. */
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zai_sapi_execute_script("./stubs/throw_exception.php");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
#else
    ZAI_SAPI_BAILOUT_EXPECTED_OPEN()
    zai_sapi_execute_script("./stubs/throw_exception.php");
    ZAI_SAPI_BAILOUT_EXPECTED_CLOSE()
#endif

    /* TODO See comment from "exception state: throw exception (userland)". */
    //REQUIRE(zai_sapi_unhandled_exception_eq(userland_ce, "My foo exception"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sapi_spindown();
}

TEST_CASE("sandbox: exception & error", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    zend_class_entry *ce;

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    ce = zai_sapi_throw_exception("Foo exception");
    zend_error(E_NOTICE, "Foo non-fatal error");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_unhandled_exception_eq(ce, "Foo exception"));
    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Foo non-fatal error"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_sapi_fake_frame_pop(&fake_frame);

    zai_sapi_spindown();
}

TEST_CASE("sandbox: existing exception & existing error", "[zai_sandbox]") {
    REQUIRE(zai_sapi_spinup());
    ZAI_SAPI_TSRMLS_FETCH();

    /* Throwing exceptions require an active execution context. */
    zend_execute_data fake_frame;
    REQUIRE(zai_sapi_fake_frame_push(&fake_frame));

    zend_class_entry *orig_exception_ce;

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zend_error(E_WARNING, "Original non-fatal error");
    orig_exception_ce = zai_sapi_throw_exception("Original exception");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
    REQUIRE(zai_sapi_unhandled_exception_eq(orig_exception_ce, "Original exception"));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zend_class_entry *ce;

    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()
    zend_error(E_NOTICE, "Foo non-fatal error");
    ce = zai_sapi_throw_exception("Foo exception");
    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Foo non-fatal error"));
    REQUIRE(zai_sapi_unhandled_exception_eq(ce, "Foo exception"));

    zai_sandbox_close(&sandbox);

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
    REQUIRE(zai_sapi_unhandled_exception_eq(orig_exception_ce, "Original exception"));

    zai_sapi_fake_frame_pop(&fake_frame);

    zai_sapi_spindown();
}
