extern "C" {
#include "env/env.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
}

#include <catch2/catch.hpp>
#include <cstdlib>
#include <cstring>

#define TEST(name, code) TEST_CASE(name, "[zai_env]") { \
        REQUIRE(zai_sapi_sinit()); \
        REQUIRE(zai_module.getenv == NULL); \
        REQUIRE(zai_sapi_minit()); \
        REQUIRE(zai_sapi_rinit()); \
        ZAI_SAPI_TSRMLS_FETCH(); \
        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() \
        { code } \
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE() \
        zai_sapi_spindown(); \
    }

#define TEST_SAPI_GETENV(name, sapi_getenv, code) TEST_CASE(name, "[zai_env]") { \
        REQUIRE(zai_sapi_sinit()); \
        zai_module.getenv = sapi_getenv; \
        REQUIRE(zai_sapi_minit()); \
        REQUIRE(zai_sapi_rinit()); \
        ZAI_SAPI_TSRMLS_FETCH(); \
        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() \
        { code } \
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE() \
        zai_sapi_spindown(); \
    }

#define REQUIRE_SETENV(key, val) REQUIRE(0 == setenv(key, val, /* overwrite */ 1))
#define REQUIRE_UNSETENV(key) REQUIRE(0 == unsetenv(key))
#define REQUIRE_BUF_EQ(str, buf) REQUIRE(0 == strcmp(str, buf.ptr))

#if PHP_VERSION_ID >= 80000
#define ZAI_SAPI_GETENV_FUNCTION(fn) static char *fn(const char *name, size_t name_len)
#elif PHP_VERSION_ID >= 70000
#define ZAI_SAPI_GETENV_FUNCTION(fn) static char *fn(char *name, size_t name_len)
#else
#define ZAI_SAPI_GETENV_FUNCTION(fn) static char *fn(char *name, size_t name_len TSRMLS_DC)
#endif

/********************** zai_getenv_literal() (from host) **********************/

TEST("get host env: non-empty string", {
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("bar", buf);
})

TEST("get host env: empty string", {
    REQUIRE_SETENV("FOO", "");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("", buf);
})

TEST("get host env: not set", {
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_NOT_SET);
    REQUIRE_BUF_EQ("", buf);
})

TEST("get host env: max buffer size", {
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("bar", buf);
})

TEST("get host env: buffer too small", {
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 3);  // No room for null terminator
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_BUFFER_TOO_SMALL);
    REQUIRE_BUF_EQ("", buf);
})

TEST("get host env: buffer too big", {
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ + 1);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_BUFFER_TOO_BIG);
    REQUIRE_BUF_EQ("", buf);
})

TEST_CASE("get host env: outside request context", "[zai_env]") {
    REQUIRE(zai_sapi_sinit());
    REQUIRE(zai_sapi_minit());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_NOT_READY);
    REQUIRE_BUF_EQ("", buf);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}

TEST("get env error: NULL name", {
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_string_view name = ZAI_STRL_VIEW("FOO");
    name.ptr = NULL;
    zai_env_result res = zai_getenv(name, buf);

    REQUIRE(res == ZAI_ENV_ERROR);
    REQUIRE_BUF_EQ("", buf);
})

TEST("get env error: zero name len", {
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("", buf);

    REQUIRE(res == ZAI_ENV_ERROR);
    REQUIRE_BUF_EQ("", buf);
})

TEST("get env error: NULL buffer", {
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    buf.ptr = NULL;
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_ERROR);
})

TEST("get env error: zero buffer size", {
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    buf.len = 0;
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_ERROR);
})

/********************** zai_getenv_literal() (from SAPI) **********************/

static char zai_str_buf[64];

// Returns the name of the env var as the value
ZAI_SAPI_GETENV_FUNCTION(zai_sapi_getenv_non_empty) {
    strcpy(zai_str_buf, name);
    return zai_str_buf;
}

TEST_SAPI_GETENV("get SAPI env: non-empty string", zai_sapi_getenv_non_empty, {
    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("FOO", buf);
})

TEST_SAPI_GETENV("get SAPI env: non-empty string (no host env fallback)", zai_sapi_getenv_non_empty, {
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("FOO", buf);
})

ZAI_SAPI_GETENV_FUNCTION(zai_sapi_getenv_null) { return NULL; }

TEST_SAPI_GETENV("get SAPI env: not set", zai_sapi_getenv_null, {
    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_NOT_SET);
    REQUIRE_BUF_EQ("", buf);
})

TEST_SAPI_GETENV("get SAPI env: not set (with host env fallback)", zai_sapi_getenv_null, {
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
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    zai_env_buffer buf = {sizeof zai_rinit_str_buf, zai_rinit_str_buf};
    zai_rinit_last_res = zai_getenv_literal("FROM_RINIT", buf);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    return SUCCESS;
}

TEST_CASE("get SAPI env (RINIT): non-empty string", "[zai_env]") {
    REQUIRE(zai_sapi_sinit());

    zai_module.getenv = zai_sapi_getenv_non_empty;

    zai_rinit_last_res = ZAI_ENV_ERROR;
    zai_rinit_str_buf[0] = '\0';
    zai_sapi_extension.request_startup_func = PHP_RINIT(zai_env);

    REQUIRE(zai_sapi_minit());
    REQUIRE(zai_sapi_rinit());  // Env var is fetched here

    REQUIRE(zai_rinit_last_res == ZAI_ENV_SUCCESS);
    REQUIRE(0 == strcmp("FROM_RINIT", zai_rinit_str_buf));

    zai_sapi_spindown();
}

TEST_CASE("get host env (RINIT): non-empty string", "[zai_env]") {
    REQUIRE(zai_sapi_sinit());

    REQUIRE(zai_module.getenv == NULL);

    zai_rinit_last_res = ZAI_ENV_ERROR;
    zai_rinit_str_buf[0] = '\0';
    zai_sapi_extension.request_startup_func = PHP_RINIT(zai_env);

    REQUIRE_SETENV("FROM_RINIT", "bar");

    REQUIRE(zai_sapi_minit());
    REQUIRE(zai_sapi_rinit());  // Env var is fetched here

    REQUIRE(zai_rinit_last_res == ZAI_ENV_SUCCESS);
    REQUIRE(0 == strcmp("bar", zai_rinit_str_buf));

    zai_sapi_spindown();
}
