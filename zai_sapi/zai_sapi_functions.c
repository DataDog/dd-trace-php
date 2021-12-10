#include "zai_sapi_functions.h"

#if PHP_VERSION_ID < 70000
#define UNUSED_PHP_FN_VARS()   \
    (void)(ht);                \
    (void)(return_value_ptr);  \
    (void)(return_value_used); \
    (void)(this_ptr)
#else
#define UNUSED_PHP_FN_VARS()
#endif

ZEND_BEGIN_ARG_INFO_EX(arginfo_void, 0, 0, 0)
ZEND_END_ARG_INFO()

/* NOOP function that can be used to make fake execute frames.
 *
 * Zai\noop(void): null
 */
static PHP_FUNCTION(noop) {
    UNUSED_PHP_FN_VARS();
#if PHP_VERSION_ID < 70000
#ifdef ZTS
    (void)(TSRMLS_C);
#endif
#else
    (void)(execute_data);
#endif
    /* NOOP */
    RETURN_NULL();
}

/* Triggers an arbitrary error from userland.
 *
 * Zai\trigger_error(string message, int error_level): void
 */
ZEND_BEGIN_ARG_INFO_EX(arginfo_trigger_error, 0, 0, 2)
ZEND_ARG_INFO(0, message)
ZEND_ARG_INFO(0, level)
ZEND_END_ARG_INFO()

static PHP_FUNCTION(trigger_error) {
    UNUSED_PHP_FN_VARS();
#if PHP_VERSION_ID < 70000
    char *msg;
    int msg_len = 0;
    long error_type = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "sl", &msg, &msg_len, &error_type) != SUCCESS) RETURN_NULL();
#else
    zend_string *message;
    zend_long error_type = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "Sl", &message, &error_type) != SUCCESS) RETURN_NULL();

    const char *msg = ZSTR_VAL(message);
#endif

    int level = (int)error_type;
    switch (level) {
        case E_ERROR:
        case E_WARNING:
        case E_PARSE:
        case E_NOTICE:
        case E_CORE_ERROR:
        case E_CORE_WARNING:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_USER_WARNING:
        case E_USER_NOTICE:
        case E_STRICT:
        case E_RECOVERABLE_ERROR:
        case E_DEPRECATED:
        case E_USER_DEPRECATED:
            zend_error(level, "%s", msg);
            break;

        default:
            RETURN_NULL();
            break;
    }
}

// clang-format off
const zend_function_entry zai_sapi_functions[] = {
    ZEND_NS_FE("Zai", noop, arginfo_void)
    ZEND_NS_FE("Zai", trigger_error, arginfo_trigger_error)
    PHP_FE_END
};
// clang-format on
