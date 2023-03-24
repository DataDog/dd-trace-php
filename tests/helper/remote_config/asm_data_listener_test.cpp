// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../common.hpp"
#include "base64.h"
#include "json_helper.hpp"
#include "mocks.hpp"
#include "remote_config/asm_data_listener.hpp"
#include "remote_config/exception.hpp"
#include "remote_config/product.hpp"
#include <engine.hpp>
#include <rapidjson/document.h>
#include <rapidjson/prettywriter.h>
#include <subscriber/waf.hpp>

namespace dds::remote_config {

ACTION_P(SaveDocument, param)
{
    rapidjson::Document &document =
        *reinterpret_cast<rapidjson::Document *>(param);

    arg0.copy(document);
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

TEST(RemoteConfigAsmDataListener, ParseRulesData)
{
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    std::vector<test_rule_data> rules_data = {
        {"id01", "ip_with_expiration",
            {{11, "1.2.3.4"}, {3657529743, "5.6.7.8"}}},
        {"id02", "data_with_expiration", {{std::nullopt, "user1"}}}};

    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    remote_config::asm_data_listener listener(engine);

    listener.on_update(get_rules_data(rules_data));
    listener.commit();

    const auto &rules = doc["rules_data"];
    const auto &first = rules[0];
    EXPECT_STREQ("id02", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "data_with_expiration", first.FindMember("type")->value.GetString());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ("user1", first_in_data.FindMember("value")->value.GetString());
    EXPECT_TRUE(
        first_in_data.FindMember("expiration") == first_in_data.MemberEnd());

    const auto &second = rules[1];
    EXPECT_STREQ("id01", second.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "ip_with_expiration", second.FindMember("type")->value.GetString());
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
        {"id01", "ip_with_expiration", {{11, "1.2.3.4"}}},
        {"id01", "ip_with_expiration", {{22, "5.6.7.8"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));
    listener.commit();

    const auto &rules = doc["rules_data"];
    EXPECT_EQ(1, rules.Size());
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "ip_with_expiration", first.FindMember("type")->value.GetString());
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
        {"id01", "ip_with_expiration", {{11, "1.2.3.4"}}},
        {"id01", "data_with_expiration", {{22, "5.6.7.8"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));
    listener.commit();

    const auto &rules = doc["rules_data"];
    EXPECT_EQ(1, rules.Size());
    // First
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "ip_with_expiration", first.FindMember("type")->value.GetString());
    EXPECT_EQ(2, first.FindMember("data")->value.GetArray().Size());
}

TEST(RemoteConfigAsmDataListener,
    IfTwoEntriesWithTheSameIdHaveTheSameValueItGetsLatestExpirationOnDifferentSets)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "ip_with_expiration", {{11, "1.2.3.4"}}},
        {"id01", "ip_with_expiration", {{33, "1.2.3.4"}}},
        {"id01", "ip_with_expiration", {{22, "1.2.3.4"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));
    listener.commit();

    const auto &rules = doc["rules_data"];
    EXPECT_EQ(1, rules.Size());
    // First
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "ip_with_expiration", first.FindMember("type")->value.GetString());
    EXPECT_EQ(1, first.FindMember("data")->value.GetArray().Size());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ(
        "1.2.3.4", first_in_data.FindMember("value")->value.GetString());
    EXPECT_EQ(33, first_in_data.FindMember("expiration")->value.GetInt64());
}

TEST(RemoteConfigAsmDataListener,
    IfTwoEntriesWithTheSameIdHaveTheSameValueItGetsLatestExpirationInSameSet)
{
    std::vector<test_rule_data> rules_data = {{"id01", "ip_with_expiration",
        {{11, "1.2.3.4"}, {33, "1.2.3.4"}, {22, "1.2.3.4"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));
    listener.commit();

    const auto &rules = doc["rules_data"];
    EXPECT_EQ(1, rules.Size());
    // First
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "ip_with_expiration", first.FindMember("type")->value.GetString());
    EXPECT_EQ(1, first.FindMember("data")->value.GetArray().Size());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ(
        "1.2.3.4", first_in_data.FindMember("value")->value.GetString());
    EXPECT_EQ(33, first_in_data.FindMember("expiration")->value.GetInt64());
}

TEST(RemoteConfigAsmDataListener,
    NonExistingExpirationMeansItNeversExpireAndThereforeTakesPriority)
{
    std::vector<test_rule_data> rules_data = {{"id01", "ip_with_expiration",
        {{11, "1.2.3.4"}, {std::nullopt, "1.2.3.4"},
            {std::numeric_limits<uint64_t>::max(), "1.2.3.4"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));
    listener.commit();

    const auto &rules = doc["rules_data"];
    EXPECT_EQ(1, rules.Size());
    // First
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "ip_with_expiration", first.FindMember("type")->value.GetString());
    EXPECT_EQ(1, first.FindMember("data")->value.GetArray().Size());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ(
        "1.2.3.4", first_in_data.FindMember("value")->value.GetString());
    EXPECT_TRUE(
        first_in_data.FindMember("expiration") == first_in_data.MemberEnd());
}

TEST(RemoteConfigAsmDataListener, ParseMultipleConfigurations)
{
    auto engine = mock::engine::create();

    rapidjson::Document doc;

    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));

    remote_config::asm_data_listener listener(engine);

    {
        std::vector<test_rule_data> rules_data = {
            {"id01", "ip_with_expiration",
                {{11, "1.2.3.4"}, {3657529743, "5.6.7.8"}}},
            {"id02", "data_with_expiration", {{std::nullopt, "user1"}}}};

        listener.on_update(get_rules_data(rules_data));
    }

    {
        std::vector<test_rule_data> rules_data = {
            {"id01", "ip_with_expiration",
                {{0, "1.2.3.4"}, {5, "192.168.1.1"}}},
            {"id02", "data_with_expiration", {{2999, "user8"}}}};

        listener.on_update(get_rules_data(rules_data));
    }

    listener.commit();

    const auto &rules = doc["rules_data"];
    const auto &first = rules[0];
    EXPECT_STREQ("id02", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "data_with_expiration", first.FindMember("type")->value.GetString());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ("user1", first_in_data.FindMember("value")->value.GetString());
    EXPECT_TRUE(
        first_in_data.FindMember("expiration") == first_in_data.MemberEnd());

    const auto &second = rules[1];
    EXPECT_STREQ("id01", second.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "ip_with_expiration", second.FindMember("type")->value.GetString());
    {
        const auto &data = second.FindMember("data")->value.GetArray()[0];
        EXPECT_STREQ("1.2.3.4", data.FindMember("value")->value.GetString());
        EXPECT_EQ(0, data.FindMember("expiration")->value.GetUint64());
    }

    {
        const auto &data = second.FindMember("data")->value.GetArray()[1];
        EXPECT_STREQ(
            "192.168.1.1", data.FindMember("value")->value.GetString());
        EXPECT_EQ(5, data.FindMember("expiration")->value.GetUint64());
    }

    {
        const auto &data = second.FindMember("data")->value.GetArray()[2];
        EXPECT_STREQ("5.6.7.8", data.FindMember("value")->value.GetString());
        EXPECT_EQ(3657529743, data.FindMember("expiration")->value.GetUint64());
    }
}

TEST(RemoteConfigAsmDataListener, EmptyUpdate)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "ip_with_expiration", {}}};

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));
    remote_config::asm_data_listener listener(engine);
    listener.init();
    listener.commit();

    const auto &rules = doc["rules_data"];
    EXPECT_EQ(0, rules.Size());
}

TEST(RemoteConfigAsmDataListener, IfDataIsEmptyItDoesNotAddAnyRule)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "ip_with_expiration", {}}};

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));
    listener.commit();

    const auto &rules = doc["rules_data"];
    EXPECT_EQ(0, rules.Size());
}

TEST(RemoteConfigAsmDataListener, IgnoreUnknownRuleDataType)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "cidr_with_expiration",
            {{11, "1.2.3.0/24"}, {3657529743, "5.6.0.0/16"}}},
        {"id02", "data_with_expiration", {{std::nullopt, "user1"}}}};

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _))
        .Times(1)
        .WillOnce(DoAll(SaveDocument(&doc)));
    remote_config::asm_data_listener listener(engine);
    listener.on_update(get_rules_data(rules_data));
    listener.commit();

    const auto &rules = doc["rules_data"];
    EXPECT_TRUE(rules.IsArray());
    EXPECT_EQ(1, rules.Size());

    const auto &data = rules[0];
    EXPECT_TRUE(data.IsObject());

    const auto &it = data.FindMember("id");
    EXPECT_NE(it, data.MemberEnd());
    EXPECT_STREQ(it->value.GetString(), "id02");
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfContentNotInBase64)
{
    std::string invalid_content = "&&&";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config non_base_64_content_config =
        get_asm_data(invalid_content, false);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(non_base_64_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }

    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfContentNotValidJsonContent)
{
    std::string invalid_content = "InvalidJsonContent";
    std::string error_message = "";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config invalid_json_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_json_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfNoRulesDataKey)
{
    std::string invalid_content = "{\"another_key\": 1234}";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data key missing or "
        "invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfRulesDataNotArray)
{
    std::string invalid_content = "{ \"rules_data\": 1234}";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data key missing or "
        "invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfRulesDataEntryNotObject)
{
    std::string invalid_content = "{\"rules_data\": [\"invalid\"] }";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: rules_data entry invalid";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfNoId)
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
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfIdNotString)
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
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfNoType)
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
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfTypeNotString)
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
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfNoData)
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
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfDataNotArray)
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
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfDataEntryNotObject)
{
    std::string invalid_content =
        R"({"rules_data": [{"data": [ "invalid" ], "id": "some_id", "type": "ip_with_expiration"} ] })";
    std::string error_message = "";
    std::string expected_error_message =
        "Invalid config json contents: Entry on data not a valid object";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfDataExpirationHasInvalidType)
{
    std::string invalid_content =
        R"({"rules_data": [{"data": [{"expiration": "invalid", "value": "1.2.3.4"}], "id": "some_id", "type": "data_with_expiration"}]})";
    std::string error_message = "";
    std::string expected_error_message = "Invalid type for expiration entry";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfDataValueMissing)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11} ], \"id\": "
        "\"some_id\", \"type\": \"data_with_expiration\"} ] }";
    std::string error_message = "";
    std::string expected_error_message = "Invalid value of data entry";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ThrowsAnErrorIfDataValueHasInvalidType)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, "
        "\"value\": 1234} ], \"id\": \"some_id\", \"type\": "
        "\"ip_with_expiration\"} ] }";
    std::string error_message = "";
    std::string expected_error_message = "Invalid value of data entry";
    remote_config::config invalid_content_config =
        get_asm_data(invalid_content, true);

    auto engine = mock::engine::create();
    rapidjson::Document doc;
    EXPECT_CALL(*engine, update(_, _, _)).Times(0);
    remote_config::asm_data_listener listener(engine);

    try {
        listener.on_update(invalid_content_config);
    } catch (remote_config::error_applying_config &error) {
        error_message = error.what();
    }
    EXPECT_EQ(0, error_message.compare(0, expected_error_message.length(),
                     expected_error_message));
}

TEST(RemoteConfigAsmDataListener, ItUpdatesEngine)
{
    std::string ip = "1.2.3.4";
    const std::string waf_rule_with_data =
        R"({"version":"2.1","rules":[{"id":"blk-001-001","name":"Block IP Addresses","tags":{"type":"block_ip","category":"security_response"},"conditions":[{"parameters":{"inputs":[{"address":"http.client_ip"}],"data":"blocked_ips"},"operator":"ip_match"}],"transformers":[],"on_match":["block"]}]})";

    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;
    auto e{engine::create()};
    e->subscribe(waf::instance::from_string(waf_rule_with_data, meta, metrics));

    std::vector<test_rule_data> rules_data = {
        {"blocked_ips", "data_with_expiration", {{std::nullopt, ip}}}};

    remote_config::asm_data_listener listener(e);
    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string(ip));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    listener.on_update(get_rules_data(rules_data));
    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string(ip));

        auto res = ctx.publish(std::move(p));
        EXPECT_FALSE(res);
    }

    listener.commit();
    {
        auto ctx = e->get_context();

        auto p = parameter::map();
        p.add("http.client_ip", parameter::string(ip));

        auto res = ctx.publish(std::move(p));
        EXPECT_TRUE(res);
        EXPECT_EQ(res->type, engine::action_type::block);
        EXPECT_EQ(res->events.size(), 1);
    }
}

} // namespace dds::remote_config
