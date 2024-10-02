// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "../../../common.hpp"
#include "../../mocks.hpp"
#include "base64.h"
#include "json_helper.hpp"
#include "remote_config/exception.hpp"
#include "remote_config/listeners/config_aggregators/asm_data_aggregator.hpp"
#include <rapidjson/document.h>
#include <rapidjson/prettywriter.h>

namespace dds::remote_config {

using mock::get_config;

struct test_rule_data_data {
    std::optional<uint64_t> expiration;
    std::string value;
};

struct test_rule_data {
    std::string id;
    std::string type;
    std::vector<test_rule_data_data> data;
};

remote_config::config get_rules_data(std::vector<test_rule_data> data)
{
    rapidjson::Document document(rapidjson::kObjectType);
    rapidjson::Document::AllocatorType &alloc = document.GetAllocator();

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

    return get_config(known_products::ASM_DATA, buffer.get_string_ref());
}

TEST(RemoteConfigAsmDataAggregator, ParseRulesData)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "ip_with_expiration",
            {{11, "1.2.3.4"}, {3657529743, "5.6.7.8"}}},
        {"id02", "data_with_expiration", {{std::nullopt, "user1"}}}};

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_rules_data(rules_data));
    aggregator.aggregate(doc);

    const auto &rules = doc["rules_data"];
    const auto &first = rules[1];
    EXPECT_STREQ("id02", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "data_with_expiration", first.FindMember("type")->value.GetString());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ("user1", first_in_data.FindMember("value")->value.GetString());
    EXPECT_TRUE(
        first_in_data.FindMember("expiration") == first_in_data.MemberEnd());

    const auto &second = rules[0];
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

TEST(RemoteConfigAsmDataAggregator, ItMergesValuesWhenIdIsTheSame)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "ip_with_expiration", {{11, "1.2.3.4"}}},
        {"id01", "ip_with_expiration", {{22, "5.6.7.8"}}}};

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_rules_data(rules_data));
    aggregator.aggregate(doc);

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

TEST(RemoteConfigAsmDataAggregator, WhenIdMatchesTypeIsSecondTypeIsIgnored)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "ip_with_expiration", {{11, "1.2.3.4"}}},
        {"id01", "data_with_expiration", {{22, "5.6.7.8"}}}};

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_rules_data(rules_data));
    aggregator.aggregate(doc);

    const auto &rules = doc["rules_data"];
    EXPECT_EQ(1, rules.Size());
    // First
    const auto &first = rules[0];
    EXPECT_STREQ("id01", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "ip_with_expiration", first.FindMember("type")->value.GetString());
    EXPECT_EQ(2, first.FindMember("data")->value.GetArray().Size());
}

TEST(RemoteConfigAsmDataAggregator,
    IfTwoEntriesWithTheSameIdHaveTheSameValueItGetsLatestExpirationOnDifferentSets)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "ip_with_expiration", {{11, "1.2.3.4"}}},
        {"id01", "ip_with_expiration", {{33, "1.2.3.4"}}},
        {"id01", "ip_with_expiration", {{22, "1.2.3.4"}}}};

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_rules_data(rules_data));
    aggregator.aggregate(doc);

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

TEST(RemoteConfigAsmDataAggregator,
    IfTwoEntriesWithTheSameIdHaveTheSameValueItGetsLatestExpirationInSameSet)
{
    std::vector<test_rule_data> rules_data = {{"id01", "ip_with_expiration",
        {{11, "1.2.3.4"}, {33, "1.2.3.4"}, {22, "1.2.3.4"}}}};

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_rules_data(rules_data));
    aggregator.aggregate(doc);

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

TEST(RemoteConfigAsmDataAggregator,
    NonExistingExpirationMeansItNeversExpireAndThereforeTakesPriority)
{
    std::vector<test_rule_data> rules_data = {{"id01", "ip_with_expiration",
        {{11, "1.2.3.4"}, {std::nullopt, "1.2.3.4"},
            {std::numeric_limits<uint64_t>::max(), "1.2.3.4"}}}};

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_rules_data(rules_data));
    aggregator.aggregate(doc);

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

TEST(RemoteConfigAsmDataAggregator, ParseMultipleConfigurations)
{
    auto engine = mock::engine::create();

    rapidjson::Document doc(rapidjson::kObjectType);

    remote_config::asm_data_aggregator aggregator;

    aggregator.init(&doc.GetAllocator());

    {
        std::vector<test_rule_data> rules_data = {
            {"id01", "ip_with_expiration",
                {{11, "1.2.3.4"}, {3657529743, "5.6.7.8"}}},
            {"id02", "data_with_expiration", {{std::nullopt, "user1"}}}};

        aggregator.add(get_rules_data(rules_data));
    }

    {
        std::vector<test_rule_data> rules_data = {
            {"id01", "ip_with_expiration",
                {{0, "1.2.3.4"}, {5, "192.168.1.1"}}},
            {"id02", "data_with_expiration", {{2999, "user8"}}}};

        aggregator.add(get_rules_data(rules_data));
    }

    aggregator.aggregate(doc);

    const auto &rules = doc["rules_data"];
    const auto &first = rules[1];
    EXPECT_STREQ("id02", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "data_with_expiration", first.FindMember("type")->value.GetString());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ("user1", first_in_data.FindMember("value")->value.GetString());
    EXPECT_TRUE(
        first_in_data.FindMember("expiration") == first_in_data.MemberEnd());

    const auto &second = rules[0];
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

TEST(RemoteConfigAsmDataAggregator, EmptyUpdate)
{
    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.aggregate(doc);

    const auto &rules = doc["rules_data"];
    EXPECT_EQ(0, rules.Size());
}

TEST(RemoteConfigAsmDataAggregator, IgnoreInvalidConfigs)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "ip_with_expiration",
            {{11, "1.2.3.4"}, {3657529743, "5.6.7.8"}}},
        {"id02", "data_with_expiration", {{std::nullopt, "user1"}}}};

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_rules_data(rules_data));
    {
        const std::string &invalid =
            R"({"rules_data": [{"id": "id01", "data": [{"expiration": 11, "value": "1.2.3.5"} ], "type": "ip_with_expiration"},{"data": [{"expiration": 11111, "value": "1.2.3.4"} ], "type": "ip_with_expiration"}]})";
        EXPECT_THROW(
            aggregator.add(get_config(known_products::ASM_DATA, invalid)),
            remote_config::error_applying_config);
    }
    aggregator.aggregate(doc);

    const auto &rules = doc["rules_data"];
    const auto &first = rules[1];
    EXPECT_STREQ("id02", first.FindMember("id")->value.GetString());
    EXPECT_STREQ(
        "data_with_expiration", first.FindMember("type")->value.GetString());
    const auto &first_in_data = first.FindMember("data")->value.GetArray()[0];
    EXPECT_STREQ("user1", first_in_data.FindMember("value")->value.GetString());
    EXPECT_TRUE(
        first_in_data.FindMember("expiration") == first_in_data.MemberEnd());

    const auto &second = rules[0];
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

TEST(RemoteConfigAsmDataAggregator, IfDataIsEmptyItDoesNotAddAnyRule)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "ip_with_expiration", {}}};

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_rules_data(rules_data));
    aggregator.aggregate(doc);

    const auto &rules = doc["rules_data"];
    EXPECT_EQ(0, rules.Size());
}

TEST(RemoteConfigAsmDataAggregator, IgnoreUnknownRuleDataType)
{
    std::vector<test_rule_data> rules_data = {
        {"id01", "cidr_with_expiration",
            {{11, "1.2.3.0/24"}, {3657529743, "5.6.0.0/16"}}},
        {"id02", "data_with_expiration", {{std::nullopt, "user1"}}}};

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());
    aggregator.add(get_rules_data(rules_data));
    aggregator.aggregate(doc);

    const auto &rules = doc["rules_data"];
    EXPECT_TRUE(rules.IsArray());
    EXPECT_EQ(1, rules.Size());

    const auto &data = rules[0];
    EXPECT_TRUE(data.IsObject());

    const auto &it = data.FindMember("id");
    EXPECT_NE(it, data.MemberEnd());
    EXPECT_STREQ(it->value.GetString(), "id02");
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfContentNotInBase64)
{
    std::string invalid_content = "&&&";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfContentNotValidJsonContent)
{
    std::string invalid_content = "InvalidJsonContent";
    std::string expected_error_message = "Invalid config contents";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfNoRulesDataKey)
{
    std::string invalid_content = "{\"another_key\": 1234}";
    std::string expected_error_message =
        "Invalid config json contents: rules_data key missing or "
        "invalid";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfRulesDataNotArray)
{
    std::string invalid_content = "{ \"rules_data\": 1234}";
    std::string expected_error_message =
        "Invalid config json contents: rules_data key missing or "
        "invalid";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfRulesDataEntryNotObject)
{
    std::string invalid_content = "{\"rules_data\": [\"invalid\"] }";
    std::string expected_error_message =
        "Invalid config json contents: rules_data entry invalid";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfNoId)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, \"value\": "
        "\"1.2.3.4\"} ], \"type\": \"some_type\"} ] }";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfIdNotString)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, \"value\": "
        "\"1.2.3.4\"} ], \"id\": 1234, \"type\": \"some_type\"} ] }";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfNoType)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, \"value\": "
        "\"1.2.3.4\"} ], \"id\": \"some_id\"} ] }";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfTypeNotString)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, \"value\": "
        "\"1.2.3.4\"} ], \"type\": 1234, \"id\": \"some_id\"} ] }";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfNoData)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"id\": \"some_id\", \"type\": \"some_type\"} ]}";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfDataNotArray)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": 1234, \"id\":\"some_id\", \"type\": "
        "\"some_type\"} ]}";
    std::string expected_error_message =
        "Invalid config json contents: rules_data missing a field or "
        "field is invalid";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfDataEntryNotObject)
{
    std::string invalid_content =
        R"({"rules_data": [{"data": [ "invalid" ], "id": "some_id", "type": "ip_with_expiration"} ] })";
    std::string expected_error_message =
        "Invalid config json contents: Entry on data not a valid object";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfDataExpirationHasInvalidType)
{
    std::string invalid_content =
        R"({"rules_data": [{"data": [{"expiration": "invalid", "value": "1.2.3.4"}], "id": "some_id", "type": "data_with_expiration"}]})";
    std::string expected_error_message = "Invalid type for expiration entry";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfDataValueMissing)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11} ], \"id\": "
        "\"some_id\", \"type\": \"data_with_expiration\"} ] }";
    std::string expected_error_message = "Invalid value of data entry";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

TEST(RemoteConfigAsmDataAggregator, ThrowsAnErrorIfDataValueHasInvalidType)
{
    std::string invalid_content =
        "{\"rules_data\": [{\"data\": [{\"expiration\": 11, "
        "\"value\": 1234} ], \"id\": \"some_id\", \"type\": "
        "\"ip_with_expiration\"} ] }";
    std::string expected_error_message = "Invalid value of data entry";
    remote_config::config config =
        get_config(known_products::ASM_DATA, invalid_content);

    remote_config::asm_data_aggregator aggregator;

    rapidjson::Document doc(rapidjson::kObjectType);
    aggregator.init(&doc.GetAllocator());

    EXPECT_THROW(
        try { aggregator.add(config); } catch (
            remote_config::error_applying_config &error) {
            std::string error_message = error.what();
            EXPECT_EQ(
                0, error_message.compare(0, expected_error_message.length(),
                       expected_error_message));
            throw;
        },
        remote_config::error_applying_config);
}

} // namespace dds::remote_config
