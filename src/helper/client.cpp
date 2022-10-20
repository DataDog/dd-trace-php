// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "client.hpp"
#include "defer.hpp"
#include "exception.hpp"
#include "msgpack/object.h"
#include "network/broker.hpp"
#include "network/proto.hpp"
#include "std_logging.hpp"
#include <chrono>
#include <mutex>
#include <spdlog/spdlog.h>
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
            return false;
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

void send_error_response(const network::base_broker &broker)
{
    if (!broker.send(network::error::response())) {
        SPDLOG_WARN("Failed to send error response");
    }
}

template <typename... Ms>
// NOLINTNEXTLINE(google-runtime-references)
bool handle_message(client &client, const network::base_broker &broker,
    std::chrono::milliseconds initial_timeout)
{
    if (spdlog::should_log(spdlog::level::debug)) {
        std::array names{Ms::request::name...};
        std::ostringstream all_names;
        std::copy(names.begin(), names.end(),
            std::ostream_iterator<std::string>(all_names, " "));
        SPDLOG_DEBUG("Wait for one these messages: {}", all_names.str());
    }

    bool send_error = false;
    try {
        auto msg = broker.recv(initial_timeout);
        return maybe_exec_cmd_M<Ms...>(client, msg);
    } catch (const client_disconnect &) {
        SPDLOG_INFO("Client has disconnected");
    } catch (const std::length_error &e) {
        SPDLOG_WARN("Failed to handle message: {}", e.what());
        send_error = true;
    } catch (const bad_cast &e) {
        SPDLOG_WARN("Failed to handle message: {}", e.what());
        send_error = true;
    } catch (const msgpack::unpack_error &e) {
        SPDLOG_WARN("Failed to unpack message: {}", e.what());
        send_error = true;
    } catch (const std::exception &e) {
        SPDLOG_WARN("Failed to handle message: {}", e.what());
    }

    if (send_error) {
        // This can happen due to a valid error, let's continue handling
        // the client as this might just happen spuriously.
        send_error_response(broker);
        return true;
    }

    // If we reach this point, there was a problem handling the message
    return false;
}

} // namespace

bool client::handle_command(const network::client_init::request &command)
{
    SPDLOG_DEBUG("Got client_id with pid={}, client_version={}, "
                 "runtime_version={}, settings={}",
        command.pid, command.client_version, command.runtime_version,
        command.settings);

    auto &&settings = command.settings;
    DD_STDLOG(DD_STDLOG_STARTUP);

    std::map<std::string_view, std::string> meta;
    std::map<std::string_view, double> metrics;

    std::vector<std::string> errors;
    bool has_errors = false;
    try {
        engine_ = engine_pool_->create_engine(settings, meta, metrics);
    } catch (std::system_error &e) {
        // TODO: logging should happen at WAF impl
        DD_STDLOG(
            DD_STDLOG_RULES_FILE_NOT_FOUND, settings.rules_file_or_default());
        errors.emplace_back(e.what());
        has_errors = true;
    } catch (std::exception &e) {
        // TODO: logging should happen at WAF impl
        DD_STDLOG(DD_STDLOG_RULES_FILE_INVALID,
            settings.rules_file_or_default(), e.what());
        errors.emplace_back(e.what());
        has_errors = true;
    }

    SPDLOG_DEBUG(
        "sending response to client_init: {}", has_errors ? "fail" : "ok");
    network::client_init::response response;
    response.status = has_errors ? "fail" : "ok";
    response.errors = std::move(errors);
    response.meta = std::move(meta);
    response.metrics = std::move(metrics);

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
    if (!engine_) {
        // This implies a failed client_init, we can't continue.
        SPDLOG_DEBUG("no engine available on request_init");
        send_error_response(*broker_);
        return false;
    }

    // During request init we initialize the engine context
    context_.emplace(*engine_);

    SPDLOG_DEBUG("received command request_init");

    network::request_init::response response;
    try {
        auto res = context_->publish(std::move(command.data));
        if (res.value == result::code::record) {
            response.verdict = "record";
            response.triggers = std::move(res.data);
        } else if (res.value == result::code::block) {
            response.verdict = "block";
            response.triggers = std::move(res.data);
            DD_STDLOG(DD_STDLOG_ATTACK_BLOCKED);
        } else {
            response.verdict = "ok";
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
        "sending response to request_init, verdict: {}", response.verdict);
    try {
        return broker_->send(response);
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
        if (!engine_) {
            // This implies a failed client_init, we can't continue.
            SPDLOG_DEBUG("no engine available on request_shutdown");
            send_error_response(*broker_);
            return false;
        }

        // During request init we initialize the engine contex
        context_.emplace(*engine_);
    }

    SPDLOG_DEBUG("received command request_shutdown");

    // Free the context at the end of request shutdown
    auto free_ctx = defer([this]() { this->context_.reset(); });

    network::request_shutdown::response response;
    try {
        auto res = context_->publish(std::move(command.data));
        if (res.value == result::code::record) {
            response.verdict = "record";
            response.triggers = std::move(res.data);
            DD_STDLOG(DD_STDLOG_ATTACK_DETECTED);
        } else if (res.value == result::code::block) {
            response.verdict = "block";
            response.triggers = std::move(res.data);
            DD_STDLOG(DD_STDLOG_ATTACK_BLOCKED);
        } else {
            response.verdict = "ok";
        }

        context_->get_meta_and_metrics(response.meta, response.metrics);
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
        "sending response to request_shutdown, verdict: {}", response.verdict);
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
        *this, *broker_, client_init_timeout);
}

bool client::run_request()
{
    // TODO: figure out how to handle errors which require sending an error
    //       response to ensure the extension doesn't hang.
    return handle_message<network::request_init, network::request_shutdown>(
        *this, *broker_, std::chrono::milliseconds{0} /* no initial timeout */
    );
}

void client::run(worker::queue_consumer &q)
{
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
