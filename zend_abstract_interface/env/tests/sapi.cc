extern "C" {
#include "env/env.h"
#include "tea/sapi.h"
#include "tea/extension.h"
}

#include "zai_tests_common.hpp"

#if PHP_VERSION_ID >= 80000
#define TEA_SAPI_GETENV_FUNCTION(fn) static char *fn(const char *name, size_t name_len)
#elif PHP_VERSION_ID >= 70000
#define TEA_SAPI_GETENV_FUNCTION(fn) static char *fn(char *name, size_t name_len)
#else
#define TEA_SAPI_GETENV_FUNCTION(fn) static char *fn(char *name, size_t name_len TSRMLS_DC)
#endif

static char zai_str_buf[64];

// Returns the name of the env var as the value
TEA_SAPI_GETENV_FUNCTION(tea_sapi_getenv_non_empty) {
    strcpy(zai_str_buf, name);
    return zai_str_buf;
}

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "non-empty string", {
    tea_sapi_module.getenv = tea_sapi_getenv_non_empty;
},{
    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("FOO", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "non-empty string (no host env fallback)", {
    tea_sapi_module.getenv = tea_sapi_getenv_non_empty;
},{
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("FOO", buf);
})

TEA_SAPI_GETENV_FUNCTION(tea_sapi_getenv_null) { return NULL; }

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "not set", {
    tea_sapi_module.getenv = tea_sapi_getenv_null;
},{
    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_NOT_SET);
    REQUIRE_BUF_EQ("", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "not set (with host env fallback)", {
    tea_sapi_module.getenv = tea_sapi_getenv_null;
},{
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("bar", buf);
})

/****************************** Access from RINIT *****************************/

zai_env_result zai_rinit_last_res;
static char zai_rinit_str_buf[64];

static PHP_RINIT_FUNCTION(zai_env) {
#if PHP_VERSION_ID >= 80000
    zend_result result = SUCCESS;
#else
    int result = SUCCESS;
#endif

    zend_try {
        zai_env_buffer buf = {sizeof zai_rinit_str_buf, zai_rinit_str_buf};
        zai_rinit_last_res = zai_getenv_literal("FROM_RINIT", buf);
    } zend_catch {
        result = FAILURE;
    } zend_end_try();

    return result;
}

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "rinit non-empty string", {
    tea_sapi_module.getenv = tea_sapi_getenv_non_empty;

    zai_rinit_last_res = ZAI_ENV_ERROR;
    zai_rinit_str_buf[0] = '\0';
    tea_extension_rinit(PHP_RINIT(zai_env));
},{
    REQUIRE(zai_rinit_last_res == ZAI_ENV_SUCCESS);
    REQUIRE(0 == strcmp("FROM_RINIT", zai_rinit_str_buf));
})

TEA_TEST_CASE_WITH_TAGS_WITH_PROLOGUE("env/host", "rinit non-empty string", "[adopted][env/sapi]", {
    REQUIRE(tea_sapi_module.getenv == NULL);

    zai_rinit_last_res = ZAI_ENV_ERROR;
    zai_rinit_str_buf[0] = '\0';
    tea_extension_rinit(PHP_RINIT(zai_env));

    REQUIRE_SETENV("FROM_RINIT", "bar");
},{
    REQUIRE(zai_rinit_last_res == ZAI_ENV_SUCCESS);
    REQUIRE(0 == strcmp("bar", zai_rinit_str_buf));
})
