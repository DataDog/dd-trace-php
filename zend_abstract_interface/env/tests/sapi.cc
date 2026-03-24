extern "C" {
#include "env/env.h"
#include "tea/sapi.h"
#include "tea/extension.h"
}

#include "zai_tests_common.hpp"

#if PHP_VERSION_ID >= 80000
#define TEA_SAPI_GETENV_FUNCTION(fn) static char *fn(const char *name, size_t name_len)
#else
#define TEA_SAPI_GETENV_FUNCTION(fn) static char *fn(char *name, size_t name_len)
#endif

// Returns the name of the env var as the value (emalloc'd so efree inside zai_sapi_getenv works)
TEA_SAPI_GETENV_FUNCTION(tea_sapi_getenv_non_empty) {
    return estrdup(name);
}

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "non-empty string", {
    tea_sapi_module.getenv = tea_sapi_getenv_non_empty;
},{
    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_sapi_getenv(ZAI_STRL("FOO"), &buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("FOO", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "non-empty string (no host env fallback)", {
    tea_sapi_module.getenv = tea_sapi_getenv_non_empty;
},{
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_sapi_getenv(ZAI_STRL("FOO"), &buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("FOO", buf);
})

TEA_SAPI_GETENV_FUNCTION(tea_sapi_getenv_null) { return NULL; }

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "fallback to host env when sapi not set", {
    tea_sapi_module.getenv = tea_sapi_getenv_null;
    REQUIRE_SETENV("FOO", "bar");
},{
    ZAI_ENV_BUFFER_INIT(buf, 64);

    zai_env_result res = zai_sapi_getenv(ZAI_STRL("FOO"), &buf);
    REQUIRE(res == ZAI_ENV_NOT_SET);

    zai_option_str sys = zai_sys_getenv(ZAI_STRL("FOO"));
    REQUIRE(zai_option_str_is_some(sys));
    REQUIRE(sys.len == strlen("bar"));
    REQUIRE(0 == memcmp(sys.ptr, "bar", sys.len));
})

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "not set", {
    tea_sapi_module.getenv = tea_sapi_getenv_null;
},{
    REQUIRE_UNSETENV("FOO");
    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_sapi_getenv(ZAI_STRL("FOO"), &buf);

    REQUIRE(res == ZAI_ENV_NOT_SET);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "not set (sapi returns null regardless of host env)", {
    tea_sapi_module.getenv = tea_sapi_getenv_null;
},{
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_sapi_getenv(ZAI_STRL("FOO"), &buf);

    // zai_sapi_getenv only consults SAPI, not host env
    REQUIRE(res == ZAI_ENV_NOT_SET);
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
        zai_rinit_last_res = zai_sapi_getenv(ZAI_STRL("FROM_RINIT"), &buf);
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
