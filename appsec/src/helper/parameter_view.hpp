// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <concepts>
#include <stdexcept>
#include <string>
#include <string_view>
#include <unordered_map>

#include "exception.hpp"
#include "parameter_base.hpp"

namespace dds {

class __attribute__((__may_alias__)) parameter_view : public parameter_base {

public:
    using map = std::unordered_map<std::string_view, parameter_view>;

    template <typename T>
        requires std::convertible_to<T, ddwaf_object>
    // NOLINTNEXTLINE
    parameter_view(const T &arg)
        : parameter_base{static_cast<ddwaf_object>(arg)}
    {}
    parameter_view(const parameter_view &) = default;
    parameter_view &operator=(const parameter_view &) = default;
    parameter_view(parameter_view &&) = default;
    parameter_view &operator=(parameter_view &&) = default;
    ~parameter_view() = default;

    template <bool is_map> struct iterator {
        using value_type = std::conditional_t<is_map,
            std::pair<std::string_view, parameter_view>, parameter_view>;

        using difference_type = std::ptrdiff_t;
        using pointer = value_type *;
        using reference = value_type &;
        using iterator_category = std::forward_iterator_tag;

        // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
        explicit iterator(ddwaf_object obj, size_t index = 0, size_t total = 0)
            : obj_{obj}, index_(index), total_(total)
        {}

        bool operator!=(const iterator &rhs) const noexcept
        {
            return index_ != rhs.index_;
        }

        value_type operator*() const noexcept
        {
            // Get the value at current index
            const ddwaf_object *val = ddwaf_object_at_value(&obj_, index_);

            if constexpr (is_map) {
                // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
                const auto *key = reinterpret_cast<const parameter_view *>(
                    ddwaf_object_at_key(&obj_, index_));

                if (!key->is_string()) {
                    return {std::string_view{}, parameter_view{*val}};
                }
                auto key_str = static_cast<std::string_view>(*key);
                return {key_str, parameter_view{*val}};
            } else {
                // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
                return *val;
            }
        }

        iterator &operator++() noexcept
        {
            if (index_ < total_) {
                index_++;
            }
            return *this;
        }

    protected:
        ddwaf_object obj_;
        size_t index_{0};
        size_t total_{0};
    };

    class array_iterable_t {
    public:
        explicit array_iterable_t(const parameter_view &obj) : obj_{obj}
        {
            if (!obj_.is_array()) {
                throw invalid_type("parameter not an array");
            }
        }
        [[nodiscard]] iterator<false> begin() const
        {
            return iterator<false>(*&obj_, 0, obj_.size());
        }
        [[nodiscard]] iterator<false> end() const
        {
            return iterator<false>(*&obj_, obj_.size(), obj_.size());
        }

    private:
        const parameter_view &obj_; // NOLINT
    };
    [[nodiscard]] class array_iterable_t array_iterable() const
    {
        return array_iterable_t{*this};
    }

    class map_iterable_t {
    public:
        explicit map_iterable_t(const parameter_view &obj) : obj_{obj}
        {
            if (!obj_.is_map()) {
                throw invalid_type("parameter not a map");
            }
        }
        [[nodiscard]] iterator<true> begin() const
        {
            return iterator<true>(*&obj_, 0, obj_.size());
        }
        [[nodiscard]] iterator<true> end() const
        {
            return iterator<true>(*&obj_, obj_.size(), obj_.size());
        }

    private:
        const parameter_view &obj_; // NOLINT
    };

    [[nodiscard]] class map_iterable_t map_iterable() const
    {
        return map_iterable_t{*this};
    }

    const parameter_view &operator[](size_t index) const
    {
        if (!is_container()) {
            throw invalid_type("parameter not a container");
        }

        if (index >= size()) {
            throw std::out_of_range("index(" + std::to_string(index) +
                                    ") out of range(" + std::to_string(size()) +
                                    ")");
        }

        const ddwaf_object *val = ddwaf_object_at_value(&obj_, index);
        if (val == nullptr) {
            throw std::out_of_range("failed to get object at index");
        }
        // This is unsafe but matches the original API
        // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
        return *reinterpret_cast<const parameter_view *>(val);
    }

    explicit operator map() const
    {
        if (!is_map()) {
            throw bad_cast("parameter_view not a map");
        }

        std::unordered_map<std::string_view, parameter_view> result;
        size_t sz = size();
        if (sz == 0) {
            return result;
        }

        result.reserve(sz);
        for (size_t i = 0; i < sz; i++) {
            const ddwaf_object *key_obj = ddwaf_object_at_key(&obj_, i);
            const ddwaf_object *val_obj = ddwaf_object_at_value(&obj_, i);

            if (key_obj == nullptr || val_obj == nullptr) {
                continue;
            }

            size_t key_len;
            const char *key_str = ddwaf_object_get_string(key_obj, &key_len);
            if (key_str == nullptr) {
                throw bad_cast("invalid key in map entry");
            }

            result.emplace(
                std::string_view(key_str, key_len), parameter_view{*val_obj});
        }

        return result;
    }
};

} // namespace dds
