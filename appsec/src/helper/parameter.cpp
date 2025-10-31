// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "parameter.hpp"
#include "ddwaf.h"
#include "exception.hpp"

#include <array>
#include <cassert>
#include <charconv>

namespace {

constexpr size_t default_capacity = 16;

} // namespace

namespace dds {

parameter::parameter(const ddwaf_object &arg) { obj_ = arg; }

parameter::parameter(parameter &&other) noexcept
{
    obj_ = other.obj_;
    ddwaf_object_set_invalid(&other.obj_);
}

parameter &parameter::operator=(parameter &&other) noexcept
{
    obj_ = other.obj_;
    ddwaf_object_set_invalid(&other.obj_);
    return *this;
}

parameter parameter::map() noexcept
{
    ddwaf_object obj;
    auto *alloc = ddwaf_get_default_allocator();

    ddwaf_object_set_map(&obj, default_capacity, alloc);
    return parameter{obj};
}

parameter parameter::array() noexcept
{
    ddwaf_object obj;
    auto *alloc = ddwaf_get_default_allocator();
    ddwaf_object_set_array(&obj, default_capacity, alloc);
    return parameter{obj};
}

parameter parameter::uint64(uint64_t value) noexcept
{
    ddwaf_object obj;
    ddwaf_object_set_unsigned(&obj, value);
    return parameter{obj};
}

parameter parameter::int64(int64_t value) noexcept
{
    ddwaf_object obj;
    ddwaf_object_set_signed(&obj, value);
    return parameter{obj};
}

parameter parameter::string(uint64_t value) noexcept
{
    // v2 doesn't have string_from_unsigned, convert manually
    std::array<char, sizeof("18446744073709551615")> buf{};
    std::to_chars_result result =
        std::to_chars(buf.data(), buf.data() + buf.size(), value);
    if (result.ec != std::errc()) {
        result.ptr = buf.data();
    }
    ddwaf_object obj;
    auto *alloc = ddwaf_get_default_allocator();
    ddwaf_object_set_string(&obj, buf.data(), result.ptr - buf.data(), alloc);
    return parameter{obj};
}

parameter parameter::string(int64_t value) noexcept
{
    // v2 doesn't have string_from_signed, convert manually
    std::array<char, sizeof("9223372036854775807")> buf{};
    std::to_chars_result result =
        std::to_chars(buf.data(), buf.data() + buf.size(), value);
    if (result.ec != std::errc()) {
        result.ptr = buf.data();
    }
    ddwaf_object obj;
    auto *alloc = ddwaf_get_default_allocator();
    ddwaf_object_set_string(&obj, buf.data(), result.ptr - buf.data(), alloc);
    return parameter{obj};
}

parameter parameter::string(std::string_view str) noexcept
{
    length_type const length =
        str.length() <= max_length ? str.length() : max_length;
    ddwaf_object obj;
    auto *alloc = ddwaf_get_default_allocator();
    const auto *data = str.data();
    if (data == nullptr) {
        data = "";
    }
    ddwaf_object_set_string(&obj, data, length, alloc);
    return parameter{obj};
}

parameter parameter::as_boolean(bool value) noexcept
{
    ddwaf_object obj;
    ddwaf_object_set_bool(&obj, value);
    return parameter{obj};
}

parameter parameter::float64(float value) noexcept
{
    ddwaf_object obj;
    ddwaf_object_set_float(&obj, value);
    return parameter{obj};
}

parameter parameter::null() noexcept
{
    ddwaf_object obj;
    ddwaf_object_set_null(&obj);
    return parameter{obj};
}

// NOLINTNEXTLINE(cppcoreguidelines-rvalue-reference-param-not-moved)
bool parameter::add(parameter &&entry) noexcept
{
    auto *alloc = ddwaf_get_default_allocator();
    ddwaf_object *inserted = ddwaf_object_insert(&obj_, alloc);
    if (inserted == nullptr) {
        return false;
    }
    *inserted = entry.obj_;
    ddwaf_object_set_invalid(&entry.obj_);
    return true;
}

// NOLINTNEXTLINE(cppcoreguidelines-rvalue-reference-param-not-moved)
bool parameter::add(std::string_view name, parameter &&entry) noexcept
{
    length_type const length =
        name.length() <= max_length ? name.length() : max_length;
    auto *alloc = ddwaf_get_default_allocator();
    ddwaf_object *inserted =
        ddwaf_object_insert_key(&obj_, name.data(), length, alloc);
    if (inserted == nullptr) {
        return false;
    }
    *inserted = entry.obj_;
    ddwaf_object_set_invalid(&entry.obj_);
    return true;
}

// NOLINTNEXTLINE(misc-no-recursion)
bool parameter::merge(parameter other)
{
    if (other.type() != type()) {
        return false;
    }

    auto *alloc = ddwaf_get_default_allocator();

    if (is_array()) {
        for (size_t i = 0; i < other.size(); ++i) {
            const ddwaf_object *other_val =
                ddwaf_object_at_value(&other.obj_, i);
            if (other_val == nullptr) {
                assert(false && "ddwaf_object_at_value returned nullptr");
                continue;
            }
            ddwaf_object *inserted = ddwaf_object_insert(&obj_, alloc);
            if (inserted == nullptr) {
                assert(false && "ddwaf_object_insert returned nullptr");
                continue;
            }
            *inserted = *other_val;
            ddwaf_object_set_invalid(
                const_cast<ddwaf_object *>(other_val)); // NOLINT
        }
        return true;
    }
    if (is_map()) {
        for (size_t i = 0; i < other.size() /* 0 if not container */; ++i) {
            const ddwaf_object *other_key = ddwaf_object_at_key(&other.obj_, i);
            const ddwaf_object *other_val =
                ddwaf_object_at_value(&other.obj_, i);

            if ((other_key == nullptr) || (other_val == nullptr)) {
                assert(false && "ddwaf_object_at_key or ddwaf_object_at_value "
                                "returned nullptr");
                continue;
            }

            size_t key_len;
            const char *key_str = ddwaf_object_get_string(other_key, &key_len);
            if (key_str == nullptr) {
                // non string key; skip
                continue;
            }

            // check if key exists in this map
            const ddwaf_object *existing =
                ddwaf_object_find(&obj_, key_str, key_len);

            if (existing == nullptr) {
                // not found, add it
                ddwaf_object *inserted =
                    ddwaf_object_insert_key(&obj_, key_str, key_len, alloc);
                if (inserted != nullptr) {
                    *inserted = *other_val;
                    ddwaf_object temp;
                    ddwaf_object_set_invalid(&temp);
                    *const_cast<ddwaf_object *>(other_val) = temp; // NOLINT
                }
            } else {
                // merge required
                parameter &orig_p{reinterpret_cast<parameter &>(  // NOLINT
                    const_cast<ddwaf_object &>(*existing))};      // NOLINT
                parameter &other_p{reinterpret_cast<parameter &>( // NOLINT
                    const_cast<ddwaf_object &>(*other_val))};     // NOLINT

                orig_p.merge(std::move(other_p));
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

    const ddwaf_object *obj = ddwaf_object_at_value(&obj_, index);
    if (obj == nullptr) {
        throw std::out_of_range("failed to get object at index");
    }

    // This is unsafe but matches the original API
    // // NOLINTNEXTLINE
    return *const_cast<parameter *>(reinterpret_cast<const parameter *>(obj));
}

} // namespace dds
