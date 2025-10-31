// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#include <limits>
#include <string>
#include <string_view>

#include "ddwaf.h"
#include "parameter_base.hpp"

namespace dds {

namespace {
// NOLINTNEXTLINE(misc-no-recursion,google-runtime-references)
void debug_str_helper(std::string &res, const ddwaf_object &p)
{
    switch (static_cast<DDWAF_OBJ_TYPE>(p.type)) {
    case DDWAF_OBJ_INVALID:
        res += "<invalid>";
        break;
    case DDWAF_OBJ_BOOL:
        res += (p.via.b8.val ? "true" : "false");
        break;
    case DDWAF_OBJ_SIGNED:
        res += std::to_string(p.via.i64.val);
        break;
    case DDWAF_OBJ_UNSIGNED:
        res += std::to_string(p.via.u64.val);
        break;
    case DDWAF_OBJ_SMALL_STRING:
    case DDWAF_OBJ_LITERAL_STRING:
    case DDWAF_OBJ_STRING: {
        res += '"';
        size_t len;
        const char *str = ddwaf_object_get_string(&p, &len);
        res += std::string_view{str, len};
        res += '"';
        break;
    }
    case DDWAF_OBJ_ARRAY:
        res += '[';
        for (decltype(p.via.array.size) i = 0; i < p.via.array.size; i++) {
            debug_str_helper(res, p.via.array.ptr[i]);
            if (i != p.via.array.size - 1) {
                res += ", ";
            }
        }
        res += ']';
        break;
    case DDWAF_OBJ_MAP:
        res += '{';
        for (decltype(p.via.map.size) i = 0; i < p.via.map.size; i++) {
            auto &kv = p.via.map.ptr[i];
            debug_str_helper(res, kv.key);
            res += ": ";
            debug_str_helper(res, kv.val);
            if (i != p.via.map.size - 1) {
                res += ", ";
            }
        }
        res += '}';
        break;
    case DDWAF_OBJ_FLOAT:
        res += std::to_string(p.via.f64.val);
        break;
    case DDWAF_OBJ_NULL:
        res += "<null>";
        break;
    }
}
} // namespace

std::string parameter_base::debug_str() const noexcept
{
    try {
        std::string res;
        debug_str_helper(res, *&*this);
        return res;
    } catch (...) {} // NOLINT(bugprone-empty-catch)

    return {};
}

} // namespace dds
