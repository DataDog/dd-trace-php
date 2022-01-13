extern "C" {
#include "env/env.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
}

#include "zai_tests_common.hpp"

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("env/error", "NULL name", {
    REQUIRE(zai_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_string_view name = ZAI_STRL_VIEW("FOO");
    name.ptr = NULL;
    zai_env_result res = zai_getenv(name, buf);

    REQUIRE(res == ZAI_ENV_ERROR);
    REQUIRE_BUF_EQ("", buf);
})

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("env/error", "zero name len", {
    REQUIRE(zai_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("", buf);

    REQUIRE(res == ZAI_ENV_ERROR);
    REQUIRE_BUF_EQ("", buf);
})

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("env/error", "NULL buffer", {
    REQUIRE(zai_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    buf.ptr = NULL;
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_ERROR);
})

ZAI_SAPI_TEST_CASE_WITH_PROLOGUE("env/error", "zero buffer size", {
    REQUIRE(zai_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    buf.len = 0;
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_ERROR);
})

