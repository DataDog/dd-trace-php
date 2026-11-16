extern "C" {
#include "env/env.h"
#include "tea/sapi.h"
#include "tea/extension.h"
}

#include "zai_tests_common.hpp"

TEA_TEST_CASE_WITH_PROLOGUE("env/error", "zero name len (sys)", {}, {
    REQUIRE(zai_option_str_is_none(zai_sys_getenv(ZAI_STRL(""))));
})
