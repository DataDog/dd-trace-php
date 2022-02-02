extern "C" {
#include "config_test_helpers.h"

#include "config/config.h"
#include "ext_zai_config.h"
}

#include "zai_tests_common.hpp"

#define TEST_ID(description, ...)      TEA_TEST_CASE_BARE("config/id", description, ZAI_CONFIG_TEST_BODY(__VA_ARGS__))

/************************ config/id *************************/

TEST_ID("bool", {
    REQUEST_BEGIN()

    zai_config_id id;
    bool res = zai_config_get_id_by_name(ZAI_STRL_VIEW("FOO_BOOL"), &id);

    REQUIRE(res == true);
    REQUIRE(id == EXT_CFG_FOO_BOOL);

    REQUEST_END()
})

TEST_ID("alias", {
    REQUEST_BEGIN()

    zai_config_id id;
    bool res = zai_config_get_id_by_name(ZAI_STRL_VIEW("BAR_ALIASED_INT_OLDEST"), &id);

    REQUIRE(res == true);
    REQUIRE(id == EXT_CFG_BAR_ALIASED_INT);

    REQUEST_END()
})

TEST_ID("unknown", {
    REQUEST_BEGIN()

    zai_config_id id;
    bool res = zai_config_get_id_by_name(ZAI_STRL_VIEW("THIS_DOES_NOT_EXIST"), &id);

    REQUIRE(res == false);

    REQUEST_END()
})

TEST_ID("null name", {
    REQUEST_BEGIN()

    zai_config_id id;
    zai_string_view name = ZAI_STRL_VIEW("FOO_BOOL");
    name.ptr = NULL;
    bool res = zai_config_get_id_by_name(name, &id);

    REQUIRE(res == false);

    REQUEST_END()
})

TEST_ID("null id", {
    REQUEST_BEGIN()

    bool res = zai_config_get_id_by_name(ZAI_STRL_VIEW("FOO_BOOL"), NULL);

    REQUIRE(res == false);

    REQUEST_END()
})

