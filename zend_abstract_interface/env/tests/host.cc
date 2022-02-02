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

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("bar", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "empty string", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_SETENV("FOO", "");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "not set", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_UNSETENV("FOO");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_NOT_SET);
    REQUIRE_BUF_EQ("", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "max buffer size", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_SUCCESS);
    REQUIRE_BUF_EQ("bar", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "buffer too small", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 3);  // No room for null terminator
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_BUFFER_TOO_SMALL);
    REQUIRE_BUF_EQ("", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/host", "buffer too big", {
    REQUIRE(tea_sapi_module.getenv == NULL);
},{
    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, ZAI_ENV_MAX_BUFSIZ + 1);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_BUFFER_TOO_BIG);
    REQUIRE_BUF_EQ("", buf);
})

TEA_TEST_CASE_BARE("env/host", "outside request context", {
    REQUIRE(tea_sapi_sinit());
    REQUIRE(tea_sapi_minit());
    TEA_TSRMLS_FETCH();
    TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()

    REQUIRE_SETENV("FOO", "bar");

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_getenv_literal("FOO", buf);

    REQUIRE(res == ZAI_ENV_NOT_READY);
    REQUIRE_BUF_EQ("", buf);

    TEA_TEST_CASE_WITHOUT_BAILOUT_END()
    tea_sapi_mshutdown();
    tea_sapi_sshutdown();
})
