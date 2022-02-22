#include <include/error.h>
#include <include/extension.h>

/* Triggers an arbitrary error from userland.
 *
 * tea\trigger_error(string message, int error_level): void
 */
ZEND_BEGIN_ARG_INFO_EX(tea_trigger_error_arginfo, 0, 0, 2)
ZEND_ARG_INFO(0, message)
ZEND_ARG_INFO(0, level)
ZEND_END_ARG_INFO()

static PHP_FUNCTION(trigger_error) {
    TEA_EXTENSION_PARAMETERS_UNUSED();
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
static const zend_function_entry tea_error_functions[] = {
    ZEND_NS_FE("tea", trigger_error, tea_trigger_error_arginfo)
    PHP_FE_END
};
// clang-format on

void tea_error_sinit(void) { tea_extension_functions(tea_error_functions); }
