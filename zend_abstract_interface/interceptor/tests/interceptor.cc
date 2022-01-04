extern "C" {
#include "ext_interceptor.h"
#include "interceptor/interceptor.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
}

#include "zai_sapi/testing/catch2.hpp"
#include <cstring>

#define TEST_PROLOGUE(targets)                 \
{                                              \
    prehook_call_count = 0;                    \
    posthook_call_count = 0;                   \
    ext_interceptor_targets = targets;         \
    ext_interceptor_ctor(&zai_sapi_extension); \
}

#define TEST_FCI(description, targets, ...)    \
    ZAI_SAPI_TEST_CASE_WITH_PROLOGUE(          \
        "zai_interceptor", description, TEST_PROLOGUE(targets), __VA_ARGS__ \
    )

/******************************* Test Helpers *********************************/

typedef struct call_target_s {
    const char *qualified_name;
    internal_hooks hooks; // Part of ext/interceptor
} call_target;

static int prehook_call_count;
static int posthook_call_count;

static void foo_prehook(zend_execute_data *execute_data) {
    prehook_call_count++;
}

static void foo_posthook(zend_execute_data *execute_data, zval *retval) {
    posthook_call_count++;
}

static void userland_targets(void) {
    static const call_target targets[] = {
        {"MyDatadog\\Foo\\App::testing", {EXT_HOOK_TYPE_STATIC, foo_prehook, foo_posthook}},
        {"\\MyDatadog\\Foo\\my_func", {EXT_HOOK_TYPE_STATIC, foo_prehook, foo_posthook}},
    };
    for (size_t i = 0; i < sizeof targets / sizeof targets[0]; ++i) {
        zai_interceptor_add_target_startup(targets[i].qualified_name, (zai_interceptor_caller_owned *)&targets[i].hooks);
    }
}

/******************* zai_interceptor_add_target_startup() *********************/

TEST_FCI("(static) userland targets", userland_targets, {

    REQUIRE(prehook_call_count == 0);
    REQUIRE(posthook_call_count == 0);

    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        REQUIRE(zai_sapi_execute_script("./stubs/index.php"));
    });

    //REQUIRE(prehook_call_count == 2);
    //REQUIRE(posthook_call_count == 2);
})

/******************* zai_interceptor_add_target_runtime() *********************/

TEST_FCI("(dynamic) userland targets", userland_targets, {
    runtime_hooks *runtime = (runtime_hooks *)zai_interceptor_add_target_runtime(
        "MyDatadog\\Foo\\App",
        "waitTillRuntimeToHookMe"
    );
    REQUIRE(runtime != NULL);
    ZVAL_LONG(&runtime->userland.prehook, 40);
    ZVAL_LONG(&runtime->userland.posthook, 2);

    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        REQUIRE(zai_sapi_execute_script("./stubs/index.php"));
    });

    //REQUIRE(ext_interceptor_userland_hook_sum() == 42);
    //REQUIRE(prehook_call_count == 2);
    //REQUIRE(posthook_call_count == 2);
})

TEST_FCI("(dynamic) replace dynamic target", userland_targets, {
    runtime_hooks *runtime = (runtime_hooks *)zai_interceptor_add_target_runtime(
        "MyDatadog\\Foo\\App",
        "waitTillRuntimeToHookMe"
    );
    REQUIRE(runtime != NULL);
    ZVAL_LONG(&runtime->userland.prehook, 40);

    runtime_hooks *runtime2 = (runtime_hooks *)zai_interceptor_add_target_runtime(
        "MyDatadog\\Foo\\App",
        "waitTillRuntimeToHookMe"
    );
    REQUIRE(runtime2 != NULL);
    ZVAL_LONG(&runtime2->userland.posthook, 2);

    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        REQUIRE(zai_sapi_execute_script("./stubs/index.php"));
    });

    //REQUIRE(ext_interceptor_userland_hook_sum() == 42);
    //REQUIRE(prehook_call_count == 2);
    //REQUIRE(posthook_call_count == 2);
})

TEST_FCI("(dynamic) replace static target", userland_targets, {
    runtime_hooks *runtime = (runtime_hooks *)zai_interceptor_add_target_runtime(
        "MyDatadog\\Foo\\App",
        "testing"
    );
    REQUIRE(runtime != NULL);
    ZVAL_LONG(&runtime->userland.prehook, 21);
    ZVAL_LONG(&runtime->userland.posthook, 21);

    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        REQUIRE(zai_sapi_execute_script("./stubs/index.php"));
    });

    //REQUIRE(ext_interceptor_userland_hook_sum() == 42);
    //REQUIRE(prehook_call_count == 2);
    //REQUIRE(posthook_call_count == 2);
})
