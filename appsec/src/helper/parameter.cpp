// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "parameter.hpp"
#include "ddwaf.h"
#include "exception.hpp"
#include <algorithm>

namespace dds {

parameter::parameter(const ddwaf_object &arg)
{
    *static_cast<ddwaf_object *>(this) = arg;
}

parameter::parameter(parameter &&other) noexcept
{
    *((ddwaf_object *)this) = *other;
    ddwaf_object_invalid(other);
}

parameter &parameter::operator=(parameter &&other) noexcept
{
    *((ddwaf_object *)this) = *other;
    ddwaf_object_invalid(other);
    return *this;
}

parameter parameter::map() noexcept
{
    ddwaf_object obj;
    ddwaf_object_map(&obj);
    return parameter{obj};
}

parameter parameter::array() noexcept
{
    ddwaf_object obj;
    ddwaf_object_array(&obj);
    return parameter{obj};
}

parameter parameter::uint64(uint64_t value) noexcept
{
    ddwaf_object obj;
    ddwaf_object_unsigned(&obj, value);
    return parameter{obj};
}

parameter parameter::int64(int64_t value) noexcept
{
    ddwaf_object obj;
    ddwaf_object_signed(&obj, value);
    return parameter{obj};
}

parameter parameter::string(uint64_t value) noexcept
{
    ddwaf_object obj;
    ddwaf_object_string_from_unsigned(&obj, value);
    return parameter{obj};
}

parameter parameter::string(int64_t value) noexcept
{
    ddwaf_object obj;
    ddwaf_object_string_from_signed(&obj, value);
    return parameter{obj};
}

parameter parameter::string(const std::string &str) noexcept
{
    length_type const length =
        str.length() <= max_length ? str.length() : max_length;
    ddwaf_object obj;
    ddwaf_object_stringl(&obj, str.c_str(), length);
    return parameter{obj};
}

parameter parameter::string(std::string_view str) noexcept
{
    length_type const length =
        str.length() <= max_length ? str.length() : max_length;
    ddwaf_object obj;
    ddwaf_object_stringl(&obj, str.data(), length);
    return parameter{obj};
}

parameter parameter::as_boolean(bool value) noexcept
{
    ddwaf_object obj;
    ddwaf_object_bool(&obj, value);
    return parameter{obj};
}

parameter parameter::float64(float value) noexcept
{
    ddwaf_object obj;
    ddwaf_object_float(&obj, value);
    return parameter{obj};
}

parameter parameter::null() noexcept
{
    ddwaf_object obj;
    ddwaf_object_null(&obj);
    return parameter{obj};
}

// NOLINTNEXTLINE(cppcoreguidelines-rvalue-reference-param-not-moved)
bool parameter::add(parameter &&entry) noexcept
{
    if (!ddwaf_object_array_add(this, entry)) {
        return false;
    }
    ddwaf_object_invalid(entry);
    return true;
}

// NOLINTNEXTLINE(cppcoreguidelines-rvalue-reference-param-not-moved)
bool parameter::add(std::string_view name, parameter &&entry) noexcept
{
    length_type const length =
        name.length() <= max_length ? name.length() : max_length;
    if (!ddwaf_object_map_addl(this, name.data(), length, entry)) {
        return false;
    }
    ddwaf_object_invalid(entry);
    return true;
}

// NOLINTNEXTLINE(misc-no-recursion)
bool parameter::merge(parameter other)
{
    if (other.type() != type()) {
        return false;
    }

    if (type() == parameter_type::array) {
        for (size_t i = 0; i < other.size(); ++i) {
            ddwaf_object_array_add(this, other[i]);
            ddwaf_object_invalid(&other[i]);
        }
        return true;
    }
    if (type() == parameter_type::map) {
        for (size_t i = 0; i < other.size(); ++i) {
            auto &oentry = other[i];
            const std::string_view &key = oentry.key();

            // NOLINTNEXTLINE(cppcoreguidelines-pro-type-static-cast-downcast)
            auto *start = static_cast<parameter *>(ddwaf_object::array);
            auto *end = start + this->nbEntries;
            auto *orig_entry = std::find_if(
                start, end, [&key](const auto &v) { return v.key() == key; });

            if (orig_entry == end) { // not found
                ddwaf_object_map_addl_nc(
                    this, key.data(), key.length(), &oentry);
                ddwaf_object_invalid(&oentry); // also nulls out key
            } else {
                // a merge is required
                orig_entry->merge(std::move(oentry));
            }
        }
        return true;
    }
    return false;
}

parameter &parameter::operator[](size_t index) const
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
    return static_cast<parameter &>(ddwaf_object::array[index]);
}

} // namespace dds
