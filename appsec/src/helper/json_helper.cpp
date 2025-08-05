// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#include "json_helper.hpp"
#include "parameter.hpp"
#include "parameter_view.hpp"
#include "std_logging.hpp"
#include <base64.h>
#include <ddwaf.h>
#include <rapidjson/error/en.h>
#include <rapidjson/prettywriter.h>
#include <string_view>
#include <type_traits>

using namespace std::literals;

namespace dds {

namespace {

// TODO: Limit recursion
template <typename T>
// NOLINTNEXTLINE(misc-no-recursion, google-runtime-references)
void parameter_to_json_helper(const parameter_view &pv, T &output,
    rapidjson::Document::AllocatorType &alloc)
{
    switch (pv.type()) {
    case parameter_type::int64:
        output.SetInt64(int64_t(pv));
        break;
    case parameter_type::uint64:
        output.SetUint64(uint64_t(pv));
        break;
    case parameter_type::string: {
        auto sv = std::string_view(pv);
        output.SetString(sv.data(), sv.size(), alloc);
    } break;
    case parameter_type::boolean:
        output.SetBool(bool(pv));
        break;
    case parameter_type::map:
        output.SetObject();
        for (const auto &v : pv) {
            rapidjson::Value key;
            rapidjson::Value value;
            parameter_to_json_helper(v, value, alloc);

            std::string_view const sv = v.key();
            key.SetString(sv.data(), sv.size(), alloc);

            output.AddMember(key, value, alloc);
        }
        break;
    case parameter_type::array:
        output.SetArray();
        for (const auto &v : pv) {
            rapidjson::Value value;
            parameter_to_json_helper(v, value, alloc);
            output.PushBack(value, alloc);
        }
        break;
    case parameter_type::invalid:
        throw std::runtime_error("invalid parameter in structure");
    };
}

} // namespace

std::string parameter_to_json(const parameter_view &pv)
{
    try {
        rapidjson::Document document;
        rapidjson::Document::AllocatorType &alloc = document.GetAllocator();

        parameter_to_json_helper(pv, document, alloc);

        dds::string_buffer buffer;
        rapidjson::Writer<decltype(buffer)> writer(buffer);

        if (document.Accept(writer)) {
            return std::move(buffer.get_string_ref());
        }
    } catch (const std::exception &e) {
        SPDLOG_WARN("Failed to convert WAF parameter to JSON: {}", e.what());
    }

    return {};
}

// TODO: we should limit the recursion
template <typename T, typename = std::enable_if_t<std::disjunction_v<
                          std::is_same<rapidjson::Document, std::decay_t<T>>,
                          std::is_same<rapidjson::Value, std::decay_t<T>>>>>
// NOLINTNEXTLINE(misc-no-recursion)
void json_to_object(ddwaf_object *object, T &doc)
{
    switch (doc.GetType()) {
    case rapidjson::kFalseType:
        ddwaf_object_stringl(object, "false", sizeof("false") - 1);
        break;
    case rapidjson::kTrueType:
        ddwaf_object_stringl(object, "true", sizeof("true") - 1);
        break;
    case rapidjson::kObjectType: {
        ddwaf_object_map(object);
        for (auto &kv : doc.GetObject()) {
            ddwaf_object element;
            json_to_object(&element, kv.value);

            std::string_view const key = kv.name.GetString();
            ddwaf_object_map_addl(object, key.data(), key.length(), &element);
        }
        break;
    }
    case rapidjson::kArrayType: {
        ddwaf_object_array(object);
        for (auto &v : doc.GetArray()) {
            ddwaf_object element;
            json_to_object(&element, v);

            ddwaf_object_array_add(object, &element);
        }
        break;
    }
    case rapidjson::kStringType: {
        std::string_view const str = doc.GetString();
        ddwaf_object_stringl(object, str.data(), str.size());
        break;
    }
    case rapidjson::kNumberType: {
        if (doc.IsInt64()) {
            ddwaf_object_signed(object, doc.GetInt64());
        } else if (doc.IsUint64()) {
            ddwaf_object_unsigned(object, doc.GetUint64());
        } else if (doc.IsDouble()) {
            ddwaf_object_float(object, doc.GetDouble());
        }
        break;
    }
    case rapidjson::kNullType:
    default:
        ddwaf_object_invalid(object);
        break;
    }
}

dds::parameter json_to_parameter(const rapidjson::Value &value)
{
    dds::parameter obj;
    json_to_object(obj, value);
    return obj;
}

dds::parameter json_to_parameter(std::string_view json)
{
    rapidjson::Document doc;
    rapidjson::ParseResult const result = doc.Parse(json.data());
    if (result.IsError()) {
        throw parsing_error("invalid json object: "s +
                            rapidjson::GetParseError_En(result.Code()));
    }
    return json_to_parameter(doc);
}

std::optional<rapidjson::Value::ConstMemberIterator>
json_helper::get_field_of_type(const rapidjson::Value &parent_field,
    std::string_view key, rapidjson::Type type)
{
    rapidjson::Value::ConstMemberIterator const output_itr =
        parent_field.FindMember(key.data());

    if (output_itr == parent_field.MemberEnd()) {
        SPDLOG_DEBUG("Field {} not found", key);
        return std::nullopt;
    }

    if (type != output_itr->value.GetType()) {
        SPDLOG_DEBUG("Field {} is not of type {}. Instead {}", key,
            fmt::underlying(type),
            fmt::underlying(output_itr->value.GetType()));
        return std::nullopt;
    }

    return output_itr;
}

std::optional<rapidjson::Value::ConstMemberIterator>
json_helper::get_field_of_type(
    rapidjson::Value::ConstMemberIterator &parent_field, std::string_view key,
    rapidjson::Type type)
{
    return get_field_of_type(parent_field->value, key, type);
}

std::optional<rapidjson::Value::ConstMemberIterator>
json_helper::get_field_of_type(
    rapidjson::Value::ConstValueIterator parent_field, std::string_view key,
    rapidjson::Type type)
{
    return get_field_of_type(*parent_field, key, type);
}

bool json_helper::field_exists(
    const rapidjson::Value &parent_field, std::string_view key)
{
    return parent_field.FindMember(key.data()) != parent_field.MemberEnd();
}

bool json_helper::field_exists(
    const rapidjson::Value::ConstMemberIterator &parent_field,
    std::string_view key)
{
    return field_exists(parent_field->value, key);
}

bool json_helper::field_exists(
    const rapidjson::Value::ConstValueIterator parent_field,
    std::string_view key)
{
    return field_exists(*parent_field, key);
}

bool json_helper::parse_json(
    std::string_view content, rapidjson::Document &output)
{
    if (output.Parse(content.data(), content.size()).HasParseError()) {
        SPDLOG_DEBUG("Invalid json: " + std::string(rapidjson::GetParseError_En(
                                            output.GetParseError())));
        return false;
    }

    return true;
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
void json_helper::merge_arrays(rapidjson::Value &destination,
    rapidjson::Value &source, rapidjson::Value::AllocatorType &allocator)
{
    if (!destination.IsArray()) {
        throw invalid_type("destination value not an array");
    }
    if (!source.IsArray()) {
        throw invalid_type("source value not an array");
    }
    for (auto *it = source.Begin(); it != source.End(); ++it) {
        destination.PushBack(*it, allocator);
    }
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters,misc-no-recursion)
void json_helper::merge_objects(rapidjson::Value &destination,
    rapidjson::Value &source, rapidjson::Value::AllocatorType &allocator)
{
    if (!destination.IsObject()) {
        throw invalid_type("destination value not an object");
    }
    if (!source.IsObject()) {
        throw invalid_type("source value not an object");
    }
    for (auto it = source.MemberBegin(); it != source.MemberEnd(); ++it) {
        if (destination.HasMember(it->name)) {
            auto &cur = destination[it->name];
            if (cur.IsArray() && it->value.IsArray()) {
                merge_arrays(cur, it->value, allocator);
            } else if (cur.IsObject() && it->value.IsObject()) {
                merge_objects(cur, it->value, allocator);
            } else {
                destination[it->name] = it->value;
            }
        } else {
            destination.AddMember(it->name, it->value, allocator);
        }
    }
}

} // namespace dds
