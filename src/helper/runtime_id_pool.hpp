// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <mutex>
#include <optional>
#include <string_view>
#include <unordered_set>

namespace dds {

/* The runtime ID pool basically provides:
 *   - A thread-safe mechanism to store  and remove runtime IDs.
 *   - A function to retrieve a valid runtime ID that doesn't change
 *     for as long as it is valid.
 *   - An guarantee  that there will always be an ID provided, even if
 *     the process who owned that ID has already been finalised.
 */
class runtime_id_pool {
public:
    runtime_id_pool() = default;

    void add(std::string id)
    {
        // Empty IDs aren't valid
        if (id.empty()) {
            return;
        }

        std::lock_guard<std::mutex> lock{mtx_};
        ids_.emplace(std::move(id));
        if (ids_.size() == 1 || current_.empty()) {
            current_ = *ids_.begin();
        }
    }

    void remove(const std::string &id)
    {
        // Empty IDs aren't valid
        if (id.empty()) {
            return;
        }

        std::lock_guard<std::mutex> lock{mtx_};
        auto it = ids_.find(id);
        if (it != ids_.end()) {
            ids_.erase(it);

            // Don't change the ID if there are no more IDs or if the current ID
            // is still valid within the multiset
            if (!ids_.empty() && ids_.find(current_) == ids_.end()) {
                current_ = *ids_.begin();
            }
        }
    }

    [[nodiscard]] std::string get() const
    {
        std::lock_guard<std::mutex> lock{mtx_};
        return current_;
    }

    [[nodiscard]] bool has_value() const
    {
        std::lock_guard<std::mutex> lock{mtx_};
        return !current_.empty();
    }

protected:
    mutable std::mutex mtx_;
    std::unordered_multiset<std::string> ids_;
    std::string current_{};
};

} // namespace dds
