// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include <chrono>
#include <map>
#include <spdlog/spdlog.h>
#include <stdexcept>
#include <string>

#include "action.hpp"
#include "client.hpp"
#include "exception.hpp"
#include "network/broker.hpp"
#include "network/proto.hpp"
#include "service.hpp"
#include "service_config.hpp"
#include "std_logging.hpp"
#include "telemetry.hpp"

using namespace std::chrono_literals;

namespace dds {

namespace {

void collect_metrics(network::request_shutdown::response &response,
    service &service, std::optional<engine::context> &context, const sidecar_settings &sc_settings);
void collect_metrics(network::client_init::response &response, service &service,
    std::optional<engine::context> &context, const sidecar_settings &sc_settings);

template <typename M, typename... Mrest>
// NOLINTNEXTLINE(google-runtime-references)
bool maybe_exec_cmd_M(client &client, network::request &msg)
{
    if (msg.id != M::request::id) {
        if constexpr (sizeof...(Mrest) == 0) {
            SPDLOG_WARN(
                "a message of type {} ({}) was not expected at this point",
                static_cast<unsigned>(msg.id), msg.method);
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
        SPDLOG_DEBUG("Unexpected command: {}", e.what());
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
                 "runtime_version={}, engine_settings={}, "
                 "remote_config_settings={}, sidecar_settings={}",
        command.pid, command.client_version, command.runtime_version,
        command.engine_settings, command.rc_settings, command.sc_settings);

    auto &&eng_settings = command.engine_settings;
    DD_STDLOG(DD_STDLOG_STARTUP);

    std::vector<std::string> errors;
    bool has_errors = false;

    client_enabled_conf = command.enabled_configuration;

    try {
        set_service(service_manager_->get_or_create_service(
            eng_settings, command.rc_settings, command.telemetry_settings));

        // save engine settings so we can recreate the service if rc path
        // changes
        engine_settings_ = eng_settings;

        // sidecar settings (session/runtime id)should not change
        sc_settings_ = command.sc_settings;
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

    if (!service_.is_empty()) {
        // may be null in testing
        collect_metrics(*response, *service_, context_, sc_settings_);
    }

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

template <typename T> bool client::service_guard()
{
    if (service_.is_empty()) {
        // This implies a failed client_init, we can't continue.
        SPDLOG_DEBUG("no service available on {}", T::name);
        send_error_response(*broker_);
        return false;
    }

    return true;
}

template <typename T>
std::shared_ptr<typename T::response> client::publish(
    typename T::request &command, const std::string &rasp_rule)
{
    SPDLOG_DEBUG("received command {}", T::name);

    auto response = std::make_shared<typename T::response>();
    try {
        // NOLINTNEXTLINE(bugprone-unchecked-optional-access)
        auto res = context_->publish(std::move(command.data), rasp_rule);
        if (res) {
            bool event_action = false;
            bool stack_trace = false;
            for (auto &act : res->actions) {
                dds::network::action_struct new_action;
                switch (act.type) {
                case dds::action_type::block:
                    new_action.verdict = network::verdict::block;
                    new_action.parameters = std::move(act.parameters);
                    event_action = true;
                    break;
                case dds::action_type::redirect:
                    new_action.verdict = network::verdict::redirect;
                    new_action.parameters = std::move(act.parameters);
                    event_action = true;
                    break;
                case dds::action_type::stack_trace:
                    stack_trace = true;
                    new_action.verdict = network::verdict::stack_trace;
                    new_action.parameters = std::move(act.parameters);
                    break;
                case dds::action_type::record:
                default:
                    event_action = true;
                    new_action.verdict = network::verdict::record;
                    new_action.parameters = {};
                    break;
                }
                response->actions.push_back(new_action);
            }
            if (!event_action && stack_trace) {
                // Stacktrace needs to send a record as well so Appsec event is
                // generated
                dds::network::action_struct extra_record_action;
                extra_record_action.verdict = network::verdict::record;
                extra_record_action.parameters = {};
                response->actions.push_back(extra_record_action);
            }
            response->triggers = std::move(res->triggers);
            response->force_keep = res->force_keep;

            DD_STDLOG(DD_STDLOG_ATTACK_DETECTED);
        } else {
            dds::network::action_struct new_action;
            new_action.verdict = network::verdict::ok;
            response->actions.push_back(new_action);
        }
    } catch (const invalid_object &e) {
        // This error indicates some issue in either the communication with
        // the client, incompatible versions or malicious client.
        SPDLOG_ERROR("invalid data format provided by the client");
        send_error_response(*broker_);
        return nullptr;
    } catch (const std::exception &e) {
        // Uncertain what the issue is... lets be cautious
        DD_STDLOG(DD_STDLOG_REQUEST_ANALYSIS_FAILED, e.what());
        send_error_response(*broker_);
        return nullptr;
    }

    return response;
}

bool client::handle_command(network::request_init::request &command)
{
    if (!service_guard<network::request_init>()) {
        return false;
    }

    if (!compute_client_status()) {
        auto response_cf =
            std::make_shared<network::config_features::response>();
        response_cf->enabled = false;

        SPDLOG_DEBUG("sending config_features to request_init");
        return send_message<network::config_features, false>(response_cf);
    }

    // During request init we initialize the engine context
    context_.emplace(*service_->get_engine());

    auto response = publish<network::request_init>(command);
    if (response) {
        response->settings["auto_user_instrum"] = to_string_view(
            service_->get_service_config()->get_auto_user_intrum_mode());
    }

    return send_message<network::request_init>(response);
}

bool client::handle_command(network::request_exec::request &command)
{
    if (!context_) {
        if (!service_guard<network::request_exec>()) {
            return false;
        }

        context_.emplace(*service_->get_engine());
    }

    auto response = publish<network::request_exec>(command, command.rasp_rule);
    return send_message<network::request_exec>(response);
}

bool client::compute_client_status()
{
    if (client_enabled_conf.has_value()) {
        request_enabled_ = client_enabled_conf.value();
        return request_enabled_;
    }

    if (service_.is_empty()) {
        request_enabled_ = false;
        return request_enabled_;
    }

    request_enabled_ =
        service_->get_service_config()->get_asm_enabled_status() ==
        enable_asm_status::ENABLED;

    return request_enabled_;
}

bool client::handle_command(network::config_sync::request &command)
{
    if (!service_guard<network::config_sync>()) {
        return false;
    }

    SPDLOG_DEBUG("received command config_sync with rem cfg path {} and "
                 "telemetry settings {}",
        command.rem_cfg_path, command.telemetry_settings);

    service_->drain_logs(sc_settings_);

    update_settings(command.rem_cfg_path, command.telemetry_settings);

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

    SPDLOG_DEBUG("sending response to config_sync");
    try {
        return broker_->send(
            std::make_shared<network::config_sync::response>());
    } catch (std::exception &e) {
        SPDLOG_ERROR(e.what());
    }

    return false;
}

template <typename T, bool actions>
bool client::send_message(const std::shared_ptr<typename T::response> &message)
{
    if (!message) {
        return false;
    }

    if (spdlog::should_log(spdlog::level::debug)) {
        // NOLINTNEXTLINE(misc-const-correctness)
        std::ostringstream all_verdicts;
        if constexpr (actions) {
            for (const auto &action : message->actions) {
                all_verdicts << action.verdict << " ";
            }
            if (message->actions.empty()) {
                all_verdicts << "no verdicts";
            }
        }
        // NOLINTNEXTLINE(misc-const-correctness)
        std::string force_keep = "not provided";
        if constexpr (std::is_same_v<typename T::response,
                          network::request_init::response> ||
                      std::is_same_v<typename T::response,
                          network::request_exec::response> ||
                      std::is_same_v<typename T::response,
                          network::request_shutdown::response>) {
            if (message->force_keep) {
                force_keep = "true";
            } else {
                force_keep = "false";
            }
        }
        SPDLOG_DEBUG("sending response to {}, verdicts: {}, force_keep: {}",
            message->get_type(), all_verdicts.str(), force_keep);
    }
    try {
        return broker_->send(message);
    } catch (std::exception &e) {
        SPDLOG_ERROR(e.what());
    }
    return false;
}

bool client::handle_command(network::request_shutdown::request &command)
{
    if (!context_) {
        if (!service_guard<network::request_shutdown>()) {
            return false;
        }

        context_.emplace(*service_->get_engine());
    }

    // Free the context at the end of request shutdown
    auto free_ctx = defer([this]() { this->context_.reset(); });

    std::uint64_t const sample_key = command.api_sec_samp_key;
    if (sample_key != 0 && service_->schema_extraction_enabled() &&
        (!sample_acc_ || sample_acc_->hit(sample_key))) {
        parameter context_processor = parameter::map();
        context_processor.add("extract-schema", parameter::as_boolean(true));
        command.data.add("waf.context.processor", std::move(context_processor));
    }

    auto response = publish<network::request_shutdown>(command);
    if (!response) {
        return false;
    }

    collect_metrics(*response, *service_, context_, sc_settings_);
    service_->drain_logs(sc_settings_);

    return send_message<network::request_shutdown>(response);
}

void client::update_settings(
    std::string_view rc_path, const telemetry_settings &telemetry_settings)
{
    if (!engine_settings_.has_value()) {
        return;
    }

    if (service_->is_remote_config_shmem_path(rc_path) &&
        service_->is_telemetry_settings(telemetry_settings)) {
        // nothing has changed
        return;
    }

    remote_config::settings rc_settings;
    if (rc_path.empty()) {
        SPDLOG_INFO("Remote config path is empty, recreating service with "
                    "disabled remote config and telemetry settings={}",
            telemetry_settings);
        rc_settings.enabled = false;
    } else {
        SPDLOG_INFO("Remote config path changed to {}, recreating service with "
                    "telemetry settings={}",
            rc_path, telemetry_settings);
        rc_settings.enabled = true;
        rc_settings.shmem_path = std::string{rc_path};
    }

    std::shared_ptr<service> new_service =
        service_manager_->get_or_create_service(
            *engine_settings_, rc_settings, telemetry_settings);

    set_service(std::move(new_service));
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

namespace {

struct request_metrics_submitter : public telemetry::telemetry_submitter {
    request_metrics_submitter() = default;
    ~request_metrics_submitter() override = default;
    request_metrics_submitter(const request_metrics_submitter &) = delete;
    request_metrics_submitter &operator=(
        const request_metrics_submitter &) = delete;
    request_metrics_submitter(request_metrics_submitter &&) = delete;
    request_metrics_submitter &operator=(request_metrics_submitter &&) = delete;

    void submit_metric(std::string_view name, double value,
        telemetry::telemetry_tags tags) override
    {
        std::string tags_s = tags.consume();
        SPDLOG_TRACE("submit_metric [req]: name={}, value={}, tags={}", name,
            value, tags_s);
        tel_metrics[name].emplace_back(value, std::move(tags_s));
    };
    void submit_span_metric(std::string_view name, double value) override
    {
        SPDLOG_TRACE(
            "submit_span_metric [req]: name={}, value={}", name, value);
        metrics[name] = value;
    };
    void submit_span_meta(std::string_view name, std::string value) override
    {
        SPDLOG_TRACE("submit_span_meta [req]: name={}, value={}", name, value);
        meta[std::string{name}] = value;
    };
    void submit_span_meta_copy_key(std::string name, std::string value) override
    {
        SPDLOG_TRACE(
            "submit_span_meta_copy_key [req]: name={}, value={}", name, value);
        meta[name] = value;
    }

    void submit_log(telemetry::telemetry_submitter::log_level /*level*/,
        std::string /*identifier*/, std::string /*message*/,
        std::optional<std::string> /*stack_trace*/,
        std::optional<std::string> /*tags*/, bool /*is_sensitive*/) override
    {
        // this class only exists to collect metrics, not logs
        SPDLOG_WARN("submit_log [req]: should not be called");
    }

    std::map<std::string, std::string> meta;
    std::map<std::string_view, double> metrics;
    std::unordered_map<std::string_view,
        std::vector<std::pair<double, std::string>>>
        tel_metrics;
};

template <typename Response>
void collect_metrics_impl(Response &response, service &service,
    std::optional<engine::context> &context, const sidecar_settings &sc_settings)
{
    request_metrics_submitter msubmitter{};
    if (context) {
        context->get_metrics(msubmitter);
    }

    auto request_metrics = std::move(msubmitter.tel_metrics);
    for (auto &metric : request_metrics) {
        for (auto &[value, tags] : metric.second) {
            service.submit_request_metric(metric.first, value,
                telemetry::telemetry_tags::from_string(std::move(tags)));
        }
    }
    service.drain_metrics(sc_settings,
        // metrics for the extension are put back into the msubmitter
        [&msubmitter](std::string_view name, double value, auto tags) {
            msubmitter.submit_metric(name, value, std::move(tags));
        });
    msubmitter.metrics.merge(service.drain_legacy_metrics());
    msubmitter.meta.merge(service.drain_legacy_meta());
    response.tel_metrics = std::move(msubmitter.tel_metrics);
    response.meta = std::move(msubmitter.meta);
    response.metrics = std::move(msubmitter.metrics);
}
void collect_metrics(network::request_shutdown::response &response,
    service &service, std::optional<engine::context> &context, const sidecar_settings &sc_settings)
{
    collect_metrics_impl(response, service, context, sc_settings);
}
void collect_metrics(network::client_init::response &response, service &service,
    std::optional<engine::context> &context, const sidecar_settings &sc_settings)
{
    collect_metrics_impl(response, service, context, sc_settings);
}
} // namespace

} // namespace dds
