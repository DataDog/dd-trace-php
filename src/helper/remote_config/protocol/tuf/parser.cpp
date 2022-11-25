// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include <iostream>
#include <rapidjson/document.h>
#include <rapidjson/prettywriter.h>

#include "parser.hpp"
#include <base64.h>

using namespace std::literals;

namespace dds::remote_config::protocol {

bool validate_field_is_present(const rapidjson::Value &parent_field,
    const char *key, rapidjson::Type type,
    rapidjson::Value::ConstMemberIterator &output_itr,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    const remote_config_parser_result missing,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    const remote_config_parser_result invalid)
{
    output_itr = parent_field.FindMember(key);

    if (output_itr == parent_field.MemberEnd()) {
        throw parser_exception(missing);
    }

    if (type == output_itr->value.GetType()) {
        return true;
    }

    throw parser_exception(invalid);
}

bool validate_field_is_present(
    rapidjson::Value::ConstMemberIterator &parent_field, const char *key,
    rapidjson::Type type, rapidjson::Value::ConstMemberIterator &output_itr,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    const remote_config_parser_result &missing,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    const remote_config_parser_result &invalid)
{
    output_itr = parent_field->value.FindMember(key);

    if (output_itr == parent_field->value.MemberEnd()) {
        throw parser_exception(missing);
    }

    if (type == output_itr->value.GetType()) {
        return true;
    }

    throw parser_exception(invalid);
}

std::unordered_map<std::string, target_file> parse_target_files(
    rapidjson::Value::ConstMemberIterator target_files_itr)
{
    std::unordered_map<std::string, target_file> result;
    for (rapidjson::Value::ConstValueIterator itr =
             target_files_itr->value.Begin();
         itr != target_files_itr->value.End(); ++itr) {
        if (!itr->IsObject()) {
            throw parser_exception(
                remote_config_parser_result::target_files_object_invalid);
        }

        // Path checks
        rapidjson::Value::ConstMemberIterator path_itr =
            itr->GetObject().FindMember("path");
        if (path_itr == itr->GetObject().MemberEnd()) {
            throw parser_exception(
                remote_config_parser_result::target_files_path_field_missing);
        }
        if (!path_itr->value.IsString()) {
            throw parser_exception(remote_config_parser_result::
                    target_files_path_field_invalid_type);
        }

        // Raw checks
        rapidjson::Value::ConstMemberIterator raw_itr =
            itr->GetObject().FindMember("raw");
        if (raw_itr == itr->GetObject().MemberEnd()) {
            throw parser_exception(
                remote_config_parser_result::target_files_raw_field_missing);
        }
        if (!raw_itr->value.IsString()) {
            throw parser_exception(remote_config_parser_result::
                    target_files_raw_field_invalid_type);
        }
        result.insert({path_itr->value.GetString(),
            {path_itr->value.GetString(), raw_itr->value.GetString()}});
    }

    return result;
}

std::vector<std::string> parse_client_configs(
    rapidjson::Value::ConstMemberIterator client_configs_itr)
{
    std::vector<std::string> result;
    for (rapidjson::Value::ConstValueIterator itr =
             client_configs_itr->value.Begin();
         itr != client_configs_itr->value.End(); ++itr) {
        if (!itr->IsString()) {
            throw parser_exception(
                remote_config_parser_result::client_config_field_invalid_entry);
        }

        result.emplace_back(itr->GetString());
    }

    return result;
}

std::pair<std::string, path> parse_target(
    rapidjson::Value::ConstMemberIterator target_itr)
{
    rapidjson::Value::ConstMemberIterator custom_itr;
    validate_field_is_present(target_itr, "custom", rapidjson::kObjectType,
        custom_itr,
        remote_config_parser_result::custom_path_targets_field_missing,
        remote_config_parser_result::custom_path_targets_field_invalid);
    rapidjson::Value::ConstMemberIterator v_itr;
    validate_field_is_present(custom_itr, "v", rapidjson::kNumberType, v_itr,
        remote_config_parser_result::v_path_targets_field_missing,
        remote_config_parser_result::v_path_targets_field_invalid);

    rapidjson::Value::ConstMemberIterator hashes_itr;
    validate_field_is_present(target_itr, "hashes", rapidjson::kObjectType,
        hashes_itr,
        remote_config_parser_result::hashes_path_targets_field_missing,
        remote_config_parser_result::hashes_path_targets_field_invalid);

    std::unordered_map<std::string, std::string> hashes_mapped;
    auto hashes_object = hashes_itr->value.GetObject();
    for (rapidjson::Value::ConstMemberIterator itr =
             hashes_object.MemberBegin();
         itr != hashes_object.MemberEnd(); ++itr) {
        if (itr->value.GetType() != rapidjson::kStringType) {
            throw parser_exception(remote_config_parser_result::
                    hash_hashes_path_targets_field_invalid);
        }

        std::pair<std::string, std::string> hash_pair(
            itr->name.GetString(), itr->value.GetString());
        hashes_mapped.insert(hash_pair);
    }

    if (hashes_mapped.empty()) {
        throw parser_exception(
            remote_config_parser_result::hashes_path_targets_field_empty);
    }

    rapidjson::Value::ConstMemberIterator length_itr;
    validate_field_is_present(target_itr, "length", rapidjson::kNumberType,
        length_itr,
        remote_config_parser_result::length_path_targets_field_missing,
        remote_config_parser_result::length_path_targets_field_invalid);

    std::string target_name(target_itr->name.GetString());
    path path_object = {
        v_itr->value.GetInt(), hashes_mapped, length_itr->value.GetInt()};

    return {target_name, path_object};
}

targets parse_targets_signed(
    rapidjson::Value::ConstMemberIterator targets_signed_itr)
{
    rapidjson::Value::ConstMemberIterator version_itr;
    validate_field_is_present(targets_signed_itr, "version",
        rapidjson::kNumberType, version_itr,
        remote_config_parser_result::version_signed_targets_field_missing,
        remote_config_parser_result::version_signed_targets_field_invalid);

    rapidjson::Value::ConstMemberIterator targets_itr;
    validate_field_is_present(targets_signed_itr, "targets",
        rapidjson::kObjectType, targets_itr,
        remote_config_parser_result::targets_signed_targets_field_missing,
        remote_config_parser_result::targets_signed_targets_field_invalid);

    std::vector<std::pair<std::string, path>> paths;
    for (rapidjson::Value::ConstMemberIterator current_target =
             targets_itr->value.MemberBegin();
         current_target != targets_itr->value.MemberEnd(); ++current_target) {
        auto path = parse_target(current_target);
        paths.push_back(path);
    }

    rapidjson::Value::ConstMemberIterator type_itr;
    validate_field_is_present(targets_signed_itr, "_type",
        rapidjson::kStringType, type_itr,
        remote_config_parser_result::type_signed_targets_field_missing,
        remote_config_parser_result::type_signed_targets_field_invalid);
    if ("targets"sv != type_itr->value.GetString()) {
        throw parser_exception(remote_config_parser_result::
                type_signed_targets_field_invalid_type);
    }

    rapidjson::Value::ConstMemberIterator custom_itr;
    validate_field_is_present(targets_signed_itr, "custom",
        rapidjson::kObjectType, custom_itr,
        remote_config_parser_result::custom_signed_targets_field_missing,
        remote_config_parser_result::custom_signed_targets_field_invalid);

    rapidjson::Value::ConstMemberIterator opaque_backend_state_itr;
    validate_field_is_present(custom_itr, "opaque_backend_state",
        rapidjson::kStringType, opaque_backend_state_itr,
        remote_config_parser_result::obs_custom_signed_targets_field_missing,
        remote_config_parser_result::obs_custom_signed_targets_field_invalid);
    std::unordered_map<std::string, path> final_paths;
    for (auto &[path_str, path] : paths) {
        final_paths.emplace(path_str, path);
    }
    return {version_itr->value.GetInt(),
        opaque_backend_state_itr->value.GetString(), final_paths};
}

targets parse_targets(rapidjson::Value::ConstMemberIterator targets_itr)
{
    std::string targets_encoded_content = targets_itr->value.GetString();

    if (targets_encoded_content.empty()) {
        throw parser_exception(
            remote_config_parser_result::targets_field_empty);
    }

    std::string base64_decoded;
    try {
        base64_decoded = base64_decode(targets_encoded_content, true);
    } catch (std::runtime_error &error) {
        throw parser_exception(
            remote_config_parser_result::targets_field_invalid_base64);
    }

    rapidjson::Document serialized_doc;
    if (serialized_doc.Parse(base64_decoded).HasParseError()) {
        throw parser_exception(
            remote_config_parser_result::targets_field_invalid_json);
    }

    rapidjson::Value::ConstMemberIterator signed_itr;

    // Lets validate the data and since we are there we get the iterators
    validate_field_is_present(serialized_doc, "signed", rapidjson::kObjectType,
        signed_itr, remote_config_parser_result::signed_targets_field_missing,
        remote_config_parser_result::signed_targets_field_invalid);

    return parse_targets_signed(signed_itr);
}

get_configs_response parse(const std::string &body)
{
    rapidjson::Document serialized_doc;
    if (serialized_doc.Parse(body).HasParseError()) {
        throw parser_exception(remote_config_parser_result::invalid_json);
    }

    rapidjson::Value::ConstMemberIterator target_files_itr;
    rapidjson::Value::ConstMemberIterator client_configs_itr;
    rapidjson::Value::ConstMemberIterator targets_itr;

    // Lets validate the data and since we are there we get the iterators
    validate_field_is_present(serialized_doc, "target_files",
        rapidjson::kArrayType, target_files_itr,
        remote_config_parser_result::target_files_field_missing,
        remote_config_parser_result::target_files_field_invalid_type);

    validate_field_is_present(serialized_doc, "client_configs",
        rapidjson::kArrayType, client_configs_itr,
        remote_config_parser_result::client_config_field_missing,
        remote_config_parser_result::client_config_field_invalid_type);

    validate_field_is_present(serialized_doc, "targets", rapidjson::kStringType,
        targets_itr, remote_config_parser_result::targets_field_missing,
        remote_config_parser_result::targets_field_invalid_type);

    const std::unordered_map<std::string, target_file> &target_files =
        parse_target_files(target_files_itr);
    const std::vector<std::string> &client_configs =
        parse_client_configs(client_configs_itr);
    const targets &targets = parse_targets(targets_itr);

    return {target_files, client_configs, targets};
}
// NOLINTNEXTLINE(cppcoreguidelines-macro-usage)
#define RESULT_AS_STR(entry) #entry,
namespace {
constexpr std::array<std::string_view,
    (size_t)remote_config_parser_result::num_of_values>
    results_as_str = {PARSER_RESULTS(RESULT_AS_STR)};
} // anonymous namespace
std::string_view remote_config_parser_result_to_str(
    const remote_config_parser_result &result)
{
    if (result == remote_config_parser_result::num_of_values) {
        return "";
    }
    // NOLINTNEXTLINE(cppcoreguidelines-pro-bounds-constant-array-index)
    return results_as_str[(size_t)result];
}

} // namespace dds::remote_config::protocol
