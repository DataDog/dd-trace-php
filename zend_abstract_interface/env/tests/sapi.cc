extern "C" {
#include "env/env.h"
#include "tea/sapi.h"
#include "tea/extension.h"
}

#include "zai_tests_common.hpp"
#include <string>

#if PHP_VERSION_ID >= 80000
#define TEA_SAPI_GETENV_FUNCTION(fn) static char *fn(const char *name, size_t name_len)
#else
#define TEA_SAPI_GETENV_FUNCTION(fn) static char *fn(char *name, size_t name_len)
#endif

static std::string zai_option_str_format(zai_option_str opt) {
    zai_str view;
    if (zai_option_str_get(opt, &view)) {
        return std::string("Some(\"") + std::string(view.ptr, view.len) + "\")";
    }
    return "None";
}

#define REQUIRE_OPTION_STR_EQ(actual, expected)                                            \
    do {                                                                                  \
        zai_option_str _req_opt_actual = (actual);                                         \
        zai_option_str _req_opt_expected = (expected);                                     \
        INFO("comparing " << zai_option_str_format(_req_opt_expected) << " and " << zai_option_str_format(_req_opt_actual)); \
        REQUIRE(zai_option_str_eq(_req_opt_actual, _req_opt_expected));                    \
    } while (0)

static char zai_str_buf[64];

// Returns the name of the env var as the value
TEA_SAPI_GETENV_FUNCTION(tea_sapi_getenv_non_empty) {
    strcpy(zai_str_buf, name);
    return zai_str_buf;
}

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "non-empty string", {
    tea_sapi_module.getenv = tea_sapi_getenv_non_empty;
},{
    zai_option_str opt = zai_sapi_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STRL("FOO"));
})

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "non-empty string (no host env fallback)", {
    tea_sapi_module.getenv = tea_sapi_getenv_non_empty;
},{
    REQUIRE_SETENV("FOO", "bar");

    zai_option_str opt = zai_sapi_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STRL("FOO"));
})

TEA_SAPI_GETENV_FUNCTION(tea_sapi_getenv_null) { return NULL; }

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "not set", {
    tea_sapi_module.getenv = tea_sapi_getenv_null;
},{
    REQUIRE_UNSETENV("FOO");

    zai_option_str opt = zai_sapi_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STR_NONE);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "not set (with host env fallback)", {
    tea_sapi_module.getenv = tea_sapi_getenv_null;
},{
    REQUIRE_SETENV("FOO", "bar");

    zai_option_str opt = zai_sapi_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STR_NONE);

    opt = zai_sys_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STRL("bar"));
})

/****************************** Access from RINIT *****************************/

static char zai_rinit_str_buf[64];
static bool zai_rinit_got_value;

static PHP_RINIT_FUNCTION(zai_env) {
#if PHP_VERSION_ID >= 80000
    zend_result result = SUCCESS;
#else
    int result = SUCCESS;
#endif

    zend_try {
        zai_option_str opt = zai_sapi_getenv(ZAI_STRL("FROM_RINIT"));
        if (zai_option_str_is_none(opt)) {
            opt = zai_sys_getenv(ZAI_STRL("FROM_RINIT"));
        }
        zai_rinit_got_value = zai_option_str_is_some(opt);
        if (zai_rinit_got_value) {
            zai_str v;
            zai_option_str_get(opt, &v);
            size_t n = v.len < sizeof(zai_rinit_str_buf) - 1 ? v.len : sizeof(zai_rinit_str_buf) - 1;
            memcpy(zai_rinit_str_buf, v.ptr, n);
            zai_rinit_str_buf[n] = '\0';
        }
    } zend_catch {
        result = FAILURE;
    } zend_end_try();

    return result;
}

TEA_TEST_CASE_WITH_PROLOGUE("env/sapi", "rinit non-empty string", {
    tea_sapi_module.getenv = tea_sapi_getenv_non_empty;

    zai_rinit_got_value = false;
    zai_rinit_str_buf[0] = '\0';
    tea_extension_rinit(PHP_RINIT(zai_env));
},{
    REQUIRE(zai_rinit_got_value);
    REQUIRE(0 == strcmp("FROM_RINIT", zai_rinit_str_buf));
})

TEA_TEST_CASE_WITH_TAGS_WITH_PROLOGUE("env/host", "rinit non-empty string", "[adopted][env/sapi]", {
    tea_sapi_module.getenv = NULL;

    REQUIRE_SETENV("FROM_RINIT", "bar");

    zai_rinit_got_value = false;
    zai_rinit_str_buf[0] = '\0';
    tea_extension_rinit(PHP_RINIT(zai_env));
},{
    REQUIRE(zai_rinit_got_value);
    REQUIRE(0 == strcmp("bar", zai_rinit_str_buf));
})
