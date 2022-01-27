#ifndef HAVE_HOOK_TESTS_USER_HPP
#define HAVE_HOOK_TESTS_USER_HPP

#include <tea/testing/catch2.hpp>

extern "C" {
#include <hook/hook.h>
#include <value/value.h>
#include <tea/extension.h>

    static zval* zai_hook_test_execute_ex_return(zend_execute_data *ex  TEA_TSRMLS_DC) {
#if PHP_VERSION_ID < 70000
        return (EG(return_value_ptr_ptr) && *EG(return_value_ptr_ptr)) ? *EG(return_value_ptr_ptr) : &zval_used_for_init;
#else
        return ex->return_value;
#endif
    }

    static void (*zend_execute_ex_function)(zend_execute_data *ex TEA_TSRMLS_DC);

    static void zai_hook_test_execute_ex(zend_execute_data *ex TEA_TSRMLS_DC) {
        void *reserved = NULL;

        zai_hook_resolve(TEA_TSRMLS_C);

        if (!zai_hook_continue(ex, &reserved TEA_TSRMLS_CC)) {
            zend_bailout();
        }

        zend_execute_ex_function(ex TEA_TSRMLS_CC);

        zai_hook_finish(ex,
            zai_hook_test_execute_ex_return(ex TEA_TSRMLS_CC),
            &reserved TEA_TSRMLS_CC);
    }

    static inline void zai_hook_test_reset(bool rv) {
        zai_hook_test_begin_return  = rv;
        zai_hook_test_begin_check   = 0;
        zai_hook_test_end_check     = 0;
    }

    static PHP_MINIT_FUNCTION(ddtrace_testing_hook) {
        zai_hook_minit();

        zend_execute_ex_function = zend_execute_ex;
        if (!zend_execute_ex_function) {
            zend_execute_ex_function = execute_ex;
        }
        zend_execute_ex =
            zai_hook_test_execute_ex;

        return SUCCESS;
    }

    static PHP_RINIT_FUNCTION(ddtrace_testing_hook) {
        zai_hook_rinit();
        return SUCCESS;
    }

    static PHP_RSHUTDOWN_FUNCTION(ddtrace_testing_hook) {
        zai_hook_rshutdown();
        return SUCCESS;
    }

    static PHP_MSHUTDOWN_FUNCTION(ddtrace_testing_hook) {
        zai_hook_mshutdown();
        return SUCCESS;
    }

    static PHP_FUNCTION(ddtrace_testing_hook_begin_return) {
        RETURN_BOOL(zai_hook_test_begin_return);
    }

    static PHP_FUNCTION(ddtrace_testing_hook_begin_check) {
        zai_hook_test_begin_check++;
    }

    static PHP_FUNCTION(ddtrace_testing_hook_end_check) {
        zai_hook_test_end_check++;
    }

    ZEND_BEGIN_ARG_INFO_EX(ddtrace_testing_hook_arginfo, 0, 0, 0)
    ZEND_END_ARG_INFO()
}
#endif
