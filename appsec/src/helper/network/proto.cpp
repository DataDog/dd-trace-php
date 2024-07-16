// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "proto.hpp"

// NOLINTNEXTLINE(modernize-concat-nested-namespaces)
namespace msgpack {
MSGPACK_API_VERSION_NAMESPACE(MSGPACK_DEFAULT_API_NS) {
namespace adaptor {

using dds::network::base_response;
using dds::network::client_init;
using dds::network::config_sync;
using dds::network::request;
using dds::network::request_exec;
using dds::network::request_id;
using dds::network::request_init;
using dds::network::request_shutdown;

namespace {
// NOLINTNEXTLINE(cert-err58-cpp,fuchsia-statically-constructed-objects)
const std::map<std::string_view, request_id> mapping = {
    {client_init::request::name, client_init::request::id},
    {request_init::request::name, request_init::request::id},
    {request_exec::request::name, request_exec::request::id},
    {config_sync::request::name, config_sync::request::id},
    {request_shutdown::request::name, request_shutdown::request::id}};

request_id command_name_to_id(const std::string &str)
{
    auto it = mapping.find(str);
    return (it == mapping.end() ? request_id::unknown : it->second);
}

template <typename T> auto msgpack_to_request(const msgpack::object &o)
{
    using R = typename T::request;
    try {
        return std::make_shared<R>(o.as<R>());
    } catch (...) {
        return std::make_shared<R>();
    }
}

} // namespace

request as<request>::operator()(const msgpack::object &o) const
{
    request r;
    if (o.type != msgpack::type::ARRAY || o.via.array.size != 2) {
        throw msgpack::type_error();
    }

    r.method = o.via.array.ptr[0].as<std::string>();
    r.id = command_name_to_id(r.method);
    switch (r.id) {
    case client_init::request::id:
        r.arguments = msgpack_to_request<client_init>(o.via.array.ptr[1]);
        break;
    case request_init::request::id:
        r.arguments = msgpack_to_request<request_init>(o.via.array.ptr[1]);
        break;
    case request_exec::request::id:
        r.arguments = msgpack_to_request<request_exec>(o.via.array.ptr[1]);
        break;
    case config_sync::request::id:
        r.arguments = msgpack_to_request<config_sync>(o.via.array.ptr[1]);
        break;
    case request_shutdown::request::id:
        r.arguments = msgpack_to_request<request_shutdown>(o.via.array.ptr[1]);
        break;
    default:
        break;
    }

    return r;
}

stream_packer &pack<base_response>::operator()(
    stream_packer &o, const base_response &v) const
{
    return v.pack(o);
}

} // namespace adaptor
} // MSGPACK_API_VERSION_NAMESPACE(MSGPACK_DEFAULT_API_NS)
} // namespace msgpack
