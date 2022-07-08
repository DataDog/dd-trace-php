extern "C" {
#include "sandbox/sandbox.h"
#include "tea/error.h"
#include "tea/exceptions.h"
}

#include "zai_tests_common.hpp"

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

TEA_TEST_CASE("sandbox/error", "fatal errors", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        TEA_TEST_CODE_WITH_BAILOUT({
            zend_error(error_type, "Foo fatal error");
        });

        REQUIRE(tea_error_eq(error_type, "Foo fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }
})

TEA_TEST_CASE("sandbox/error", "fatal errors restore to existing error", {
    TEA_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_WARNING, "Original non-fatal error");
    });

    REQUIRE(tea_error_eq(E_WARNING, "Original non-fatal error"));

    for (int error_type : fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        TEA_TEST_CODE_WITH_BAILOUT({
            zend_error(error_type, "Foo fatal error");
        });

        REQUIRE(tea_error_eq(error_type, "Foo fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(tea_error_eq(E_WARNING, "Original non-fatal error"));
    }
})

TEA_TEST_CASE("sandbox/error", "non-fatal errors", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : non_fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        TEA_TEST_CODE_WITHOUT_BAILOUT({
            zend_error(error_type, "Foo non-fatal error");
        });

        REQUIRE(tea_error_eq(error_type, "Foo non-fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }
})

TEA_TEST_CASE("sandbox/error", "non-fatal errors restore to existing error", {
    TEA_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_NOTICE, "Original non-fatal error");
    });

    REQUIRE(tea_error_eq(E_NOTICE, "Original non-fatal error"));

    for (int error_type : non_fatal_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        TEA_TEST_CODE_WITHOUT_BAILOUT({
            zend_error(error_type, "Foo non-fatal error");
        });

        REQUIRE(tea_error_eq(error_type, "Foo non-fatal error"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(tea_error_eq(E_NOTICE, "Original non-fatal error"));
    }
})

TEA_TEST_CASE("sandbox/error", "fatal-error (userland)", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    zai_error_state es;
    zai_sandbox_error_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    TEA_TEST_CODE_WITH_BAILOUT({
        tea_execute_script("./stubs/trigger_error_E_ERROR.php");
    });

    REQUIRE(tea_error_eq(E_ERROR, "My E_ERROR"));

    zai_sandbox_error_state_restore(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
})

TEA_TEST_CASE("sandbox/error", "non-fatal error (userland)", {
    zai_error_state es;
    zai_sandbox_error_state_backup(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    TEA_TEST_CODE_WITHOUT_BAILOUT({
        REQUIRE(tea_execute_script("./stubs/trigger_error_E_NOTICE.php"));
    });

    REQUIRE(tea_error_eq(E_NOTICE, "My E_NOTICE"));

    zai_sandbox_error_state_restore(&es);

    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
})

/* Although these are non-fatal errors, on PHP 7.0+ we have to set the error
 * handler to EH_THROW since EH_SUPPRESS was removed from core in PHP 7.3. This
 * means zend_bailout is expected for these non-fatal errors on PHP 7+ because
 * they are converted into exceptions.
 */
TEA_TEST_CASE("sandbox/error", "throwable non-fatal errors (PHP 7+)", {
    REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

    for (int error_type : non_fatal_throwable_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        TEA_TEST_CODE_WITH_BAILOUT({
            zend_error(error_type, "Foo throwable non-fatal error");
        });

        REQUIRE(tea_error_eq(E_ERROR, "Uncaught Exception: Foo throwable non-fatal error in [no active file]:0\nStack trace:\n#0 {main}\n  thrown"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();
    }
})

TEA_TEST_CASE("sandbox/error", "throwable non-fatal errors restore to existing error (PHP 7+)", {
    TEA_TEST_CODE_WITHOUT_BAILOUT({
        zend_error(E_WARNING, "Original non-fatal error");
    });

    REQUIRE(tea_error_eq(E_WARNING, "Original non-fatal error"));

    for (int error_type : non_fatal_throwable_errors) {
        zai_error_state es;
        zai_sandbox_error_state_backup(&es);

        REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE();

        TEA_TEST_CODE_WITH_BAILOUT({
            zend_error(error_type, "Foo throwable non-fatal error");
        });

        REQUIRE(tea_error_eq(E_ERROR, "Uncaught Exception: Foo throwable non-fatal error in [no active file]:0\nStack trace:\n#0 {main}\n  thrown"));

        zai_sandbox_error_state_restore(&es);

        REQUIRE(tea_error_eq(E_WARNING, "Original non-fatal error"));
    }
})
