// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <limits>
#include <stdexcept>
#include <string>
#include <string_view>
#include <unordered_map>
#include <unordered_set>
#include <vector>

#include "exception.hpp"
#include "parameter.hpp"
#include "parameter_base.hpp"

namespace dds {

class parameter_view : public parameter_base {
public:
    class iterator {
    public:
        explicit iterator(const parameter_view &pv, size_t index = 0)
            : current_(
                  pv.array + (index < pv.nbEntries ? index : pv.nbEntries)),
              end_(pv.array + pv.nbEntries)
        {}

        bool operator!=(const iterator &rhs) const noexcept
        {
            return current_ != rhs.current_;
        }

        const parameter_view &operator*() const noexcept
        {
            // NOLINTNEXTLINE(cppcoreguidelines-pro-type-static-cast-downcast)
            return static_cast<const parameter_view &>(*current_);
        }

        iterator &operator++() noexcept
        {
            if (current_ != end_) {
                current_++;
            }
            return *this;
        }

    protected:
        const ddwaf_object *current_{nullptr};
        const ddwaf_object *end_{nullptr};
    };

    parameter_view() = default;
    explicit parameter_view(const ddwaf_object &arg)
    {
        *(static_cast<ddwaf_object *>(this)) = arg;
    }

    explicit parameter_view(const parameter &arg)
    {
        *(static_cast<ddwaf_object *>(this)) =
            static_cast<const ddwaf_object &>(arg);
    }

    parameter_view(const parameter_view &) = default;
    parameter_view &operator=(const parameter_view &) = default;

    parameter_view(parameter_view &&) = delete;
    parameter_view operator=(parameter_view &&) = delete;

    ~parameter_view() = default;

    [[nodiscard]] iterator begin() const
    {
        if (!is_container()) {
            throw invalid_type("parameter not a container");
        }
        return iterator(*this);
    }
    [[nodiscard]] iterator end() const
    {
        if (!is_container()) {
            throw invalid_type("parameter not a container");
        }
        return iterator(*this, size());
    }

    parameter_view &operator[](size_t index) const
    {
        if (!is_container()) {
            throw invalid_type("parameter not a container");
        }

        if (index >= size()) {
            throw std::out_of_range("index(" + std::to_string(index) +
                                    ") out of range(" + std::to_string(size()) +
                                    ")");
        }
        // NOLINTNEXTLINE(cppcoreguidelines-pro-type-static-cast-downcast)
        return static_cast<parameter_view &>(ddwaf_object::array[index]);
    }
};

} // namespace dds
