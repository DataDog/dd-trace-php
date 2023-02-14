// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "base64.h"
#include "common.hpp"
#include "json_helper.hpp"
#include "remote_config/asm_data_listener.hpp"
#include "remote_config/product.hpp"
#include <rapidjson/document.h>
#include <rapidjson/prettywriter.h>

namespace dds {

namespace mock {
class engine : public dds::engine {
public:
    explicit engine(
        uint32_t trace_rate_limit = engine_settings::default_trace_rate_limit,
        action_map &&actions = {})
        : dds::engine(trace_rate_limit, std::move(actions))
    {}
    MOCK_METHOD(
        void, update_rule_data, (dds::parameter_view & parameter), (override));

    static auto create() { return std::shared_ptr<engine>(new engine()); }
};
} // namespace mock

ACTION_P(SaveParameterView, param)
{
    rapidjson::Document &document =
        *reinterpret_cast<rapidjson::Document *>(param);
    std::string str = parameter_to_json(arg0);
    document.Parse(str);
}

struct test_rule_data_data {
    std::optional<uint64_t> expiration;
    std::string value;
};

struct test_rule_data {
    std::string id;
    std::string type;
    std::vector<test_rule_data_data> data;
};

remote_config::config get_asm_data(
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

remote_config::config get_rules_data(std::vector<test_rule_data> data)
{
    rapidjson::Document document;
    rapidjson::Document::AllocatorType &alloc = document.GetAllocator();

    document.SetObject();

    rapidjson::Value rules_json(rapidjson::kArrayType);

    for (const auto &rule_data : data) {
        rapidjson::Value rule_data_entry(rapidjson::kObjectType);

        // Generate data on entry
        rapidjson::Value data_json(rapidjson::kArrayType);
        for (const auto &data_entry : rule_data.data) {
            rapidjson::Value data_json_entry(rapidjson::kObjectType);
            data_json_entry.AddMember("value", data_entry.value, alloc);
            if (data_entry.expiration) {
                data_json_entry.AddMember(
                    "expiration", data_entry.expiration.value(), alloc);
            }
            data_json.PushBack(data_json_entry, alloc);
        }

        rule_data_entry.AddMember("id", rule_data.id, alloc);
        rule_data_entry.AddMember("type", rule_data.type, alloc);
        rule_data_entry.AddMember("data", data_json, alloc);
        rules_json.PushBack(rule_data_entry, alloc);
    }

    document.AddMember("rules_data", rules_json, alloc);

    dds::string_buffer buffer;
    rapidjson::Writer<decltype(buffer)> writer(buffer);
    document.Accept(writer);

    return get_asm_data(buffer.get_string_ref());
}

TEST(RemoteConfigAsmDataListener, ItParsesRulesData)
{
    auto engine = mock::engine::create();

    rapidjson::Document rules;

    std::vector<test_rule_data> rules_data = {
        {"id01", "type01", {{11, "1.2.3.4"}, {3657529743, "5.6.7.8"}}},
        {"id02", "type02", {{std::nullopt, "user1"}}}};

    EXPECT_CALL(*engine, update_rule_data(_))
        .Times(1)
        .WillOnce(DoAll(SaveParameterView(&rules)));

    remote_config::asm_data_listener listener(engine);

    listener.on_update(get_rules_data(rules_data));

    const auto &first = rules[0];
    EXPECT_STREQ("id02", first.FindMember("id")->value.GetString());
    EXPECT_STREQ("type02", first.FindMember("type")->value.GetString());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ("user1", first_in_data.FindMember("value")->value.GetString());
    EXPECT_TRUE(
        first_in_data.FindMember("expiration") == first_in_data.MemberEnd());

    const auto &second = rules[1];
    EXPECT_STREQ("id01", second.FindMember("id")->value.GetString());
    EXPECT_STREQ("type01", second.FindMember("type")->value.GetString());
    const auto &second_first_data =
        second.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ(
        "1.2.3.4", second_first_data.FindMember("value")->value.GetString());
    EXPECT_EQ(
        11, second_first_data.FindMember("expiration")->value.GetUint64());
    const auto &second_second_data =
        second.FindMember("data")->value.GetArray()[1];
    EXPECT_STREQ(
        "5.6.7.8", second_second_data.FindMember("value")->value.GetString());
    EXPECT_EQ(3657529743,
        second_second_data.FindMember("expiration")->value.GetUint64());
}

TEST(RemoteConfigAsmDataListener, ItMergesValuesWhenIdIsTheSame)
{

    std::vector<test_rule_data> rules_data = {
        {"id01", "type01", {{11, "1.2.3.4"}}},
        {"id01", "type01", {{22, "5.6.7.8"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_))
        .Times(1)
        .WillOnce(DoAll(SaveParameterView(&rules)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));

    EXPECT_EQ(1, rules.Size());
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ("type01", first.FindMember("type")->value.GetString());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ(
        "1.2.3.4", first_in_data.FindMember("value")->value.GetString());
    EXPECT_EQ(11, first_in_data.FindMember("expiration")->value.GetInt64());
    const auto &second_in_data = first.FindMember("data")->value.GetArray()[1];
    EXPECT_STREQ(
        "5.6.7.8", second_in_data.FindMember("value")->value.GetString());
    EXPECT_EQ(22, second_in_data.FindMember("expiration")->value.GetInt64());
}

TEST(RemoteConfigAsmDataListener, WhenIdMatchesTypeIsSecondTypeIsIgnored)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "type01", {{11, "1.2.3.4"}}},
        {"id01", "another type", {{22, "5.6.7.8"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_))
        .Times(1)
        .WillOnce(DoAll(SaveParameterView(&rules)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));

    EXPECT_EQ(1, rules.Size());
    // First
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ("type01", first.FindMember("type")->value.GetString());
    EXPECT_EQ(2, first.FindMember("data")->value.GetArray().Size());
}

TEST(RemoteConfigAsmDataListener,
    IfTwoEntriesWithTheSameIdHaveTheSameValueItGetsLatestExpirationOnDifferentSets)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "type01", {{11, "1.2.3.4"}}},
        {"id01", "type01", {{33, "1.2.3.4"}}},
        {"id01", "type01", {{22, "1.2.3.4"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_))
        .Times(1)
        .WillOnce(DoAll(SaveParameterView(&rules)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));

    EXPECT_EQ(1, rules.Size());
    // First
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ("type01", first.FindMember("type")->value.GetString());
    EXPECT_EQ(1, first.FindMember("data")->value.GetArray().Size());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ(
        "1.2.3.4", first_in_data.FindMember("value")->value.GetString());
    EXPECT_EQ(33, first_in_data.FindMember("expiration")->value.GetInt64());
}

TEST(RemoteConfigAsmDataListener,
    IfTwoEntriesWithTheSameIdHaveTheSameValueItGetsLatestExpirationInSameSet)
{
    std::vector<test_rule_data> rules_data = {{"id01", "type01",
        {{11, "1.2.3.4"}, {33, "1.2.3.4"}, {22, "1.2.3.4"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_))
        .Times(1)
        .WillOnce(DoAll(SaveParameterView(&rules)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));

    EXPECT_EQ(1, rules.Size());
    // First
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ("type01", first.FindMember("type")->value.GetString());
    EXPECT_EQ(1, first.FindMember("data")->value.GetArray().Size());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ(
        "1.2.3.4", first_in_data.FindMember("value")->value.GetString());
    EXPECT_EQ(33, first_in_data.FindMember("expiration")->value.GetInt64());
}

TEST(RemoteConfigAsmDataListener,
    NonExitingExprirationMeansItNeversExpireAndThereforeTakesPriority)
{
    std::vector<test_rule_data> rules_data = {{"id01", "type01",
        {{11, "1.2.3.4"}, {std::nullopt, "1.2.3.4"},
            {std::numeric_limits<uint64_t>::max(), "1.2.3.4"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_))
        .Times(1)
        .WillOnce(DoAll(SaveParameterView(&rules)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));

    EXPECT_EQ(1, rules.Size());
    // First
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ("type01", first.FindMember("type")->value.GetString());
    EXPECT_EQ(1, first.FindMember("data")->value.GetArray().Size());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ(
        "1.2.3.4", first_in_data.FindMember("value")->value.GetString());
    EXPECT_TRUE(
        first_in_data.FindMember("expiration") == first_in_data.MemberEnd());
}

TEST(RemoteConfigAsmDataListener, IfDataIsEmptyItDoesNotAddAnyRule)
{
    std::vector<test_rule_data> rules_data = {{"id01", "type01", {}}};

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_))
        .Times(1)
        .WillOnce(DoAll(SaveParameterView(&rules)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));

    EXPECT_EQ(0, rules.Size());
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfContentNotInBase64)
{
    std::string invalid_content = "&&&";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config non_base_64_content_config =
        get_asm_data(invalid_content, false);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(non_base_64_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfContentNotValidJsonContent)
{
    std::string invalid_content = "InvalidJsonContent";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config invalid_json_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_json_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfNoRulesDataKey)
{
    std::string invalid_content = "{\"another_key\": 1234}";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data key missing or "
        "invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfRulesDataNotArray)
{
    std::string invalid_content = "{ \"rules_data\": 1234}";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data key missing or "
        "invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfRulesDataEntryNotObject)
{
    std::string invalid_content = "{\"rules_data\": [\"invalid\"] }";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data entry invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfNoId)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, \"value\": "
        "\"1.2.3.4\"} ], \"type\": \"some_type\"} ] }";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfIdNotString)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, \"value\": "
        "\"1.2.3.4\"} ], \"id\": 1234, \"type\": \"some_type\"} ] }";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfNoType)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, \"value\": "
        "\"1.2.3.4\"} ], \"id\": \"some_id\"} ] }";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfTypeNotString)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, \"value\": "
        "\"1.2.3.4\"} ], \"type\": 1234, \"id\": \"some_id\"} ] }";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfNoData)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"id\": \"some_id\", \"type\": \"some_type\"} ]}";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfDataNotArray)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": 1234, \"id\":\"some_id\", \"type\": "
        "\"some_type\"} ]}";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfDataEntryNotObject)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [ \"invalid\" ], \"id\": \"some_id\", "
        "\"type\": \"some_type\"} ] }";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: Entry on data not a valid object";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfDataExpirationHasInvalidType)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": \"invalid\", "
        "\"value\": \"1.2.3.4\"} ], \"id\": \"some_id\", \"type\": "
        "\"some_type\"} ] }";
    std::string error_message = "";
    std::string expected_error_message = "Invalid type for expiration entry";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfDataValueMissing)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11} ], \"id\": "
        "\"some_id\", \"type\": \"some_type\"} ] }";
    std::string error_message = "";
    std::string expected_error_message = "Invalid value of data entry";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItThrowsAnErrorIfDataValueHasInvalidType)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, "
        "\"value\": 1234} ], \"id\": \"some_id\", \"type\": "
        "\"some_type\"} ] }";
    std::string error_message = "";
    std::string expected_error_message = "Invalid value of data entry";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document rules;
    EXPECT_CALL(*engine, update_rule_data(_)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}
} // namespace dds