// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include "exception.hpp"
#include <ddwaf.h>
#include <limits>

namespace dds {

enum parameter_type : unsigned {
    invalid = DDWAF_OBJ_INVALID,
    int64 = DDWAF_OBJ_SIGNED,
    uint64 = DDWAF_OBJ_UNSIGNED,
    string = DDWAF_OBJ_STRING,
    map = DDWAF_OBJ_MAP,
    array = DDWAF_OBJ_ARRAY,
    boolean = DDWAF_OBJ_BOOL
};

class parameter_base : public ddwaf_object {
public:
    parameter_base() : ddwaf_object() { ddwaf_object_invalid(this); }

    parameter_base(const parameter_base &) = default;
    parameter_base &operator=(const parameter_base &) = default;

    parameter_base(parameter_base &&) = default;
    parameter_base &operator=(parameter_base &&) = default;

    ~parameter_base() = default;

    // Container size
    [[nodiscard]] parameter_type type() const noexcept
    {
        return static_cast<parameter_type>(ddwaf_object::type);
    }
    [[nodiscard]] size_t size() const noexcept
    {
        if (!is_container()) {
            return 0;
        }
        return static_cast<size_t>(nbEntries);
    }
    [[nodiscard]] size_t length() const noexcept
    {
        if (!is_string()) {
            return 0;
        }
        return static_cast<size_t>(nbEntries);
    }
    [[nodiscard]] bool has_key() const noexcept
    {
        return parameterName != nullptr;
    }
    [[nodiscard]] std::string_view key() const noexcept
    {
        return {parameterName, parameterNameLength};
    }
    [[nodiscard]] bool is_map() const noexcept
    {
        return ddwaf_object::type == DDWAF_OBJ_MAP;
    }
    [[nodiscard]] bool is_container() const noexcept
    {
        return (ddwaf_object::type & (DDWAF_OBJ_MAP | DDWAF_OBJ_ARRAY)) != 0;
    }
    [[nodiscard]] bool is_string() const noexcept
    {
        return ddwaf_object::type == DDWAF_OBJ_STRING;
    }
    [[nodiscard]] bool is_unsigned() const noexcept
    {
        return ddwaf_object::type == DDWAF_OBJ_UNSIGNED;
    }
    [[nodiscard]] bool is_signed() const noexcept
    {
        return ddwaf_object::type == DDWAF_OBJ_SIGNED;
    }
    [[nodiscard]] bool is_boolean() const noexcept
    {
        return ddwaf_object::type == DDWAF_OBJ_BOOL;
    }
    [[nodiscard]] bool is_valid() const noexcept
    {
        return ddwaf_object::type != DDWAF_OBJ_INVALID;
    }
    [[nodiscard]] bool is_float() const noexcept
    {
        return ddwaf_object::type == DDWAF_OBJ_FLOAT;
    }
    // NOLINTNEXTLINE
    operator ddwaf_object *() noexcept { return this; }

    explicit operator std::string_view() const
    {
        if (!is_string()) {
            throw bad_cast("parameter not a string");
        }
        return {stringValue, nbEntries};
    }
    explicit operator std::string() const
    {
        if (!is_string()) {
            throw bad_cast("parameter not a string");
        }
        return {stringValue, nbEntries};
    }
    explicit operator uint64_t() const
    {
        if (!is_unsigned()) {
            throw bad_cast("parameter not an uint64");
        }
        return uintValue;
    }
    explicit operator int64_t() const
    {
        if (!is_signed()) {
            throw bad_cast("parameter not an int64");
        }
        return intValue;
    }
    explicit operator double() const
    {
        if (!is_float()) {
            throw bad_cast("parameter not a float");
        }
        return f64;
    }
    explicit operator bool() const
    {
        if (!is_boolean()) {
            throw bad_cast("parameter not a bool");
        }
        return boolean;
    }

    [[nodiscard]] std::string debug_str() const noexcept;

    using length_type = decltype(nbEntries);
    static constexpr auto max_length{
        std::numeric_limits<length_type>::max() - 1};
};

} // namespace dds
