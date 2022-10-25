// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <algorithm>
#include <optional>
#include <ostream>
#include <string>
#include <string_view>
#include <unordered_set>
#include <vector>

namespace dds {

struct result {
    result() = default;

    result(std::vector<std::string> &&data_,
        std::unordered_set<std::string> &&actions_)
        : data(std::move(data_)), actions(std::move(actions_))
    {}
    result(const result &) = default;
    result(result &&) = default;
    result &operator=(const result &) = default;
    result &operator=(result &&) = default;
    ~result() = default;

    bool valid() const { return !data.empty() || !actions.empty(); }

    // Convenience method
    void merge(std::optional<result> &&oth)
    {
        if (oth) {
            merge(*oth);
        }
    }

    void merge(result &oth)
    {
        data.insert(data.end(), std::make_move_iterator(oth.data.begin()),
            std::make_move_iterator(oth.data.end()));
        actions.merge(oth.actions);
    }

    std::vector<std::string> data;
    std::unordered_set<std::string> actions;
};

} // namespace dds
