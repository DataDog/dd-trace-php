// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <array>
#include <rapidjson/document.h>
#include <string>

#include "get_configs_response.hpp"

namespace dds::remote_config::protocol {
// NOLINTNEXTLINE(cppcoreguidelines-macro-usage)
#define PARSER_RESULTS(X)                                                      \
    X(success)                                                                 \
    X(invalid_json)                                                            \
    X(targets_field_empty)                                                     \
    X(targets_field_invalid_base64)                                            \
    X(targets_field_invalid_json)                                              \
    X(targets_field_missing)                                                   \
    X(targets_field_invalid_type)                                              \
    X(signed_targets_field_invalid)                                            \
    X(signed_targets_field_missing)                                            \
    X(type_signed_targets_field_invalid)                                       \
    X(type_signed_targets_field_invalid_type)                                  \
    X(type_signed_targets_field_missing)                                       \
    X(version_signed_targets_field_invalid)                                    \
    X(version_signed_targets_field_missing)                                    \
    X(custom_signed_targets_field_invalid)                                     \
    X(custom_signed_targets_field_missing)                                     \
    X(obs_custom_signed_targets_field_invalid)                                 \
    X(obs_custom_signed_targets_field_missing)                                 \
    X(target_files_field_missing)                                              \
    X(target_files_object_invalid)                                             \
    X(target_files_field_invalid_type)                                         \
    X(target_files_path_field_missing)                                         \
    X(target_files_path_field_invalid_type)                                    \
    X(target_files_raw_field_missing)                                          \
    X(target_files_raw_field_invalid_type)                                     \
    X(client_config_field_missing)                                             \
    X(client_config_field_invalid_type)                                        \
    X(client_config_field_invalid_entry)                                       \
    X(targets_signed_targets_field_invalid)                                    \
    X(targets_signed_targets_field_missing)                                    \
    X(custom_path_targets_field_invalid)                                       \
    X(custom_path_targets_field_missing)                                       \
    X(v_path_targets_field_invalid)                                            \
    X(v_path_targets_field_missing)                                            \
    X(hashes_path_targets_field_invalid)                                       \
    X(hashes_path_targets_field_missing)                                       \
    X(hashes_path_targets_field_empty)                                         \
    X(hash_hashes_path_targets_field_invalid)                                  \
    X(length_path_targets_field_invalid)                                       \
    X(length_path_targets_field_missing)

// NOLINTNEXTLINE(cppcoreguidelines-macro-usage)
#define RESULT_AS_ENUM_ENTRY(entry) entry,
enum class remote_config_parser_result : size_t {
    PARSER_RESULTS(RESULT_AS_ENUM_ENTRY) num_of_values
};

std::string_view remote_config_parser_result_to_str(
    const remote_config_parser_result &result);

class parser_exception : public std::exception {
public:
    explicit parser_exception(remote_config_parser_result error)
        : message_(remote_config_parser_result_to_str(error)), error_(error)
    {}
    virtual const char *what() { return message_.c_str(); }
    remote_config_parser_result get_error() { return error_; }

private:
    std::string message_;
    remote_config_parser_result error_;
};

get_configs_response parse(const std::string &body);

} // namespace dds::remote_config::protocol