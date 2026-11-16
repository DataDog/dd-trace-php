extern "C" {
#include "env/env.h"
#include "tea/sapi.h"
#include "tea/extension.h"
}

#include "zai_tests_common.hpp"

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "non-empty string", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_SETENV("FOO", "bar");

    REQUIRE(zai_option_str_is_some(zai_sys_getenv(ZAI_STRL("FOO"))));
})

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "empty string", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_SETENV("FOO", "");

    zai_option_str result = zai_sys_getenv(ZAI_STRL("FOO"));
    REQUIRE(zai_option_str_is_some(result));
    REQUIRE(result.len == 0);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "not set", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    REQUIRE(zai_option_str_is_none(zai_sys_getenv(ZAI_STRL("FOO"))));
})

TEA_TEST_CASE_BARE("env/host", "outside request context", {
    REQUIRE(tea_sapi_sinit());
    REQUIRE(tea_sapi_minit());
    TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()

    REQUIRE_SETENV("FOO", "bar");

    // zai_sys_getenv works outside request context (no readiness check)
    REQUIRE(zai_option_str_is_some(zai_sys_getenv(ZAI_STRL("FOO"))));

    TEA_TEST_CASE_WITHOUT_BAILOUT_END()
    tea_sapi_mshutdown();
    tea_sapi_sshutdown();
})
