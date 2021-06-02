extern "C" {
#include "env/env.h"
#include "zai_sapi/zai_sapi.h"
}

#include <catch2/catch.hpp>
#include <cstdlib>
#include <cstring>

#define TEST(name, code) TEST_CASE(name, "[zai_env]") { \
        REQUIRE(zai_sapi_spinup()); \
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

#if PHP_VERSION_ID >= 80000
#define ZAI_SAPI_GETENV_FUNCTION(fn) static char *fn(const char *name, size_t name_len)
#elif PHP_VERSION_ID >= 70000
#define ZAI_SAPI_GETENV_FUNCTION(fn) static char *fn(char *name, size_t name_len)
#else
#define ZAI_SAPI_GETENV_FUNCTION(fn) static char *fn(char *name, size_t name_len TSRMLS_DC)
#endif

/************************** zai_getenv() (from host) **************************/

TEST("get host env: non-empty string", {
    REQUIRE_SETENV("FOO", "bar");

    char buf[64];
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE(strcmp("bar", buf) == 0);
})

TEST("get host env: empty string", {
    REQUIRE_SETENV("FOO", "");

    char buf[64];
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE(strcmp("", buf) == 0);
})

TEST("get host env: not set", {
    REQUIRE_UNSETENV("FOO");

    char buf[64];
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_NOT_SET);
    REQUIRE(strcmp("", buf) == 0);
})

TEST("get host env: buffer too small", {
    REQUIRE_SETENV("FOO", "bar");

    char buf[3];  // No room for null terminator
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_BUFFER_TOO_SMALL);
    REQUIRE(strcmp("", buf) == 0);
})

TEST_CASE("get host env: outside request context", "[zai_env]") {
    REQUIRE(zai_sapi_sinit());
    REQUIRE(zai_sapi_minit());
    ZAI_SAPI_TSRMLS_FETCH();
    ZAI_SAPI_ABORT_ON_BAILOUT_OPEN()

    REQUIRE_SETENV("FOO", "bar");

    char buf[64];
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_NOT_READY);
    REQUIRE(strcmp("", buf) == 0);

    ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE()
    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}

TEST("get env error: NULL name", {
    REQUIRE_UNSETENV("FOO");

    char buf[64];
    zai_env_result res = zai_getenv(NULL, 42, buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_ERROR);
    REQUIRE(strcmp("", buf) == 0);
})

TEST("get env error: zero name len", {
    REQUIRE_UNSETENV("FOO");

    char buf[64];
    zai_env_result res = zai_getenv("FOO", 0, buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_ERROR);
    REQUIRE(strcmp("", buf) == 0);
})

TEST("get env error: NULL buffer", {
    REQUIRE_UNSETENV("FOO");

    char buf[64];
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), NULL, sizeof buf);

    REQUIRE(res == ZAI_ENV_ERROR);
})

TEST("get env error: zero buffer size", {
    REQUIRE_UNSETENV("FOO");

    char buf[64];
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), buf, 0);

    REQUIRE(res == ZAI_ENV_ERROR);
})

/************************** zai_getenv() (from SAPI) **************************/

static char zai_str_buf[64];

// Returns the name of the env var as the value
ZAI_SAPI_GETENV_FUNCTION(zai_sapi_getenv_non_empty) {
    strcpy(zai_str_buf, name);
    return zai_str_buf;
}

TEST_SAPI_GETENV("get SAPI env: non-empty string", zai_sapi_getenv_non_empty, {
    char buf[64];
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE(strcmp("FOO", buf) == 0);
})

TEST_SAPI_GETENV("get SAPI env: non-empty string (no host env fallback)", zai_sapi_getenv_non_empty, {
    REQUIRE_SETENV("FOO", "bar");

    char buf[64];
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE(strcmp("FOO", buf) == 0);
})

ZAI_SAPI_GETENV_FUNCTION(zai_sapi_getenv_null) { return NULL; }

TEST_SAPI_GETENV("get SAPI env: not set", zai_sapi_getenv_null, {
    char buf[64];
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_NOT_SET);
    REQUIRE(strcmp("", buf) == 0);
})

TEST_SAPI_GETENV("get SAPI env: not set (no host env fallback)", zai_sapi_getenv_null, {
    REQUIRE_SETENV("FOO", "bar");

    char buf[64];
    zai_env_result res = zai_getenv(ZEND_STRL("FOO"), buf, sizeof buf);

    REQUIRE(res == ZAI_ENV_NOT_SET);
    REQUIRE(strcmp("", buf) == 0);
})
