// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "parameter.hpp"
#include "ddwaf.h"
#include "exception.hpp"
#include <string>
#include <string_view>

namespace dds {

parameter::parameter() : _ddwaf_object() { ddwaf_object_invalid(this); }

parameter::parameter(const ddwaf_object &arg) : _ddwaf_object()
{
    *((ddwaf_object *)this) = arg;
}

parameter::parameter(parameter &&other) noexcept : _ddwaf_object()
{
    *((ddwaf_object *)this) = *other.ptr();
    ddwaf_object_invalid(other.ptr());
}

parameter &parameter::operator=(parameter &&other) noexcept
{
    *((ddwaf_object *)this) = *other.ptr();
    ddwaf_object_invalid(other.ptr());
    return *this;
}

parameter::parameter(uint64_t value) : _ddwaf_object()
{
    ddwaf_object_unsigned(this, value);
}

parameter::parameter(int64_t value) : _ddwaf_object()
{
    ddwaf_object_signed(this, value);
}

parameter::parameter(const std::string &str) : _ddwaf_object()
{
    length_type length = str.length() <= max_length ? str.length() : max_length;
    ddwaf_object_stringl(this, str.c_str(), length);
}

parameter::parameter(std::string_view str) : _ddwaf_object()
{
    length_type length = str.length() <= max_length ? str.length() : max_length;
    ddwaf_object_stringl(this, str.data(), length);
}

void parameter::free() noexcept { ddwaf_object_free(this); }

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

bool parameter::add(parameter &entry) noexcept
{
    return ddwaf_object_array_add(this, entry.ptr());
}
bool parameter::add(parameter &&entry) noexcept
{
    if (!ddwaf_object_array_add(this, entry.ptr())) {
        entry.free();
        return false;
    }
    return true;
}
bool parameter::add(std::string_view name, parameter &entry) noexcept
{
    length_type length =
        name.length() <= max_length ? name.length() : max_length;
    return ddwaf_object_map_addl(this, name.data(), length, entry.ptr());
}

bool parameter::add(std::string_view name, parameter &&entry) noexcept
{
    length_type length =
        name.length() <= max_length ? name.length() : max_length;
    if (!ddwaf_object_map_addl(this, name.data(), length, entry.ptr())) {
        entry.free();
        return false;
    }
    return true;
}

ddwaf_object *parameter::ptr() noexcept { return this; }

parameter parameter::operator[](size_t index) const noexcept
{
    if (!is_container() || index >= size()) {
        return {};
    }

    return parameter{ddwaf_object::array[index]};
}

parameter::operator std::string_view() const noexcept
{
    if (type != DDWAF_OBJ_STRING || stringValue == nullptr) {
        return {};
    }

    return {stringValue, nbEntries};
}

namespace {
// NOLINTNEXTLINE(misc-no-recursion,google-runtime-references)
void debug_str_helper(std::string &res, const parameter &p) noexcept
{
    if (p.parameterNameLength != 0U) {
        res += p.key();
        res += ": ";
    }
    switch (p.type) {
    case DDWAF_OBJ_INVALID:
        res += "<invalid>";
        break;
    case DDWAF_OBJ_SIGNED:
        res += std::to_string(p.intValue);
        break;
    case DDWAF_OBJ_UNSIGNED:
        res += std::to_string(p.uintValue);
        break;
    case DDWAF_OBJ_STRING:
        res += '"';
        res += std::string_view{p.stringValue, p.nbEntries};
        res += '"';
        break;
    case DDWAF_OBJ_ARRAY:
        res += '[';
        for (decltype(p.size()) i = 0; i < p.size(); i++) {
            debug_str_helper(res, p[i]);
            if (i != p.size() - 1) {
                res += ", ";
            }
        }
        res += ']';
        break;
    case DDWAF_OBJ_MAP:
        res += '{';
        for (decltype(p.size()) i = 0; i < p.size(); i++) {
            debug_str_helper(res, p[i]);
            if (i != p.size() - 1) {
                res += ", ";
            }
        }
        res += '}';
        break;
    }
}
} // namespace

std::string parameter::debug_str() const noexcept
{
    std::string res;
    debug_str_helper(res, *this);
    return res;
}
} // namespace dds
