// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#pragma once

#include "../tel_subm_mock.hpp"
#include "engine.hpp"
#include "metrics.hpp"
#include "remote_config/config.hpp"

namespace dds::remote_config::mock {

class engine : public dds::engine {
public:
    explicit engine(
        uint32_t trace_rate_limit = engine_settings::default_trace_rate_limit,
        action_map &&actions = {})
        : dds::engine(trace_rate_limit)
    {}
    MOCK_METHOD(void, update,
        (const rapidjson::Document &, metrics::telemetry_submitter &),
        (override));

    static auto create() { return std::shared_ptr<engine>(new engine()); }
};

remote_config::config get_config(
    std::string_view product_name, const std::string &content);

struct asm_add {
    std::string_view path;
    std::string_view data;
};
struct asm_remove {
    std::string_view path;
};

template <typename... Args> rapidjson::Document create_cs(Args... actions)
{
    rapidjson::Document doc;
    doc.SetObject();
    auto &allocator = doc.GetAllocator();
    doc.AddMember(rapidjson::StringRef("asm_added"),
        rapidjson::Value{rapidjson::kObjectType}, allocator);
    doc.AddMember(rapidjson::StringRef("asm_removed"),
        rapidjson::Value(rapidjson::kArrayType), allocator);

    (
        [&](auto &&act) {
            using act_type = std::decay_t<decltype(act)>;
            if constexpr (std::is_same_v<act_type, asm_add>) {
                rapidjson::Document new_doc{rapidjson::kObjectType, &allocator};
                new_doc.Parse(&act.data[0], act.data.size());
                doc["asm_added"].AddMember(
                    rapidjson::Value{&act.path[0],
                        static_cast<rapidjson::SizeType>(act.path.size()),
                        allocator},
                    std::move(new_doc), allocator);
            } else if constexpr (std::is_same_v<act_type, asm_remove>) {
                doc["asm_removed"].PushBack(
                    rapidjson::Value{&act.path[0],
                        static_cast<rapidjson::SizeType>(act.path.size()),
                        allocator},
                    allocator);
            }
        }(actions),
        ...);

    return doc;
}

} // namespace dds::remote_config::mock
