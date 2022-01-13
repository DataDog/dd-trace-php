extern "C" {
#include "env/env.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
}

#include "zai_tests_common.hpp"

#if PHP_VERSION_ID >= 80000
#define ZAI_SAPI_GETENV_FUNCTION(fn) static char *fn(const char *name, size_t name_len)
#elif PHP_VERSION_ID >= 70000
#define ZAI_SAPI_GETENV_FUNCTION(fn) static char *fn(char *name, size_t name_len)
#else
#define ZAI_SAPI_GETENV_FUNCTION(fn) static char *fn(char *name, size_t name_len TSRMLS_DC)
#endif

static char zai_str_buf[64];

// Returns the name of the env var as the value
ZAI_SAPI_GETENV_FUNCTION(zai_sapi_getenv_non_empty) {
    strcpy(zai_str_buf, name);
    return zai_str_buf;
}

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("env/sapi", "non-empty string", {
    zai_module.getenv = zai_sapi_getenv_non_empty;
},{
    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("FOO", buf);
})

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("env/sapi", "non-empty string (no host env fallback)", {
    zai_module.getenv = zai_sapi_getenv_non_empty;
},{
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("FOO", buf);
})

ZAI_SAPI_GETENV_FUNCTION(zai_sapi_getenv_null) { return NULL; }

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("env/sapi", "not set", {
    zai_module.getenv = zai_sapi_getenv_null;
},{
    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_NOT_SET);
    REQUIRE_BUF_EQ("", buf);
})

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("env/sapi", "not set (with host env fallback)", {
    zai_module.getenv = zai_sapi_getenv_null;
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

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("env/sapi", "rinit non-empty string", {
    zai_module.getenv = zai_sapi_getenv_non_empty;

    zai_rinit_last_res = ZAI_ENV_ERROR;
    zai_rinit_str_buf[0] = '\0';
    zai_sapi_extension.request_startup_func = PHP_RINIT(zai_env);
},{
    REQUIRE(zai_rinit_last_res == ZAI_ENV_SUCCESS);
    REQUIRE(0 == strcmp("FROM_RINIT", zai_rinit_str_buf));
})

ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_PROLOGUE("env/host", "rinit non-empty string", "[adopted][env/sapi]", {
    REQUIRE(zai_module.getenv == NULL);

    zai_rinit_last_res = ZAI_ENV_ERROR;
    zai_rinit_str_buf[0] = '\0';
    zai_sapi_extension.request_startup_func = PHP_RINIT(zai_env);

    REQUIRE_SETENV("FROM_RINIT", "bar");
},{
    REQUIRE(zai_rinit_last_res == ZAI_ENV_SUCCESS);
    REQUIRE(0 == strcmp("bar", zai_rinit_str_buf));
})
