extern "C" {
#include "env/env.h"
#include "tea/sapi.h"
#include "tea/extension.h"
}

#include "zai_tests_common.hpp"
#include <string>

static std::string zai_option_str_format(zai_option_str opt) {
    zai_str view;
    if (zai_option_str_get(opt, &view)) {
        return std::string("Some(\"") + std::string(view.ptr, view.len) + "\")";
    } else {
        return "None";
    }
}

#define REQUIRE_OPTION_STR_EQ(actual, expected)                                            \
    do {                                                                                  \
        zai_option_str _req_opt_actual = (actual);                                         \
        zai_option_str _req_opt_expected = (expected);                                     \
        INFO("comparing " << zai_option_str_format(_req_opt_expected) << " and " << zai_option_str_format(_req_opt_actual)); \
        REQUIRE(zai_option_str_eq(_req_opt_actual, _req_opt_expected));                    \
    } while (0)

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "non-empty string", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_SETENV("FOO", "bar");

    zai_option_str opt = zai_sys_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STRL("bar"));

    opt = zai_sapi_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STR_NONE);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "empty string", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_SETENV("FOO", "");

    zai_option_str opt = zai_sys_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STRL(""));

    opt = zai_sapi_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STR_NONE);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "not set", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    zai_option_str opt = zai_sys_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STR_NONE);

    opt = zai_sapi_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STR_NONE);
})

TEA_TEST_CASE_BARE("env/host", "outside request context", {
    REQUIRE(tea_sapi_sinit());
    REQUIRE(tea_sapi_minit());
    TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()

    REQUIRE_SETENV("FOO", "bar");

    zai_option_str opt = zai_sys_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STRL("bar"));

    opt = zai_sapi_getenv(ZAI_STRL("FOO"));
    REQUIRE_OPTION_STR_EQ(opt, ZAI_OPTION_STR_NONE);

    TEA_TEST_CASE_WITHOUT_BAILOUT_END()
    tea_sapi_mshutdown();
    tea_sapi_sshutdown();
})
