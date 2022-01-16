extern "C" {
    #include <include/extension.h>

    static int tea_extension_run_check;

    static zend_result_t tea_extension_init_success(INIT_FUNC_ARGS) {
        tea_extension_run_check++;

        return SUCCESS;
    }

    static zend_result_t tea_extension_init_failure(INIT_FUNC_ARGS) {
        tea_extension_run_check++;
        /* an extension returning FAILURE will not cause stage to fail */
        return FAILURE;
    }

    static zend_result_t tea_extension_shutdown_success(SHUTDOWN_FUNC_ARGS) {
        tea_extension_run_check++;

        return SUCCESS;
    }

    static zend_result_t tea_extension_shutdown_failure(SHUTDOWN_FUNC_ARGS) {
        tea_extension_run_check++;

        /* an extension returning FAILURE will not cause stage to fail */
        return FAILURE;
    }

    ZEND_BEGIN_ARG_INFO_EX(tea_extension_function_arginfo, 0, 0, 0)
    ZEND_END_ARG_INFO()

    static PHP_FUNCTION(tea_extension_function_one) {}
    static PHP_FUNCTION(tea_extension_function_two) {}
    static PHP_FUNCTION(tea_extension_function_three) {}
    static PHP_FUNCTION(tea_extension_function_four) {}

    const zend_function_entry tea_extension_function_entry_one[] = {
        PHP_FE(tea_extension_function_one, tea_extension_function_arginfo)
        PHP_FE(tea_extension_function_two, tea_extension_function_arginfo)
        PHP_FE_END
    };

    const zend_function_entry tea_extension_function_entry_two[] = {
        PHP_FE(tea_extension_function_three, tea_extension_function_arginfo)
        PHP_FE(tea_extension_function_four,  tea_extension_function_arginfo)
        PHP_FE_END
    };
}

#include <include/testing/catch2.hpp>

TEA_TEST_CASE_BARE("tea/extension", "name", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_name("rename", sizeof("rename")-1);
    }
    REQUIRE(tea_sapi_minit());
    REQUIRE(tea_sapi_rinit());

#if PHP_VERSION_ID < 70000
    CHECK(zend_hash_exists(&module_registry, "rename", sizeof("rename")));
#else
    CHECK(zend_hash_str_exists(&module_registry, "rename", sizeof("rename")-1));
#endif
})

TEA_TEST_CASE_BARE("tea/extension", "minit pass", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_minit(tea_extension_init_success);
    }
    CHECK(tea_sapi_minit());
    REQUIRE(tea_extension_run_check);
});

TEA_TEST_CASE_BARE("tea/extension", "minit fail", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_minit(tea_extension_init_failure);
    }
    CHECK(tea_sapi_minit());
    REQUIRE(tea_extension_run_check);
});

TEA_TEST_CASE_BARE("tea/extension", "minit multiple", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_minit(tea_extension_init_failure);
        tea_extension_minit(tea_extension_init_failure);
    }
    CHECK(tea_sapi_minit());
    REQUIRE(tea_extension_run_check == 2);
});

TEA_TEST_CASE_BARE("tea/extension", "rinit pass", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_rinit(tea_extension_init_success);
    }
    CHECK(tea_sapi_minit());
    CHECK(!tea_extension_run_check);
    CHECK(tea_sapi_rinit());
    REQUIRE(tea_extension_run_check);
});

TEA_TEST_CASE_BARE("tea/extension", "rinit fail", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_rinit(tea_extension_init_failure);
    }
    CHECK(tea_sapi_minit());
    CHECK(!tea_extension_run_check);
    CHECK(tea_sapi_rinit());
    REQUIRE(tea_extension_run_check);
});

TEA_TEST_CASE_BARE("tea/extension", "rinit multiple", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_rinit(tea_extension_init_failure);
        tea_extension_rinit(tea_extension_init_failure);
    }
    CHECK(tea_sapi_minit());
    CHECK(!tea_extension_run_check);
    CHECK(tea_sapi_rinit());
    REQUIRE(tea_extension_run_check == 2);
});

TEA_TEST_CASE_BARE("tea/extension", "rshutdown pass", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_rshutdown(tea_extension_shutdown_success);
    }
    CHECK(tea_sapi_minit());
    CHECK(!tea_extension_run_check);
    CHECK(tea_sapi_rinit());
    CHECK(!tea_extension_run_check);
    tea_sapi_rshutdown();
    REQUIRE(tea_extension_run_check);
});

TEA_TEST_CASE_BARE("tea/extension", "rshutdown fail", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_rshutdown(tea_extension_shutdown_failure);
    }
    CHECK(tea_sapi_minit());
    CHECK(!tea_extension_run_check);
    CHECK(tea_sapi_rinit());
    CHECK(!tea_extension_run_check);
    tea_sapi_rshutdown();
    REQUIRE(tea_extension_run_check);
});

TEA_TEST_CASE_BARE("tea/extension", "rshutdown multiple", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_rshutdown(tea_extension_shutdown_failure);
        tea_extension_rshutdown(tea_extension_shutdown_failure);
    }
    CHECK(tea_sapi_minit());
    CHECK(!tea_extension_run_check);
    CHECK(tea_sapi_rinit());
    CHECK(!tea_extension_run_check);
    tea_sapi_rshutdown();
    REQUIRE(tea_extension_run_check == 2);
});

TEA_TEST_CASE_BARE("tea/extension", "mshutdown pass", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_mshutdown(tea_extension_shutdown_success);
    }
    CHECK(tea_sapi_minit());
    CHECK(!tea_extension_run_check);
    CHECK(tea_sapi_rinit());
    CHECK(!tea_extension_run_check);
    tea_sapi_rshutdown();
    CHECK(!tea_extension_run_check);
    tea_sapi_mshutdown();
    CHECK(tea_extension_run_check);
});

TEA_TEST_CASE_BARE("tea/extension", "mshutdown fail", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_mshutdown(tea_extension_shutdown_failure);
    }
    CHECK(tea_sapi_minit());
    CHECK(!tea_extension_run_check);
    CHECK(tea_sapi_rinit());
    CHECK(!tea_extension_run_check);
    tea_sapi_rshutdown();
    CHECK(!tea_extension_run_check);
    tea_sapi_mshutdown();
    CHECK(tea_extension_run_check);
});

TEA_TEST_CASE_BARE("tea/extension", "mshutdown multiple", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_run_check = 0;

        tea_extension_mshutdown(tea_extension_shutdown_failure);
        tea_extension_mshutdown(tea_extension_shutdown_failure);
    }
    CHECK(tea_sapi_minit());
    CHECK(!tea_extension_run_check);
    CHECK(tea_sapi_rinit());
    CHECK(!tea_extension_run_check);
    tea_sapi_rshutdown();
    CHECK(!tea_extension_run_check);
    tea_sapi_mshutdown();
    CHECK(tea_extension_run_check == 2);
});

TEA_TEST_CASE_BARE("tea/extension", "functions", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_functions(tea_extension_function_entry_one);
    }
    REQUIRE(tea_sapi_minit());
    REQUIRE(tea_sapi_rinit());
    TEA_TSRMLS_FETCH();

#if PHP_VERSION_ID < 70000
    CHECK(zend_hash_exists(EG(function_table), "tea_extension_function_one", sizeof("tea_extension_function_one")));
    CHECK(zend_hash_exists(EG(function_table), "tea_extension_function_two", sizeof("tea_extension_function_two")));
#else
    CHECK(zend_hash_str_exists(EG(function_table), "tea_extension_function_one", sizeof("tea_extension_function_one")-1));
    CHECK(zend_hash_str_exists(EG(function_table), "tea_extension_function_two", sizeof("tea_extension_function_two")-1));
#endif
});

TEA_TEST_CASE_BARE("tea/extension", "functions multiple", {
    REQUIRE(tea_sapi_sinit());
    {
        /* PROLOGUE */
        tea_extension_functions(tea_extension_function_entry_one);
        tea_extension_functions(tea_extension_function_entry_two);
    }
    REQUIRE(tea_sapi_minit());
    REQUIRE(tea_sapi_rinit());
    TEA_TSRMLS_FETCH();

#if PHP_VERSION_ID < 70000
    CHECK(zend_hash_exists(EG(function_table), "tea_extension_function_one", sizeof("tea_extension_function_one")));
    CHECK(zend_hash_exists(EG(function_table), "tea_extension_function_two", sizeof("tea_extension_function_two")));
    CHECK(zend_hash_exists(EG(function_table), "tea_extension_function_three", sizeof("tea_extension_function_three")));
    CHECK(zend_hash_exists(EG(function_table), "tea_extension_function_four",  sizeof("tea_extension_function_four")));
#else
    CHECK(zend_hash_str_exists(EG(function_table), "tea_extension_function_one", sizeof("tea_extension_function_one")-1));
    CHECK(zend_hash_str_exists(EG(function_table), "tea_extension_function_two", sizeof("tea_extension_function_two")-1));
    CHECK(zend_hash_str_exists(EG(function_table), "tea_extension_function_three", sizeof("tea_extension_function_three")-1));
    CHECK(zend_hash_str_exists(EG(function_table), "tea_extension_function_four", sizeof("tea_extension_function_four")-1));
#endif
});
