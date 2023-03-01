// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "base64.h"
#include "common.hpp"
#include "json_helper.hpp"
#include "remote_config/asm_dd_listener.hpp"
#include "remote_config/product.hpp"
#include <rapidjson/document.h>

const std::string waf_rule_with_data =
    R"({"rules": [{"id": "someId", "name": "Test", "tags": {"type": "security_scanner", "category": "attack_attempt"}, "conditions": [{"parameters": {"inputs": [{"address": "http.url"} ], "regex": "(?i)\\evil\\b"}, "operator": "match_regex"} ], "transformers": [], "on_match": ["block"] } ] })";

namespace dds {

namespace mock {
class engine : public dds::engine {
public:
    explicit engine(
        uint32_t trace_rate_limit = engine_settings::default_trace_rate_limit,
        action_map &&actions = {})
        : dds::engine(trace_rate_limit, std::move(actions))
    {}
    MOCK_METHOD(void, update,
        (engine_ruleset &, (std::map<std::string_view, std::string> &),
            (std::map<std::string_view, double> &)),
        (override));

    static auto create() { return std::shared_ptr<engine>(new engine()); }
};
} // namespace mock

remote_config::config get_asm_dd_data(
    const std::string &content, bool encode = true)
{
    std::string encoded_content = content;
    if (encode) {
        encoded_content = base64_encode(content);
    }

    return {"some product", "some id", encoded_content, "some path", {}, 123,
        321,
        remote_config::protocol::config_state::applied_state::UNACKNOWLEDGED,
        ""};
}

ACTION_P(SaveDocument, param)
{
    rapidjson::Document &document =
        *reinterpret_cast<rapidjson::Document *>(param);

    arg0.copy(document);
}

TEST(RemoteConfigAsmDdListener, ItParsesRules)
{
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    remote_config::asm_dd_listener listener(engine, "");

    listener.on_update(get_asm_dd_data(waf_rule_with_data));

    const auto &rules = doc["rules"];
    const auto &first = rules[0];
    EXPECT_STREQ("someId", first.FindMember("id")->value.GetString());
}

TEST(RemoteConfigAsmDdListener, OnUnApplyUsesFallback)
{
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    remote_config::asm_dd_listener listener(engine, create_sample_rules_ok());

    listener.on_unapply(get_asm_dd_data(waf_rule_with_data));

    const auto &rules = doc["rules"];
    const auto &first = rules[0];
    EXPECT_STREQ("blk-001-001", first.FindMember("id")->value.GetString());
}

TEST(RemoteConfigAsmDdListener, ItThrowsAnErrorIfContentNotInBase64)
{
    std::string invalid_content = "&&&";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config non_base_64_content_config =
        get_asm_dd_data(invalid_content, false);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_dd_listener listener(engine, "");

    try {
        listener.on_update(non_base_64_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDdListener, ItThrowsAnErrorIfContentNotValidJsonContent)
{
    std::string invalid_content = "InvalidJsonContent";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config invalid_json_config =
        get_asm_dd_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_dd_listener listener(engine, "");

    try {
        listener.on_update(invalid_json_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

} // namespace dds
