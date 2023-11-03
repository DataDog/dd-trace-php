// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "client.hpp"
#include "exception.hpp"
#include "network/broker.hpp"
#include "network/proto.hpp"
#include "std_logging.hpp"
#include <chrono>
#include <spdlog/spdlog.h>
#include <stdexcept>
#include <string>
#include <thread>

using namespace std::chrono_literals;

namespace dds {

namespace {

template <typename M, typename... Mrest>
// NOLINTNEXTLINE(google-runtime-references)
bool maybe_exec_cmd_M(client &client, network::request &msg)
{
    if (msg.id != M::request::id) {
        if constexpr (sizeof...(Mrest) == 0) {
            SPDLOG_WARN(
                "a message of type {} ({}) was not expected at this point",
                msg.id, msg.method);
            throw unexpected_command(msg.method);
        } else {
            return maybe_exec_cmd_M<Mrest...>(client, msg);
        }
    }

    try {
        return client.handle_command(msg.as<M>());
    } catch (const std::bad_cast &e) {
        SPDLOG_WARN("invalid client message for command type {}: {}",
            msg.method, e.what());
    } catch (const std::exception &e) {
        SPDLOG_WARN("Error handling {} command: {}", msg.method, e.what());
    } catch (...) {
        SPDLOG_WARN("Unknown error handling {} command", msg.method);
    }

    return false;
}

bool send_error_response(const network::base_broker &broker)
{
    try {
        if (!broker.send(std::make_shared<network::error::response>())) {
            SPDLOG_WARN("Failed to send error response");
            return false;
        }
    } catch (const std::exception &e) {
        SPDLOG_WARN("Failed to send error response: {}", e.what());
        return false;
    }

    return true;
}

template <typename... Ms>
// NOLINTNEXTLINE(google-runtime-references)
bool handle_message(client &client, const network::base_broker &broker,
    std::chrono::milliseconds initial_timeout, bool ignore_unexpected_messages)
{
    if (spdlog::should_log(spdlog::level::debug)) {
        std::array names{Ms::request::name...};
        std::ostringstream all_names;
        std::copy(names.begin(), names.end(),
            std::ostream_iterator<std::string>(all_names, " "));
        SPDLOG_DEBUG("Wait for one of these messages: {}", all_names.str());
    }

    bool send_error = false;
    bool result = true;
    try {
        auto msg = broker.recv(initial_timeout);
        return maybe_exec_cmd_M<Ms...>(client, msg);
    } catch (const unexpected_command &e) {
        send_error = true;
        if (!ignore_unexpected_messages) {
            result = false;
        }
    } catch (const client_disconnect &) {
        SPDLOG_INFO("Client has disconnected");
        // When this exception has been received, we should stop hadling this
        // particular client.
        result = false;
    } catch (const std::out_of_range &e) {
        // The message received was too large, in theory this should've been
        // flushed and we can continue handling messages from this client,
        // however we need to report an error to ensure the client is in a good
        // state.
        SPDLOG_WARN("Failed to handle message: {}", e.what());
        send_error = true;
    } catch (const std::length_error &e) {
        // The message was partially received, the state of the socket is
        // undefined so we need to respond with an error and stop handling
        // this client.
        SPDLOG_WARN("Failed to handle message: {}", e.what());
        send_error = true;
        result = false;
    } catch (const bad_cast &e) {
        // The data received was somehow incomprehensible but we might still be
        // able to continue, so we only send an error.
        SPDLOG_WARN("Failed to handle message: {}", e.what());
        send_error = true;
    } catch (const msgpack::unpack_error &e) {
        // The data received was somehow incomprehensible or perhaps beyond
        // limits, but we might still be able to continue, so we only send an
        // error.
        SPDLOG_WARN("Failed to unpack message: {}", e.what());
        send_error = true;
    } catch (const std::exception &e) {
        SPDLOG_WARN("Failed to handle message: {}", e.what());
        result = false;
    }

    if (send_error) {
        if (!send_error_response(broker)) {
            return false;
        }
        if (result) {
            client.compute_client_status();
        }
    }

    // If we reach this point, there was a problem handling the message
    return result;
}

} // namespace

bool client::handle_command(const network::client_init::request &command)
{
    SPDLOG_DEBUG("Got client_id with pid={}, client_version={}, "
                 "runtime_version={}, service={}, engine_settings={}, "
                 "remote_config_settings={}",
        command.pid, command.client_version, command.runtime_version,
        command.service, command.engine_settings, command.rc_settings);

    auto service_id = command.service;
    auto &&eng_settings = command.engine_settings;
    DD_STDLOG(DD_STDLOG_STARTUP);

    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    std::vector<std::string> errors;
    bool has_errors = false;

    client_enabled_conf = command.enabled_configuration;
    if (service_id.runtime_id.empty()) {
        service_id.runtime_id = generate_random_uuid();
    }
    runtime_id_ = service_id.runtime_id;

    try {
        service_ = service_manager_->create_service(std::move(service_id),
            eng_settings, command.rc_settings, meta, metrics,
            !client_enabled_conf.has_value());
        if (service_) {
            // This null check is only needed due to some tests
            service_->register_runtime_id(runtime_id_);
        }
    } catch (std::system_error &e) {
        // TODO: logging should happen at WAF impl
        DD_STDLOG(DD_STDLOG_RULES_FILE_NOT_FOUND,
            eng_settings.rules_file_or_default());
        errors.emplace_back(e.what());
        has_errors = true;
    } catch (std::exception &e) {
        // TODO: logging should happen at WAF impl
        DD_STDLOG(DD_STDLOG_RULES_FILE_INVALID,
            eng_settings.rules_file_or_default(), e.what());
        errors.emplace_back(e.what());
        has_errors = true;
    }

    SPDLOG_DEBUG(
        "sending response to client_init: {}", has_errors ? "fail" : "ok");
    auto response = std::make_shared<network::client_init::response>();
    response->status = has_errors ? "fail" : "ok";
    response->errors = std::move(errors);
    response->meta = std::move(meta);
    response->metrics = std::move(metrics);

    try {
        if (!broker_->send(response)) {
            has_errors = true;
        }
    } catch (std::exception &e) {
        SPDLOG_ERROR(e.what());
        has_errors = true;
    }

    if (has_errors) {
        DD_STDLOG(DD_LOG_STARTUP_ERROR);
    }

    return !has_errors;
}

bool client::handle_command(network::request_init::request &command)
{
    if (!service_) {
        // This implies a failed client_init, we can't continue.
        SPDLOG_DEBUG("no service available on request_init");
        send_error_response(*broker_);
        return false;
    }

    if (!compute_client_status()) {
        auto response_cf =
            std::make_shared<network::config_features::response>();
        response_cf->enabled = false;

        SPDLOG_DEBUG("sending config_features to request_init");
        try {
            return broker_->send(response_cf);
        } catch (std::exception &e) {
            SPDLOG_ERROR(e.what());
        }

        return true;
    }

    // During request init we initialize the engine context
    context_.emplace(*service_->get_engine());

    SPDLOG_DEBUG("received command request_init");

    auto response = std::make_shared<network::request_init::response>();
    try {
        auto res = context_->publish(std::move(command.data));
        if (res) {
            switch (res->type) {
            case engine::action_type::block:
                response->verdict = network::verdict::block;
                response->parameters = std::move(res->parameters);
                break;
            case engine::action_type::redirect:
                response->verdict = network::verdict::redirect;
                response->parameters = std::move(res->parameters);
                break;
            case engine::action_type::record:
            default:
                response->verdict = network::verdict::record;
                response->parameters = {};
                break;
            }

            response->triggers = std::move(res->events);
            response->force_keep = res->force_keep;

            DD_STDLOG(DD_STDLOG_ATTACK_DETECTED);
        } else {
            response->verdict = network::verdict::ok;
        }
    } catch (const invalid_object &e) {
        // This error indicates some issue in either the communication with
        // the client, incompatible versions or malicious client.
        SPDLOG_ERROR("invalid data format provided by the client");
        send_error_response(*broker_);
        return false;
    } catch (const std::exception &e) {
        // Uncertain what the issue is... lets be cautious
        DD_STDLOG(DD_STDLOG_REQUEST_ANALYSIS_FAILED, e.what());
        send_error_response(*broker_);
        return false;
    }

    SPDLOG_DEBUG(
        "sending response to request_init, verdict: {}", response->verdict);
    try {
        return broker_->send(response);
    } catch (std::exception &e) {
        SPDLOG_ERROR(e.what());
    }

    return false;
}

bool client::handle_command(network::request_exec::request &command)
{
    if (!context_) {
        // A lack of context implies processing request_init failed, this
        // can happen for legitimate reasons so let's try to process the data.
        if (!service_) {
            // This implies a failed client_init, we can't continue.
            SPDLOG_DEBUG("no service available on request_exec");
            send_error_response(*broker_);
            return false;
        }

        context_.emplace(*service_->get_engine());
    }

    SPDLOG_DEBUG("received command request_exec");

    auto response = std::make_shared<network::request_exec::response>();
    try {
        auto res = context_->publish(std::move(command.data));
        if (res) {
            switch (res->type) {
            case engine::action_type::block:
                response->verdict = network::verdict::block;
                response->parameters = std::move(res->parameters);
                break;
            case engine::action_type::redirect:
                response->verdict = network::verdict::redirect;
                response->parameters = std::move(res->parameters);
                break;
            case engine::action_type::record:
            default:
                response->verdict = network::verdict::record;
                response->parameters = {};
                break;
            }

            response->triggers = std::move(res->events);
            response->force_keep = res->force_keep;

            DD_STDLOG(DD_STDLOG_ATTACK_DETECTED);
        } else {
            response->verdict = network::verdict::ok;
        }
    } catch (const invalid_object &e) {
        // This error indicates some issue in either the communication with
        // the client, incompatible versions or malicious client.
        SPDLOG_ERROR("invalid data format provided by the client");
        send_error_response(*broker_);
        return false;
    } catch (const std::exception &e) {
        // Uncertain what the issue is... lets be cautious
        DD_STDLOG(DD_STDLOG_REQUEST_ANALYSIS_FAILED, e.what());
        send_error_response(*broker_);
        return false;
    }

    SPDLOG_DEBUG(
        "sending response to request_exec, verdict: {}", response->verdict);
    try {
        return broker_->send(response);
    } catch (std::exception &e) {
        SPDLOG_ERROR(e.what());
    }

    return false;
}

bool client::compute_client_status()
{
    if (client_enabled_conf.has_value()) {
        request_enabled_ = client_enabled_conf.value();
        return request_enabled_;
    }

    if (service_ == nullptr) {
        request_enabled_ = false;
        return request_enabled_;
    }

    request_enabled_ =
        service_->get_service_config()->get_asm_enabled_status() ==
        enable_asm_status::ENABLED;

    return request_enabled_;
}

bool client::handle_command(network::config_sync::request & /* command */)
{
    if (!service_) {
        // This implies a failed client_init, we can't continue.
        SPDLOG_DEBUG("no service available on config_sync");
        send_error_response(*broker_);
        return false;
    }

    SPDLOG_DEBUG("received command config_sync");

    if (compute_client_status()) {
        auto response_cf =
            std::make_shared<network::config_features::response>();
        response_cf->enabled = true;

        SPDLOG_DEBUG("sending config_features to config_sync");
        try {
            return broker_->send(response_cf);
        } catch (std::exception &e) {
            SPDLOG_ERROR(e.what());
        }

        return true;
    }

    SPDLOG_DEBUG("sending config_sync to config_sync");
    try {
        return broker_->send(
            std::make_shared<network::config_sync::response>());
    } catch (std::exception &e) {
        SPDLOG_ERROR(e.what());
    }

    return false;
}

bool client::handle_command(network::request_shutdown::request &command)
{
    if (!context_) {
        // A lack of context implies processing request_init failed, this
        // can happen for legitimate reasons so let's try to process the data.
        if (!service_) {
            // This implies a failed client_init, we can't continue.
            SPDLOG_DEBUG("no service available on request_shutdown");
            send_error_response(*broker_);
            return false;
        }

        context_.emplace(*service_->get_engine());
    }

    SPDLOG_DEBUG("received command request_shutdown");

    // Free the context at the end of request shutdown
    auto free_ctx = defer([this]() { this->context_.reset(); });

    auto response = std::make_shared<network::request_shutdown::response>();
    try {
        auto res = context_->publish(std::move(command.data));
        if (res) {
            switch (res->type) {
            case engine::action_type::block:
                response->verdict = network::verdict::block;
                response->parameters = std::move(res->parameters);
                break;
            case engine::action_type::redirect:
                response->verdict = network::verdict::redirect;
                response->parameters = std::move(res->parameters);
                break;
            case engine::action_type::record:
            default:
                response->verdict = network::verdict::record;
                response->parameters = {};
                break;
            }

            response->triggers = std::move(res->events);
            response->force_keep = res->force_keep;

            DD_STDLOG(DD_STDLOG_ATTACK_DETECTED);
        } else {
            response->verdict = network::verdict::ok;
        }

        context_->get_meta_and_metrics(response->meta, response->metrics);
    } catch (const invalid_object &e) {
        // This error indicates some issue in either the communication with
        // the client, incompatible versions or malicious client.
        SPDLOG_ERROR("invalid data format provided by the client");
        send_error_response(*broker_);
        return false;
    } catch (const std::exception &e) {
        // Uncertain what the issue is... lets be cautious
        DD_STDLOG(DD_STDLOG_REQUEST_ANALYSIS_FAILED, e.what());
        send_error_response(*broker_);
        return false;
    }

    SPDLOG_DEBUG(
        "sending response to request_shutdown, verdict: {}", response->verdict);
    try {
        return broker_->send(response);
    } catch (std::exception &e) {
        SPDLOG_ERROR(e.what());
    }

    return false;
}

bool client::run_client_init()
{
    static constexpr auto client_init_timeout{std::chrono::milliseconds{500}};
    return handle_message<network::client_init>(
        *this, *broker_, client_init_timeout, false);
}

bool client::run_request()
{
    if (!request_enabled_) {
        return handle_message<network::request_init, network::config_sync>(
            *this, *broker_,
            std::chrono::milliseconds{0} /* no initial timeout */, true);
    }
    // TODO: figure out how to handle errors which require sending an error
    //       response to ensure the extension doesn't hang.
    return handle_message<network::request_init, network::request_exec,
        network::config_sync, network::request_shutdown>(*this, *broker_,
        std::chrono::milliseconds{0} /* no initial timeout */, true);
}

void client::run(worker::queue_consumer &q)
{
    const defer on_exit{[this]() {
        if (this->service_) {
            this->service_->unregister_runtime_id(this->runtime_id_);
        }
    }};

    if (q.running()) {
        if (!run_client_init()) {
            SPDLOG_DEBUG("Finished handling client (client_init failed)");
            return;
        }

        SPDLOG_DEBUG("Finished handling client (client_init succeded)");
    }

    while (q.running() && run_request()) {}

    SPDLOG_DEBUG("Finished handling client");
}

} // namespace dds
