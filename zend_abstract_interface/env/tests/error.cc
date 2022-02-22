extern "C" {
#include "env/env.h"
#include "tea/sapi.h"
#include "tea/extension.h"
}

#include "zai_tests_common.hpp"

TEA_TEST_CASE_WITH_PROLOGUE("env/error", "NULL name", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_string_view name = ZAI_STRL_VIEW("FOO");
    name.ptr = NULL;
    zai_env_result res = zai_getenv(name, buf);

    REQUIRE(res == ZAI_ENV_ERROR);
    REQUIRE_BUF_EQ("", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/error", "zero name len", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("", buf);

    REQUIRE(res == ZAI_ENV_ERROR);
    REQUIRE_BUF_EQ("", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/error", "NULL buffer", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    buf.ptr = NULL;
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_ERROR);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/error", "zero buffer size", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    buf.len = 0;
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_ERROR);
})

