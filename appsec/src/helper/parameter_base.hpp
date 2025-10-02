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

enum class parameter_type : unsigned {
    invalid = 0,
    null,
    boolean,
    int64,
    uint64,
    float64,
    string,
    array,
    map,
};

class parameter_base {
protected:
    ddwaf_object obj_{};

public:
    parameter_base() = default;
    explicit parameter_base(const ddwaf_object &arg) : obj_{arg} {}
    parameter_base(const parameter_base &) = default;
    parameter_base &operator=(const parameter_base &) = default;
    parameter_base(parameter_base &&) noexcept = default;
    parameter_base &operator=(parameter_base &&) noexcept = default;
    ~parameter_base() = default;

    // Container size
    [[nodiscard]] parameter_type type() const noexcept
    {
        switch (ddwaf_object_get_type(&obj_)) {
        default:
        case DDWAF_OBJ_INVALID:
            return parameter_type::invalid;
        case DDWAF_OBJ_NULL:
            return parameter_type::null;
        case DDWAF_OBJ_SIGNED:
            return parameter_type::int64;
        case DDWAF_OBJ_UNSIGNED:
            return parameter_type::uint64;
        case DDWAF_OBJ_FLOAT:
            return parameter_type::float64;
        case DDWAF_OBJ_STRING:
        case DDWAF_OBJ_LITERAL_STRING:
        case DDWAF_OBJ_SMALL_STRING:
            return parameter_type::string;
        case DDWAF_OBJ_MAP:
            return parameter_type::map;
        case DDWAF_OBJ_ARRAY:
            return parameter_type::array;
        case DDWAF_OBJ_BOOL:
            return parameter_type::boolean;
        }
    }
    [[nodiscard]] size_t size() const noexcept
    {
        if (!is_container()) {
            return 0;
        }
        return ddwaf_object_get_size(&obj_);
    }
    [[nodiscard]] size_t length() const noexcept
    {
        if (!is_string()) {
            return 0;
        }
        return ddwaf_object_get_length(&obj_);
    }

    [[nodiscard]] bool is_array() const noexcept
    {
        return ddwaf_object_is_array(&obj_);
    }

    [[nodiscard]] bool is_map() const noexcept
    {
        return ddwaf_object_is_map(&obj_);
    }
    [[nodiscard]] bool is_container() const noexcept
    {
        return ddwaf_object_is_array(&obj_) || ddwaf_object_is_map(&obj_);
    }
    [[nodiscard]] bool is_string() const noexcept
    {
        return ddwaf_object_is_string(&obj_);
    }
    [[nodiscard]] bool is_unsigned() const noexcept
    {
        return ddwaf_object_is_unsigned(&obj_);
    }
    [[nodiscard]] bool is_signed() const noexcept
    {
        return ddwaf_object_is_signed(&obj_);
    }
    [[nodiscard]] bool is_boolean() const noexcept
    {
        return ddwaf_object_is_bool(&obj_);
    }
    [[nodiscard]] bool is_valid() const noexcept
    {
        return !ddwaf_object_is_invalid(&obj_);
    }
    [[nodiscard]] bool is_float() const noexcept
    {
        return ddwaf_object_is_float(&obj_);
    }

    // NOLINTNEXTLINE(google-runtime-operator)
    ddwaf_object *operator&() noexcept { return &obj_; }
    // NOLINTNEXTLINE(google-runtime-operator)
    const ddwaf_object *operator&() const noexcept { return &obj_; }

    explicit operator std::string_view() const
    {
        if (!is_string()) {
            throw bad_cast("parameter not a string");
        }
        size_t len;
        const char *str = ddwaf_object_get_string(&obj_, &len);
        return {str, len};
    }
    explicit operator std::string() const
    {
        if (!is_string()) {
            throw bad_cast("parameter not a string");
        }
        size_t len;
        const char *str = ddwaf_object_get_string(&obj_, &len);
        return {str, len};
    }
    explicit operator uint64_t() const
    {
        if (!is_unsigned()) {
            throw bad_cast("parameter not an uint64");
        }
        return ddwaf_object_get_unsigned(&obj_);
    }
    explicit operator int64_t() const
    {
        if (!is_signed()) {
            throw bad_cast("parameter not an int64");
        }
        return ddwaf_object_get_signed(&obj_);
    }
    explicit operator double() const
    {
        if (!is_float()) {
            throw bad_cast("parameter not a float");
        }
        return ddwaf_object_get_float(&obj_);
    }
    explicit operator bool() const
    {
        if (!is_boolean()) {
            throw bad_cast("parameter not a bool");
        }
        return ddwaf_object_get_bool(&obj_);
    }

    [[nodiscard]] std::string debug_str() const noexcept;

    using length_type = uint16_t; // v2 uses uint16_t for sizes
    static constexpr auto max_length{
        std::numeric_limits<length_type>::max() - 1};
};

} // namespace dds
