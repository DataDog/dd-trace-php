// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <rapidjson/document.h>
#include <string_view>

namespace dds {

class engine_ruleset {
public:
    explicit engine_ruleset(std::string_view ruleset);
    explicit engine_ruleset(rapidjson::Document doc) : doc_(std::move(doc)) {}

    [[nodiscard]] const rapidjson::Document &get_document() const
    {
        return doc_;
    }

    [[nodiscard]] const auto &get_node(std::string_view key) const
    {
        return doc_[key.data()];
    }

    static engine_ruleset from_path(std::string_view path);

    // Used only for testing
    void copy(rapidjson::Document &new_doc)
    {
        new_doc.CopyFrom(doc_, new_doc.GetAllocator(), true);
    }

protected:
    rapidjson::Document doc_;
};

} // namespace dds
