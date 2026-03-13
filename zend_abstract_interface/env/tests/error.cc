extern "C" {
#include "env/env.h"
#include "tea/sapi.h"
#include "tea/extension.h"
}

#include "zai_tests_common.hpp"

TEA_TEST_CASE_WITH_PROLOGUE("env/error", "zero name len (sys)", {},{
    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_sys_getenv(ZAI_STRL(""), &buf);

    REQUIRE(res == ZAI_ENV_ERROR);
    REQUIRE_BUF_EQ("", buf);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/error", "NULL buffer (sys)", {},{
    ZAI_ENV_BUFFER_INIT(buf, 64);
    buf.ptr = NULL;
    zai_env_result res = zai_sys_getenv(ZAI_STRL("FOO"), &buf);

    REQUIRE(res == ZAI_ENV_ERROR);
})

TEA_TEST_CASE_WITH_PROLOGUE("env/error", "zero buffer size (sys)", {},{
    ZAI_ENV_BUFFER_INIT(buf, 64);
    buf.len = 0;
    zai_env_result res = zai_sys_getenv(ZAI_STRL("FOO"), &buf);

    REQUIRE(res == ZAI_ENV_ERROR);
})

TEA_TEST_CASE_BARE("env/error", "sapi not ready outside request context", {
    REQUIRE(tea_sapi_sinit());
    REQUIRE(tea_sapi_minit());
    TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()

    ZAI_ENV_BUFFER_INIT(buf, 64);
    zai_env_result res = zai_sapi_getenv(ZAI_STRL("FOO"), &buf);

    REQUIRE(res == ZAI_ENV_NOT_READY);
    REQUIRE_BUF_EQ("", buf);

    TEA_TEST_CASE_WITHOUT_BAILOUT_END()
    tea_sapi_mshutdown();
    tea_sapi_sshutdown();
})
