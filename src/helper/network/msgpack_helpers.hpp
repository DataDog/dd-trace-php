// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef MSGPACK_HELPERS_HPP
#define MSGPACK_HELPERS_HPP

#include "../parameter.hpp"
// NOLINTNEXTLINE: msgpack.hpp is buggy and needs an include of sstream before
#include <msgpack.hpp>
#include <sstream>

namespace msgpack {
MSGPACK_API_VERSION_NAMESPACE(MSGPACK_DEFAULT_API_NS)
{
    namespace adaptor {

    template <> struct as<dds::parameter> {
        dds::parameter operator()(const msgpack::object &o) const;
    };

    template <> struct convert<dds::parameter> {
        // NOLINTNEXTLINE(google-runtime-references)
        msgpack::object const &operator()(
            const msgpack::object &o, dds::parameter &v) const;
    };

    } // namespace adaptor
}
} // namespace msgpack
#endif
