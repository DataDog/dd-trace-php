#ifndef HAVE_ZAI_TESTS_COMMON_HPP
#define HAVE_ZAI_TESTS_COMMON_HPP

#include "tea/testing/catch2.hpp"
extern "C" {
#include <zai_string/string.h>
#include <Zend/zend_API.h>
#include "../sandbox.h"

    static inline bool zai_test_call_global_with_0_params(zai_str name, zval *rv) {
        zend_fcall_info fci;
        fci.size = sizeof(fci);
        fci.object = NULL;
        ZVAL_STRINGL(&fci.function_name, name.ptr, name.len);
        fci.retval = rv;
        fci.param_count = 0;
        fci.params = NULL;
#if PHP_VERSION_ID < 70100
        fci.symbol_table = NULL;
#endif
#if PHP_VERSION_ID >= 80000
        fci.named_params = NULL;
#else
        fci.no_separation = 1;
#endif

        zai_sandbox sandbox;
        zai_sandbox_open(&sandbox);
        int ret = zai_sandbox_call(&sandbox, &fci, NULL);
        zai_sandbox_close(&sandbox);

        zval_ptr_dtor(&fci.function_name);
        return ret;
    }
}

#define REQUIRE_ERROR_AND_EXCEPTION_CLEAN_SLATE()         \
    REQUIRE(false == tea_exception_exists()); \
    REQUIRE(tea_error_is_empty());
#endif
