extern "C" {
#include "sandbox/sandbox.h"
#include "zai_sapi/zai_sapi.h"
}

#include "zai_tests_common.hpp"
#include <Zend/zend_exceptions.h>

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

ZAI_SAPI_TEST_CASE("sandbox/error", "fatal errors", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_TEST_CODE_WITH_BAILOUT({
            zend_error(error_type, "Foo fatal error");
        });

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }
})

ZAI_SAPI_TEST_CASE("sandbox/error", "fatal errors restore to existing error", {
    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_WARNING, "Original non-fatal error");
    });

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));

    for (int error_type : fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_TEST_CODE_WITH_BAILOUT({
            zend_error(error_type, "Foo fatal error");
        });

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
    }
})

ZAI_SAPI_TEST_CASE("sandbox/error", "non-fatal errors", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : non_fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
            zend_error(error_type, "Foo non-fatal error");
        });

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo non-fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }
})

ZAI_SAPI_TEST_CASE("sandbox/error", "non-fatal errors restore to existing error", {
    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_NOTICE, "Original non-fatal error");
    });

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Original non-fatal error"));

    for (int error_type : non_fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
            zend_error(error_type, "Foo non-fatal error");
        });

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo non-fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Original non-fatal error"));
    }
})

ZAI_SAPI_TEST_CASE("sandbox/error", "fatal-error (userland)", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_error_state es;
    zai_sandbox_error_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    ZAI_SAPI_TEST_CODE_WITH_BAILOUT({
        zai_sapi_execute_script("./stubs/trigger_error_E_ERROR.php");
    });

    REQUIRE(zai_sapi_last_error_eq(E_ERROR, "My E_ERROR"));

    zai_sandbox_error_state_restore(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
})

ZAI_SAPI_TEST_CASE("sandbox/error", "non-fatal error (userland)", {
    zai_error_state es;
    zai_sandbox_error_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        REQUIRE(zai_sapi_execute_script("./stubs/trigger_error_E_NOTICE.php"));
    });

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "My E_NOTICE"));

    zai_sandbox_error_state_restore(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
})

#if PHP_VERSION_ID >= 70000
/* Although these are non-fatal errors, on PHP 7.0+ we have to set the error
 * handler to EH_THROW since EH_SUPPRESS was removed from core in PHP 7.3. This
 * means zend_bailout is expected for these non-fatal errors on PHP 7+ because
 * they are converted into exceptions.
 */
ZAI_SAPI_TEST_CASE("sandbox/error", "throwable non-fatal errors (PHP 7+)", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : non_fatal_throwable_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_TEST_CODE_WITH_BAILOUT({
            zend_error(error_type, "Foo throwable non-fatal error");
        });

        REQUIRE(zai_sapi_last_error_eq(E_ERROR, "Uncaught Exception: Foo throwable non-fatal error in [no active file]:0\nStack trace:\n#0 {main}\n  thrown"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }
})

ZAI_SAPI_TEST_CASE("sandbox/error", "throwable non-fatal errors restore to existing error (PHP 7+)", {
    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_WARNING, "Original non-fatal error");
    });

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));

    for (int error_type : non_fatal_throwable_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_TEST_CODE_WITH_BAILOUT({
            zend_error(error_type, "Foo throwable non-fatal error");
        });

        REQUIRE(zai_sapi_last_error_eq(E_ERROR, "Uncaught Exception: Foo throwable non-fatal error in [no active file]:0\nStack trace:\n#0 {main}\n  thrown"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
    }
})
#else
/* In PHP 5 we set the error handler to EH_SUPPRESS so throwable non-fatal
 * errors are just treated as normal non-fatal errors.
 */
ZAI_SAPI_TEST_CASE("sandbox/error", "throwable non-fatal errors (PHP 5)", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : non_fatal_throwable_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
            zend_error(error_type, "Foo throwable non-fatal error");
        });

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo throwable non-fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }
})

ZAI_SAPI_TEST_CASE("sandbox/error", "throwable non-fatal errors restore to existing error (PHP 5)", {
    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_WARNING, "Original non-fatal error");
    });

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));

    for (int error_type : non_fatal_throwable_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
            zend_error(error_type, "Foo throwable non-fatal error");
        });

        REQUIRE(zai_sapi_last_error_eq(error_type, "Foo throwable non-fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
    }
})

ZAI_SAPI_TEST_CASE("sandbox/error", "fatal error (userland)", {
    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    ZAI_SAPI_TEST_CODE_WITH_BAILOUT({
        zai_sapi_execute_script("./stubs/trigger_error_E_ERROR.php");
    });

    REQUIRE(zai_sapi_last_error_eq(E_ERROR, "My E_ERROR"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
})

ZAI_SAPI_TEST_CASE("sandbox/error", "fatal error with existing error (userland)", {
    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_NOTICE, "Original non-fatal error");
    });

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Original non-fatal error"));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    ZAI_SAPI_TEST_CODE_WITH_BAILOUT({
        zai_sapi_execute_script("./stubs/trigger_error_E_ERROR.php");
    });

    REQUIRE(zai_sapi_last_error_eq(E_ERROR, "My E_ERROR"));

    zai_sandbox_close(&sandbox);

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "Original non-fatal error"));
})

ZAI_SAPI_TEST_CASE("sandbox/error", "non-fatal error (userland)", {
    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        zai_sapi_execute_script("./stubs/trigger_error_E_NOTICE.php");
    });

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "My E_NOTICE"));

    zai_sandbox_close(&sandbox);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
})

ZAI_SAPI_TEST_CASE("sandbox/error", "non-fatal error with existing error (userland)", {
    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_WARNING, "Original non-fatal error");
    });

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));

    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        zai_sapi_execute_script("./stubs/trigger_error_E_NOTICE.php");
    });

    REQUIRE(zai_sapi_last_error_eq(E_NOTICE, "My E_NOTICE"));

    zai_sandbox_close(&sandbox);

    REQUIRE(zai_sapi_last_error_eq(E_WARNING, "Original non-fatal error"));
})

#endif
