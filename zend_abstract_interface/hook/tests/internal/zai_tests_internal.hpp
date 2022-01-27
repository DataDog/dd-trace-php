#ifndef HAVE_HOOK_TESTS_INTERNAL_HPP
#define HAVE_HOOK_TESTS_INTERNAL_HPP

#include <tea/testing/catch2.hpp>

extern "C" {
#include <hook/hook.h>
#include <value/value.h>

#if PHP_VERSION_ID < 70000
    static void (*zend_execute_internal_function)(zend_execute_data *ex, zend_fcall_info *fci, int return_value_used TEA_TSRMLS_DC);

    static void zai_hook_test_execute_internal(zend_execute_data *ex, zend_fcall_info *fci, int return_value_used TEA_TSRMLS_DC) {
        void *reserved = NULL;

        if (!zai_hook_continue(ex, &reserved TEA_TSRMLS_CC)) {
            zend_bailout();
        }

        zend_execute_internal_function(ex, fci, return_value_used TEA_TSRMLS_CC);

        zai_hook_finish(ex, *fci->retval_ptr_ptr, &reserved TEA_TSRMLS_CC);
    }
#else
    static void (*zend_execute_internal_function)(zend_execute_data *ex, zval *rv);

    static void zai_hook_test_execute_internal(zend_execute_data *ex, zval *rv) {
        void *reserved = NULL;

        if (!zai_hook_continue(ex, &reserved)) {
            zend_bailout();
        }

        zend_execute_internal_function(ex, rv);

        zai_hook_finish(ex, rv, &reserved);
    }
#endif

    static inline void zai_hook_test_reset(bool rv) {
        zai_hook_test_begin_return  = rv;
        zai_hook_test_begin_check   = 0;
        zai_hook_test_end_check     = 0;
        zai_hook_test_begin_dynamic = NULL;
        zai_hook_test_end_dynamic   = NULL;
        zai_hook_test_begin_fixed   = NULL;
        zai_hook_test_end_fixed     = NULL;
    }
}
#endif
