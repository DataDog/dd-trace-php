// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "msgpack_helpers.hpp"

// NOLINTNEXTLINE(modernize-concat-nested-namespaces)
namespace msgpack {
MSGPACK_API_VERSION_NAMESPACE(MSGPACK_DEFAULT_API_NS) {
namespace adaptor {

namespace {
constexpr unsigned max_depth = 20;

// NOLINTNEXTLINE(misc-no-recursion)
dds::parameter msgpack_to_param(const msgpack::object &o, unsigned depth = 0)
{
    if (depth++ >= max_depth) {
        return {};
    }

    switch (o.type) {
    case msgpack::type::ARRAY: {
        dds::parameter p = dds::parameter::array();
        const msgpack::object_array &array = o.via.array;
        for (uint32_t i = 0; i < array.size; i++) {
            const msgpack::object &item = array.ptr[i];
            p.add(msgpack_to_param(item, depth));
        }
        return p;
    }
    case msgpack::type::MAP: {
        dds::parameter p = dds::parameter::map();
        const msgpack::object_map &map = o.via.map;
        for (uint32_t i = 0; i < map.size; i++) {
            const msgpack::object_kv &kv = map.ptr[i];
            // Assume keys are strings
            p.add(
                kv.key.as<std::string_view>(), msgpack_to_param(kv.val, depth));
        }
        return p;
    }
    case msgpack::type::STR:
        return dds::parameter::string(o.as<std::string_view>());
    case msgpack::type::BOOLEAN:
        return dds::parameter::as_boolean(o.as<bool>());
    case msgpack::type::FLOAT64:
        return dds::parameter::float64(o.as<float>());
    case msgpack::type::POSITIVE_INTEGER:
        return dds::parameter::uint64(o.as<uint64_t>());
    case msgpack::type::NEGATIVE_INTEGER:
        return dds::parameter::int64(o.as<int64_t>());
    case msgpack::type::NIL:
        return dds::parameter::null();
    default:
        break;
    }

    return {};
}
} // namespace

dds::parameter as<dds::parameter>::operator()(const msgpack::object &o) const
{
    return msgpack_to_param(o);
}

msgpack::object const &convert<dds::parameter>::operator()(
    msgpack::object const &o, dds::parameter &v) const
{
    v = msgpack_to_param(o);
    return o;
}

} // namespace adaptor
} // MSGPACK_API_VERSION_NAMESPACE(MSGPACK_DEFAULT_API_NS)
} // namespace msgpack
