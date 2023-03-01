// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include <rapidjson/document.h>
#include <string>

#include "../common.hpp"
#include "base64.h"
#include "remote_config/protocol/client.hpp"
#include "remote_config/protocol/client_state.hpp"
#include "remote_config/protocol/client_tracer.hpp"
#include "remote_config/protocol/config_state.hpp"
#include "remote_config/protocol/tuf/get_configs_request.hpp"
#include "remote_config/protocol/tuf/serializer.hpp"

namespace dds {

bool array_contains_string(const rapidjson::Value &array, const char *searched)
{
    if (!array.IsArray()) {
        return false;
    }

    bool result = false;
    for (rapidjson::Value::ConstValueIterator itr = array.Begin();
         itr != array.End(); ++itr) {
        if (itr->IsString() && strcmp(searched, itr->GetString()) == 0) {
            result = true;
        }
    }

    return result;
}

rapidjson::Value::ConstMemberIterator assert_it_contains_string(
    const rapidjson::Value &parent_field, const char *key, const char *value)
{
    rapidjson::Value::ConstMemberIterator tmp_itr =
        parent_field.FindMember(key);
    bool found = false;
    if (tmp_itr != parent_field.MemberEnd()) {
        found = true;
    }
    EXPECT_TRUE(found) << "Key " << key << " not found";
    EXPECT_EQ(rapidjson::kStringType, tmp_itr->value.GetType());
    EXPECT_EQ(value, tmp_itr->value);

    return tmp_itr;
}

rapidjson::Value::ConstMemberIterator assert_it_contains_int(
    const rapidjson::Value &parent_field, const char *key, int value)
{
    rapidjson::Value::ConstMemberIterator tmp_itr =
        parent_field.FindMember(key);
    bool found = false;
    if (tmp_itr != parent_field.MemberEnd()) {
        found = true;
    }
    EXPECT_TRUE(found) << "Key " << key << " not found";
    EXPECT_EQ(rapidjson::kNumberType, tmp_itr->value.GetType());
    EXPECT_EQ(value, tmp_itr->value);

    return tmp_itr;
}

rapidjson::Value::ConstMemberIterator assert_it_contains_bool(
    const rapidjson::Value &parent_field, const char *key, bool value)
{
    rapidjson::Value::ConstMemberIterator tmp_itr =
        parent_field.FindMember(key);
    bool found = false;
    if (tmp_itr != parent_field.MemberEnd()) {
        found = true;
    }
    EXPECT_TRUE(found) << "Key " << key << " not found";
    rapidjson::Type type = rapidjson::kTrueType;
    if (!value) {
        type = rapidjson::kFalseType;
    }
    EXPECT_EQ(type, tmp_itr->value.GetType());
    EXPECT_EQ(value, tmp_itr->value);

    return tmp_itr;
}

rapidjson::Value::ConstMemberIterator find_and_assert_type(
    const rapidjson::Value &parent_field, const char *key, rapidjson::Type type)
{
    rapidjson::Value::ConstMemberIterator tmp_itr =
        parent_field.FindMember(key);
    bool found = false;
    if (tmp_itr != parent_field.MemberEnd()) {
        found = true;
    }
    EXPECT_TRUE(found) << "Key " << key << " not found";
    EXPECT_EQ(type, tmp_itr->value.GetType())
        << "Key " << key << " not matching expected type";

    return tmp_itr;
}

int config_state_version = 456;
int targets_version = 123;

remote_config::protocol::client get_client()
{
    remote_config::protocol::client_tracer client_tracer = {"some runtime id",
        "some tracer version", "some service", "some env", "some app version"};

    std::vector<remote_config::protocol::config_state> config_states;

    remote_config::protocol::config_state cs_unknown = {
        "unknown config_state id", 11, "unknown config_state product",
        remote_config::protocol::config_state::applied_state::UNKNOWN, ""};
    remote_config::protocol::config_state cs_unacknowledged = {
        "unacknowledged config_state id", 22,
        "unacknowledged config_state product",
        remote_config::protocol::config_state::applied_state::UNACKNOWLEDGED,
        ""};
    remote_config::protocol::config_state cs_acknowledged = {
        "acknowledged config_state id", 33, "acknowledged config_state product",
        remote_config::protocol::config_state::applied_state::ACKNOWLEDGED, ""};
    remote_config::protocol::config_state cs_error = {"error config_state id",
        44, "error config_state product",
        remote_config::protocol::config_state::applied_state::ERROR,
        "error description"};
    config_states.push_back(cs_unknown);
    config_states.push_back(cs_unacknowledged);
    config_states.push_back(cs_acknowledged);
    config_states.push_back(cs_error);

    remote_config::protocol::client_state client_s = {
        targets_version, config_states, false, "", "some backend client state"};

    return {"some_id", {"ASM_DD"}, client_tracer, client_s};
}

std::vector<remote_config::protocol::cached_target_files>
get_cached_target_files()
{
    std::vector<remote_config::protocol::cached_target_files>
        cached_target_files;

    std::vector<remote_config::protocol::cached_target_files_hash> first_hashes;
    remote_config::protocol::cached_target_files_hash first_hash{
        "first hash algorithm", "first hash hash"};
    first_hashes.push_back(first_hash);
    remote_config::protocol::cached_target_files first{
        "first some path", 1, std::move(first_hashes)};
    cached_target_files.push_back(first);

    std::vector<remote_config::protocol::cached_target_files_hash>
        second_hashes;
    remote_config::protocol::cached_target_files_hash second_hash{
        "second hash algorithm", "second hash hash"};
    second_hashes.push_back(second_hash);
    remote_config::protocol::cached_target_files second{
        "second some path", 1, std::move(second_hashes)};
    cached_target_files.push_back(second);

    return cached_target_files;
}

TEST(RemoteConfigSerializer, RequestCanBeSerializedWithClientField)
{
    remote_config::protocol::get_configs_request request = {
        get_client(), get_cached_target_files()};

    std::optional<std::string> serialised_string;
    serialised_string = remote_config::protocol::serialize(std::move(request));

    EXPECT_TRUE(serialised_string);

    // Lets transform the resulting string back to json so we can assert more
    // easily
    rapidjson::Document serialized_doc;
    serialized_doc.Parse(serialised_string.value());

    // Client fields
    rapidjson::Value::ConstMemberIterator client_itr =
        find_and_assert_type(serialized_doc, "client", rapidjson::kObjectType);

    assert_it_contains_string(client_itr->value, "id", "some_id");
    assert_it_contains_bool(client_itr->value, "is_tracer", true);

    // Client products fields
    rapidjson::Value::ConstMemberIterator products_itr = find_and_assert_type(
        client_itr->value, "products", rapidjson::kArrayType);
    array_contains_string(products_itr->value, "ASM_DD");

    // Client tracer fields
    rapidjson::Value::ConstMemberIterator client_tracer_itr =
        find_and_assert_type(
            client_itr->value, "client_tracer", rapidjson::kObjectType);
    assert_it_contains_string(client_tracer_itr->value, "language", "php");
    assert_it_contains_string(
        client_tracer_itr->value, "runtime_id", "some runtime id");
    assert_it_contains_string(
        client_tracer_itr->value, "tracer_version", "some tracer version");
    assert_it_contains_string(
        client_tracer_itr->value, "service", "some service");
    assert_it_contains_string(client_tracer_itr->value, "env", "some env");
    assert_it_contains_string(
        client_tracer_itr->value, "app_version", "some app version");

    // Client state fields
    rapidjson::Value::ConstMemberIterator client_state_itr =
        find_and_assert_type(
            client_itr->value, "state", rapidjson::kObjectType);
    assert_it_contains_int(
        client_state_itr->value, "targets_version", targets_version);
    assert_it_contains_int(client_state_itr->value, "root_version", 1);
    assert_it_contains_bool(client_state_itr->value, "has_error", false);
    assert_it_contains_string(client_state_itr->value, "error", "");
    assert_it_contains_string(client_state_itr->value, "backend_client_state",
        "some backend client state");

    // Config state fields
    rapidjson::Value::ConstMemberIterator config_states_itr =
        find_and_assert_type(
            client_state_itr->value, "config_states", rapidjson::kArrayType);
    ;

    // UNKNOWN
    rapidjson::Value::ConstValueIterator itr = config_states_itr->value.Begin();
    assert_it_contains_string(*itr, "id", "unknown config_state id");
    assert_it_contains_int(*itr, "version", 11);
    assert_it_contains_string(*itr, "product", "unknown config_state product");
    assert_it_contains_int(*itr, "apply_state", 0);
    assert_it_contains_string(*itr, "apply_error", "");
    // UNACKNOWLEDGED
    itr++;
    assert_it_contains_string(*itr, "id", "unacknowledged config_state id");
    assert_it_contains_int(*itr, "version", 22);
    assert_it_contains_string(
        *itr, "product", "unacknowledged config_state product");
    assert_it_contains_int(*itr, "apply_state", 1);
    assert_it_contains_string(*itr, "apply_error", "");
    // ACKNOWLEDGED
    itr++;
    assert_it_contains_string(*itr, "id", "acknowledged config_state id");
    assert_it_contains_int(*itr, "version", 33);
    assert_it_contains_string(
        *itr, "product", "acknowledged config_state product");
    assert_it_contains_int(*itr, "apply_state", 2);
    assert_it_contains_string(*itr, "apply_error", "");
    // ERROR
    itr++;
    assert_it_contains_string(*itr, "id", "error config_state id");
    assert_it_contains_int(*itr, "version", 44);
    assert_it_contains_string(*itr, "product", "error config_state product");
    assert_it_contains_int(*itr, "apply_state", 3);
    assert_it_contains_string(*itr, "apply_error", "error description");
}

TEST(RemoteConfigSerializer, RequestCanBeSerializedWithCachedTargetFields)
{
    remote_config::protocol::get_configs_request request = {
        get_client(), get_cached_target_files()};

    std::optional<std::string> serialised_string;
    serialised_string = remote_config::protocol::serialize(std::move(request));

    EXPECT_TRUE(serialised_string);

    // Lets transform the resulting string back to json so we can assert more
    // easily
    rapidjson::Document serialized_doc;
    serialized_doc.Parse(serialised_string.value());

    // cached_target_files fields
    rapidjson::Value::ConstMemberIterator cached_target_files_itr =
        find_and_assert_type(
            serialized_doc, "cached_target_files", rapidjson::kArrayType);

    EXPECT_EQ(2, cached_target_files_itr->value.Size());

    rapidjson::Value::ConstValueIterator first =
        cached_target_files_itr->value.Begin();
    assert_it_contains_string(*first, "path", "first some path");
    assert_it_contains_int(*first, "length", 1);

    // Cached target file hash of first
    rapidjson::Value::ConstMemberIterator first_cached_target_files_hash =
        find_and_assert_type(*first, "hashes", rapidjson::kArrayType);
    EXPECT_EQ(1, first_cached_target_files_hash->value.Size());
    assert_it_contains_string(*first_cached_target_files_hash->value.Begin(),
        "algorithm", "first hash algorithm");
    assert_it_contains_string(*first_cached_target_files_hash->value.Begin(),
        "hash", "first hash hash");

    rapidjson::Value::ConstValueIterator second =
        std::next(cached_target_files_itr->value.Begin());
    assert_it_contains_string(*second, "path", "second some path");
    assert_it_contains_int(*second, "length", 1);

    // Cached target file hash of second
    rapidjson::Value::ConstMemberIterator second_cached_target_files_hash =
        find_and_assert_type(*second, "hashes", rapidjson::kArrayType);
    EXPECT_EQ(1, second_cached_target_files_hash->value.Size());
    assert_it_contains_string(*second_cached_target_files_hash->value.Begin(),
        "algorithm", "second hash algorithm");
    assert_it_contains_string(*second_cached_target_files_hash->value.Begin(),
        "hash", "second hash hash");
}

TEST(RemoteConfigSerializer, CapabilitiesCanBeSet)
{
    auto client = get_client();
    client.set_capabilities({remote_config::protocol::capabilities_e::RESERVED,
        remote_config::protocol::capabilities_e::ASM_ACTIVATION,
        remote_config::protocol::capabilities_e::ASM_IP_BLOCKING,
        remote_config::protocol::capabilities_e::ASM_DD_RULES});

    remote_config::protocol::get_configs_request request = {
        client, get_cached_target_files()};

    std::optional<std::string> serialised_string;
    serialised_string = remote_config::protocol::serialize(std::move(request));

    EXPECT_TRUE(serialised_string);

    // Lets transform the resulting string back to json so we can assert more
    // easily
    rapidjson::Document serialized_doc;
    serialized_doc.Parse(serialised_string.value());

    // Client fields
    rapidjson::Value::ConstMemberIterator client_itr =
        find_and_assert_type(serialized_doc, "client", rapidjson::kObjectType);

    rapidjson::Value::ConstMemberIterator capabilities_itr =
        find_and_assert_type(
            client_itr->value, "capabilities", rapidjson::kStringType);

    EXPECT_STREQ("DwA=", capabilities_itr->value.GetString());
}

} // namespace dds
