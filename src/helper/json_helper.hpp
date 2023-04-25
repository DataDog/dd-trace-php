// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "parameter_view.hpp"
#include <optional>
#include <rapidjson/document.h>
#include <string>

namespace dds {

inline rapidjson::GenericStringRef<char> StringRef(std::string_view str)
{
    return {str.data(), static_cast<rapidjson::SizeType>(str.size())};
}

// This replaces rapidjson::StringBuffer providing a way to write directly
// into an std::string without requiring an extra copy.
// rapidjson::StringBuffer provides a const char * so it can still be used
// when std::string_view is enough.
class string_buffer {
public:
    using Ch = char;

protected:
    static constexpr std::size_t default_capacity = 1024;

public:
    string_buffer() { buffer_.reserve(default_capacity); }

    void Put(Ch c) { buffer_.push_back(c); }
    void PutUnsafe(Ch c) { Put(c); }
    void Flush() {}
    void Clear() { buffer_.clear(); }
    void ShrinkToFit() { buffer_.shrink_to_fit(); }
    void Reserve(size_t count) { buffer_.reserve(count); }

    [[nodiscard]] const Ch *GetString() const { return buffer_.c_str(); }
    [[nodiscard]] size_t GetSize() const { return buffer_.size(); }

    [[nodiscard]] size_t GetLength() const { return GetSize(); }

    std::string &get_string_ref() { return buffer_; }

protected:
    std::string buffer_;
};

std::string parameter_to_json(const dds::parameter_view &pv);
dds::parameter json_to_parameter(const rapidjson::Document &doc);
dds::parameter json_to_parameter(std::string_view json);

namespace json_helper {
std::optional<rapidjson::Value::ConstMemberIterator> get_field_of_type(
    const rapidjson::Value &parent_field, std::string_view key,
    rapidjson::Type type);
std::optional<rapidjson::Value::ConstMemberIterator> get_field_of_type(
    rapidjson::Value::ConstMemberIterator &parent_field, std::string_view key,
    rapidjson::Type type);
std::optional<rapidjson::Value::ConstMemberIterator> get_field_of_type(
    rapidjson::Value::ConstValueIterator parent_field, std::string_view key,
    rapidjson::Type type);
bool get_json_base64_encoded_content(
    const std::string &content, rapidjson::Document &output);
// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
void merge_arrays(rapidjson::Value &destination, rapidjson::Value &source,
    rapidjson::Value::AllocatorType &allocator);

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
void merge_objects(rapidjson::Value &destination, rapidjson::Value &source,
    rapidjson::Value::AllocatorType &allocator);

} // namespace json_helper
} // namespace dds
