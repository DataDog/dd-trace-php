#ifndef HAVE_HOOK_TESTS_INTERNAL_HPP
#define HAVE_HOOK_TESTS_INTERNAL_HPP

#include <tea/testing/catch2.hpp>

extern "C" {
#include <hook/hook.h>
#include <value/value.h>

    static void (*zend_execute_internal_function)(zend_execute_data *ex, zval *rv);

    static void zai_hook_test_execute_internal(zend_execute_data *ex, zval *rv) {
        zai_hook_memory_t memory;

        if (!zai_hook_continue(ex, &memory)) {
            zend_bailout();
        }

        zend_execute_internal_function(ex, rv);

        zai_hook_finish(ex, rv, &memory);
    }

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

#define zai_hook_install(scope, function, begin, end, aux, dynamic) zai_hook_install(scope, function, (zai_hook_begin)begin, (zai_hook_end)end, aux, dynamic)
#endif
