// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "engine_settings.hpp"
#include "msgpack_helpers.hpp"
#include "remote_config/settings.hpp"
#include "service_identifier.hpp"
#include <msgpack.hpp>
#include <optional>
#include <type_traits>
#include <typeinfo>
#include <version.hpp>

using stream_packer = msgpack::packer<std::stringstream>;

namespace dds::network {

struct verdict {
    static constexpr std::string_view ok = "ok";
    static constexpr std::string_view record = "record";
    static constexpr std::string_view block = "block";
    static constexpr std::string_view redirect = "redirect";
};

using header_t = struct __attribute__((__packed__)) header {
    char code[4]{"dds"}; // dds\0 NOLINT
    uint32_t size{0};
};

enum class request_id : unsigned {
    unknown,
    client_init,
    request_init,
    request_exec,
    request_shutdown,
    config_sync
};

enum class response_id : unsigned {
    unknown,
    client_init,
    request_init,
    request_exec,
    request_shutdown,
    error,
    config_sync,
    config_features
};

struct base_request {
    base_request() = default;
    base_request(const base_request &) = default;
    base_request &operator=(const base_request &) = default;
    base_request(base_request &&) = default;
    base_request &operator=(base_request &&) = default;

    virtual ~base_request() = default;
};

struct base_response {
    base_response() = default;
    base_response(const base_response &) = default;
    base_response &operator=(const base_response &) noexcept = default;
    base_response(base_response &&) noexcept = default;
    base_response &operator=(base_response &&) = default;

    virtual ~base_response() = default;
    // NOLINTNEXTLINE(google-runtime-references)
    virtual stream_packer &pack(stream_packer &packer) const = 0;
    [[nodiscard]] virtual std::string_view get_type() const = 0;
};

template <typename T> struct base_response_generic : public base_response {
    base_response_generic() = default;
    base_response_generic(const base_response_generic &) = default;
    base_response_generic &operator=(const base_response_generic &) = default;
    base_response_generic(base_response_generic &&) noexcept = default;
    base_response_generic &operator=(
        base_response_generic &&) noexcept = default;
    ~base_response_generic() override = default;

    stream_packer &pack(stream_packer &packer) const override
    {
        return packer.pack(*static_cast<const T *>(this));
    }
};

using telemetry_metrics =
    std::unordered_map<std::string, std::tuple<double, std::string>>;

struct client_init {
    static constexpr const char *name = "client_init";
    struct request : base_request {
        static constexpr const char *name = client_init::name;
        static constexpr request_id id = request_id::client_init;

        unsigned pid{0};
        std::string client_version;
        std::string runtime_version;
        std::optional<bool> enabled_configuration;

        dds::service_identifier service;
        dds::engine_settings engine_settings;
        dds::remote_config::settings rc_settings;

        request() = default;
        request(const request &) = delete;
        request &operator=(const request &) = delete;
        request(request &&) = default;
        request &operator=(request &&) = default;
        ~request() override = default;

        MSGPACK_DEFINE(pid, client_version, runtime_version,
            enabled_configuration, service, engine_settings, rc_settings);
    };

    struct response : base_response_generic<response> {
        static constexpr response_id id = response_id::client_init;

        [[nodiscard]] std::string_view get_type() const override
        {
            return client_init::name;
        };
        std::string status;
        std::string version{dds::php_ddappsec_version};
        std::vector<std::string> errors;

        std::map<std::string, std::string> meta;
        std::map<std::string_view, double> metrics;
        std::map<std::string_view, std::vector<std::pair<double, std::string>>>
            tel_metrics;

        MSGPACK_DEFINE(status, version, errors, meta, metrics, tel_metrics);
    };
};

struct request_init {
    static constexpr const char *name = "request_init";

    struct request : base_request {
        static constexpr const char *name = request_init::name;
        static constexpr request_id id = request_id::request_init;

        dds::parameter data;

        request() = default;
        request(const request &) = delete;
        request &operator=(const request &) = delete;
        request(request &&) = default;
        request &operator=(request &&) = default;
        ~request() override = default;

        MSGPACK_DEFINE(data)
    };

    struct response : base_response_generic<response> {
        static constexpr response_id id = response_id::request_init;

        [[nodiscard]] std::string_view get_type() const override
        {
            return request_init::name;
        };
        std::string verdict;
        std::unordered_map<std::string, std::string> parameters;
        std::vector<std::string> triggers;

        bool force_keep;

        MSGPACK_DEFINE(verdict, parameters, triggers, force_keep);
    };
};

struct request_exec {
    static constexpr const char *name = "request_exec";

    struct request : base_request {
        static constexpr const char *name = request_exec::name;
        static constexpr request_id id = request_id::request_exec;

        dds::parameter data;

        request() = default;
        request(const request &) = delete;
        request &operator=(const request &) = delete;
        request(request &&) = default;
        request &operator=(request &&) = default;
        ~request() override = default;

        MSGPACK_DEFINE(data)
    };

    struct response : base_response_generic<response> {
        static constexpr response_id id = response_id::request_exec;

        [[nodiscard]] std::string_view get_type() const override
        {
            return request_exec::name;
        };
        std::string verdict;
        std::unordered_map<std::string, std::string> parameters;
        std::vector<std::string> triggers;

        bool force_keep;

        MSGPACK_DEFINE(verdict, parameters, triggers, force_keep);
    };
};

struct config_sync {
    static constexpr const char *name = "config_sync";
    struct request : base_request {
        static constexpr request_id id = request_id::config_sync;
        static constexpr const char *name = config_sync::name;

        request() = default;
        request(const request &) = delete;
        request &operator=(const request &) = delete;
        request(request &&) = default;
        request &operator=(request &&) = default;
        ~request() override = default;
        MSGPACK_DEFINE()
    };

    struct response : base_response_generic<response> {
        static constexpr response_id id = response_id::config_sync;

        [[nodiscard]] std::string_view get_type() const override
        {
            return config_sync::name;
        };

        MSGPACK_DEFINE();
    };
};

struct config_features {
    static constexpr const char *name = "config_features";
    struct response : base_response_generic<response> {
        static constexpr response_id id = response_id::config_features;

        [[nodiscard]] std::string_view get_type() const override
        {
            return config_features::name;
        };
        bool enabled;

        MSGPACK_DEFINE(enabled);
    };
};

struct request_shutdown {
    static constexpr const char *name = "request_shutdown";
    struct request : base_request {
        static constexpr const char *name = request_shutdown::name;
        static constexpr request_id id = request_id::request_shutdown;

        dds::parameter data;

        request() = default;
        request(const request &) = delete;
        request &operator=(const request &) = delete;
        request(request &&) = default;
        request &operator=(request &&) = default;
        ~request() override = default;

        MSGPACK_DEFINE(data)
    };

    struct response : base_response_generic<response> {
        static constexpr response_id id = response_id::request_shutdown;

        [[nodiscard]] std::string_view get_type() const override
        {
            return request_shutdown::name;
        };
        std::string verdict;
        std::unordered_map<std::string, std::string> parameters;
        std::vector<std::string> triggers;

        bool force_keep;

        std::map<std::string, std::string> meta;
        std::map<std::string_view, double> metrics;
        std::map<std::string_view, std::vector<std::pair<double, std::string>>>
            tel_metrics;

        MSGPACK_DEFINE(verdict, parameters, triggers, force_keep, meta, metrics,
            tel_metrics);
    };
};

// Response to be used if the incoming message could not be parsed, this
// response ensures that the extension will not be blocked waiting for a
// message.
struct error {
    static constexpr const char *name = "error";

    struct response : base_response_generic<response> {
        static constexpr response_id id = response_id::error;

        [[nodiscard]] std::string_view get_type() const override
        {
            return error::name;
        };

        MSGPACK_DEFINE();
    };
};

struct request {
    request_id id{request_id::unknown};
    std::string method;
    std::shared_ptr<base_request> arguments;

    request() = default;
    request(const request &) = default;
    request &operator=(const request &) = default;
    request(request &&) = default;
    request &operator=(request &&) = default;
    ~request() = default;

    template <typename T,
        typename = std::enable_if_t<std::is_base_of<base_request, T>::value>>
    explicit request(T &&msg)
        : id(T::id), method(T::name),
          arguments(std::make_shared<T>(std::forward<T>(msg)))
    {}

    template <typename T> typename T::request &as()
    {
        using R = typename T::request;
        if (id != R::id) {
            throw std::bad_cast();
        }
        return *static_cast<R *>(arguments.get());
    }
};

} // namespace dds::network

namespace msgpack {
MSGPACK_API_VERSION_NAMESPACE(MSGPACK_DEFAULT_API_NS) {
namespace adaptor {
template <> struct as<dds::network::request> {
    dds::network::request operator()(const msgpack::object &o) const;
};

template <> struct pack<dds::network::base_response> {
    stream_packer &operator()(
        // NOLINTNEXTLINE(google-runtime-references)
        stream_packer &o, const dds::network::base_response &v) const;
};
} // namespace adaptor
} // MSGPACK_API_VERSION_NAMESPACE(MSGPACK_DEFAULT_API_NS)
} // namespace msgpack
