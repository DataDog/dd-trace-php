// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#include <rapidjson/document.h>
#include <rapidjson/prettywriter.h>

#include <string_view>

#include "json_helper.hpp"
#include "parameter.hpp"
#include "parameter_view.hpp"
#include "std_logging.hpp"

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
    case parameter_type::map:
        output.SetObject();
        for (const auto &v : pv) {
            rapidjson::Value key;
            rapidjson::Value value;
            parameter_to_json_helper(v, value, alloc);

            std::string_view sv = v.key();
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

} // namespace dds
