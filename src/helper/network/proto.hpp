// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include "msgpack_helpers.hpp"
#include <iostream>
#include <msgpack.hpp>
#include <optional>
#include <type_traits>
#include <typeinfo>

using stream_packer = msgpack::packer<std::stringstream>;

namespace dds::network {

using header_t = struct __attribute__((__packed__)) header {
    char code[4]{"dds"}; // dds\0 NOLINT
    uint32_t size{0};
};

enum class request_id : unsigned {
    unknown,
    client_init,
    request_init,
    request_shutdown
};

enum class response_id : unsigned {
    unknown,
    client_init,
    request_init,
    request_shutdown
};

struct base_request {
    base_request() = default;
    base_request(const base_request&) = default;
    base_request& operator=(const base_request&) = default;
    base_request(base_request&&) = default;
    base_request& operator=(base_request&&) = default;

    virtual ~base_request() = default;
};

struct base_response {
    base_response() = default;
    base_response(const base_response&) = default;
    base_response& operator=(const base_response&) noexcept = default;
    base_response(base_response&&) noexcept = default;
    base_response& operator=(base_response&&) = default;

    virtual ~base_response() = default;
    // NOLINTNEXTLINE(google-runtime-references)
    virtual stream_packer& pack(stream_packer& packer) const = 0;
};

template <typename T>
struct base_response_generic : base_response {
    base_response_generic() = default;
    base_response_generic(const base_response_generic&) = default;
    base_response_generic& operator=(const base_response_generic&) = default;
    base_response_generic(base_response_generic&&) noexcept = default;
    base_response_generic& operator=(base_response_generic&&) noexcept = default;
    ~base_response_generic() override = default;

    stream_packer& pack(stream_packer& packer) const override {
        return packer.pack(*static_cast<const T*>(this));
    }
};

struct client_init {
    struct request : base_request {
        static constexpr const char *name = "client_init";
        static constexpr request_id id = request_id::client_init;

        unsigned pid{0};
        std::string client_version;
        std::string runtime_version;
        std::string rules_file;

        request() = default;
        request(const request &) = delete;
        request& operator=(const request&) = delete;
        request(request &&) = default;
        request& operator=(request&&) = default;
        ~request() override = default;

        MSGPACK_DEFINE(pid, client_version, runtime_version, rules_file);
    };

    struct response : base_response_generic<response> {
        static constexpr response_id id = response_id::client_init;

        std::string status;
        std::vector<std::string> errors;

        MSGPACK_DEFINE(status, errors);
    };
};

struct request_init {
    struct request : base_request {
        static constexpr const char *name = "request_init";
        static constexpr request_id id = request_id::request_init;

        dds::parameter data;

        request() = default;
        request(const request &) = delete;
        request& operator=(const request&) = delete;
        request(request &&) = default;
        request& operator=(request&&) = default;
        ~request() override { data.free(); }

        MSGPACK_DEFINE(data)
    };

    struct response : base_response_generic<response> {
        static constexpr response_id id = response_id::request_init;

        std::string verdict;
        std::vector<std::string> triggers;

        MSGPACK_DEFINE(verdict, triggers);
    };
};

struct request_shutdown {
    struct request : base_request {
        static constexpr const char *name = "request_shutdown";
        static constexpr request_id id = request_id::request_shutdown;

        dds::parameter data;

        request() = default;
        request(const request &) = delete;
        request& operator=(const request&) = delete;
        request(request &&) = default;
        request& operator=(request&&) = default;
        ~request() override { data.free(); }

        MSGPACK_DEFINE(data)
    };

    struct response : base_response_generic<response> {
        static constexpr response_id id = response_id::request_shutdown;

        std::string verdict;
        std::vector<std::string> triggers;

        MSGPACK_DEFINE(verdict, triggers);
    };
};

struct request {
    request_id id{request_id::unknown};
    std::string method;
    std::shared_ptr<base_request> arguments;

    request() = default;
    request(const request&) = default;
    request& operator=(const request&) = default;
    request(request&&) = default;
    request& operator=(request&&) = default;
    ~request() = default;

    template <typename T, typename = std::enable_if_t<std::is_base_of<base_request, T>::value>>
    explicit request(T &&msg):
        id(T::id), method(T::name),
        arguments(std::make_shared<T>(std::forward<T>(msg))) {}

    template <typename T>
    typename T::request & as() {
        using R = typename T::request;
        if (id != R::id) {
            throw std::bad_cast();
        }
        return *static_cast<R*>(arguments.get());
    }
};

} // namespace dds::network

namespace msgpack {
MSGPACK_API_VERSION_NAMESPACE(MSGPACK_DEFAULT_API_NS) {
namespace adaptor {
template <>
struct as<dds::network::request> {
    dds::network::request operator()(const msgpack::object& o) const;
};

template<>
struct pack<dds::network::base_response> {
    // NOLINTNEXTLINE(google-runtime-references)
    stream_packer& operator()(stream_packer& o, const dds::network::base_response& v) const;
};
} // namespace adaptor
}
} // namespace msgpack
