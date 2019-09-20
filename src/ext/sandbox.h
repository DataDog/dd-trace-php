#ifndef DDTRACE_SANDBOX_H
#define DDTRACE_SANDBOX_H
#include <Zend/zend_exceptions.h>
#include <php.h>

#if PHP_VERSION_ID < 70000
#define DD_TRACE_SANDBOX_OPEN                                                  \
    zend_error_handling error_handling;                                        \
    int orig_error_reporting = EG(error_reporting);                            \
    EG(error_reporting) = 0;                                                   \
    zend_replace_error_handling(EH_SUPPRESS, NULL, &error_handling TSRMLS_CC); \
    {
#define DD_TRACE_SANDBOX_CLOSE                              \
    }                                                       \
    zend_restore_error_handling(&error_handling TSRMLS_CC); \
    EG(error_reporting) = orig_error_reporting;

// Cannot use zend_clear_exception() in PHP 5 since there is no NULL check on the opline
#define DD_TRACE_MAYBE_CLEAR_EXCEPTION                                          \
    if (EG(exception)) {                                                        \
        if (!DDTRACE_G(strict_mode)) {                                          \
            zval_ptr_dtor(&EG(exception));                                      \
            EG(exception) = NULL;                                               \
            if (EG(prev_exception)) {                                           \
                zval_ptr_dtor(&EG(prev_exception));                             \
                EG(prev_exception) = NULL;                                      \
            }                                                                   \
            if (EG(current_execute_data)) {                                     \
                EG(current_execute_data)->opline = EG(opline_before_exception); \
            }                                                                   \
        }                                                                       \
    }
#else
#define DD_TRACE_SANDBOX_OPEN                       \
    int orig_error_reporting = EG(error_reporting); \
    EG(error_reporting) = 0;                        \
    {
#define DD_TRACE_SANDBOX_CLOSE \
    }                          \
    EG(error_reporting) = orig_error_reporting;

#define DD_TRACE_MAYBE_CLEAR_EXCEPTION \
    if (EG(exception)) {               \
        if (!DDTRACE_G(strict_mode)) { \
            zend_clear_exception();    \
        }                              \
    }
#endif

#endif  // DDTRACE_SANDBOX_H
