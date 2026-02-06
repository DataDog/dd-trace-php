// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "service.hpp"
#include "ffi.hpp"
#include "metrics.hpp"
#include "sidecar_settings.hpp"
#include <common.h>

#include <utility>

extern "C" {
#include <dlfcn.h>
}

using CharSlice = ddog_Slice_CChar;

SIDECAR_FFI_SYMBOL(ddog_sidecar_connect);
SIDECAR_FFI_SYMBOL(ddog_sidecar_ping);
SIDECAR_FFI_SYMBOL(ddog_sidecar_transport_drop);

SIDECAR_FFI_SYMBOL(ddog_sidecar_enqueue_telemetry_log);
SIDECAR_FFI_SYMBOL(ddog_sidecar_enqueue_telemetry_point);
SIDECAR_FFI_SYMBOL(ddog_sidecar_enqueue_telemetry_metric);

SIDECAR_FFI_SYMBOL(ddog_Error_message);
SIDECAR_FFI_SYMBOL(ddog_Error_drop);

namespace {
inline CharSlice to_ffi_string(std::string_view sv)
{
    return {sv.data(), sv.length()};
}

bool wait_for_sidecar_ready();

} // namespace

namespace dds {
service::service(std::shared_ptr<engine> engine,
    std::shared_ptr<service_config> service_config,
    std::unique_ptr<dds::remote_config::client_handler> client_handler,
    std::shared_ptr<metrics_impl> msubmitter, std::string rc_path,
    telemetry_settings telemetry_settings,
    const schema_extraction_settings &schema_extraction_settings)
    : engine_{std::move(engine)}, service_config_{std::move(service_config)},
      client_handler_{std::move(client_handler)},
      schema_extraction_enabled_{schema_extraction_settings.enabled},
      schema_sampler_{
          schema_extraction_settings.enabled &&
                  schema_extraction_settings.sampling_period >= 1.0
              ? std::make_optional<sampler>(static_cast<std::uint32_t>(
                    schema_extraction_settings.sampling_period))
              : std::nullopt},
      rc_path_{std::move(rc_path)},
      telemetry_settings_{std::move(telemetry_settings)},
      metrics_registered_at_{
          std::chrono::steady_clock::now() - std::chrono::years(1)},
      msubmitter_{std::move(msubmitter)}
{
    // The engine should always be valid
    if (!engine_) {
        throw std::runtime_error("invalid engine");
    }

    if (client_handler_) {
        client_handler_->poll();
    }
}

std::shared_ptr<service> service::from_settings(
    const dds::engine_settings &eng_settings,
    const remote_config::settings &rc_settings,
    telemetry_settings telemetry_settings)
{
    std::shared_ptr<metrics_impl> msubmitter = std::make_shared<metrics_impl>();

    const std::shared_ptr<engine> engine_ptr =
        engine::from_settings(eng_settings, *msubmitter);

    auto service_config = std::make_shared<dds::service_config>();

    auto client_handler = remote_config::client_handler::from_settings(
        eng_settings, service_config, rc_settings, engine_ptr, msubmitter);

    return create_shared(engine_ptr, std::move(service_config),
        std::move(client_handler), std::move(msubmitter),
        rc_settings.shmem_path, std::move(telemetry_settings),
        eng_settings.schema_extraction);
}

void service::metrics_impl::submit_log(const sidecar_settings &sc_settings,
    const telemetry_settings &telemetry_settings, const tel_log &log)
{
    SPDLOG_DEBUG("submit_log (ffi): [{}][{}]: {}", log.level, log.identifier,
        log.message);

    if (!is_sidecar_ready()) {
        SPDLOG_DEBUG("Sidecar is not ready, skipping log submission");
        return;
    }

    CharSlice const session_id_ffi = to_ffi_string(sc_settings.session_id);
    CharSlice const runtime_id_ffi = to_ffi_string(sc_settings.runtime_id);
    CharSlice const service_name_ffi =
        to_ffi_string(telemetry_settings.service_name);
    CharSlice const env_name_ffi = to_ffi_string(telemetry_settings.env_name);

    CharSlice const identifier_ffi = to_ffi_string(log.identifier);
    ddog_LogLevel c_level = DDOG_LOG_LEVEL_DEBUG; // Default to Debug
    switch (log.level) {
    case telemetry::telemetry_submitter::log_level::Error:
        c_level = DDOG_LOG_LEVEL_ERROR;
        break;
    case telemetry::telemetry_submitter::log_level::Warn:
        c_level = DDOG_LOG_LEVEL_WARN;
        break;
    case telemetry::telemetry_submitter::log_level::Debug:
        c_level = DDOG_LOG_LEVEL_DEBUG;
        break;
    }
    CharSlice const message_ffi = to_ffi_string(log.message);

    CharSlice stack_trace_ffi_struct;
    CharSlice *stack_trace_ffi_ptr = nullptr;
    if (log.stack_trace.has_value()) {
        stack_trace_ffi_struct = to_ffi_string(*log.stack_trace);
        stack_trace_ffi_ptr = &stack_trace_ffi_struct;
    }

    CharSlice tags_ffi_struct;
    CharSlice *tags_ffi_ptr = nullptr;
    if (log.tags.has_value()) {
        tags_ffi_struct = to_ffi_string(*log.tags);
        tags_ffi_ptr = &tags_ffi_struct;
    }

    ddog_MaybeError result =
        ffi::ddog_sidecar_enqueue_telemetry_log(session_id_ffi, runtime_id_ffi,
            service_name_ffi, env_name_ffi, identifier_ffi, c_level,
            message_ffi, stack_trace_ffi_ptr, tags_ffi_ptr, log.is_sensitive);

    if (result.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
        ddog_CharSlice const error_msg = ffi::ddog_Error_message(&result.some);
        SPDLOG_INFO("Failed to enqueue telemetry log, error: {}",
            std::string_view{error_msg.ptr, error_msg.len});
        ffi::ddog_Error_drop(&result.some);
    } else {
        SPDLOG_DEBUG("Sent telemetry log via sidecar-ffi: {}: {}",
            log.identifier, log.message);
    }
}

void service::metrics_impl::register_metric_ffi(
    const sidecar_settings &sc_settings,
    const telemetry_settings &telemetry_settings, std::string_view name,
    ddog_MetricType type)
{
    SPDLOG_TRACE("register_metric_ffi: name: {}, type: {}", name, type);

    if (!is_sidecar_ready()) {
        SPDLOG_DEBUG("Sidecar is not ready, skipping metric registration");
        return;
    }

    ddog_MaybeError result = ffi::ddog_sidecar_enqueue_telemetry_metric(
        to_ffi_string(sc_settings.session_id),
        to_ffi_string(sc_settings.runtime_id),
        to_ffi_string(telemetry_settings.service_name),
        to_ffi_string(telemetry_settings.env_name), to_ffi_string(name), type,
        DDOG_METRIC_NAMESPACE_APPSEC);

    if (result.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
        ddog_CharSlice const error_msg = ffi::ddog_Error_message(&result.some);
        SPDLOG_INFO("Failed to register telemetry metric, error: {}",
            std::string_view{error_msg.ptr, error_msg.len});
        ffi::ddog_Error_drop(&result.some);
    } else {
        SPDLOG_DEBUG(
            "Registered telemetry metric via sidecar-ffi: {} of type {}", name,
            type);
    }
}

void service::metrics_impl::submit_metric_ffi(
    const sidecar_settings &sc_settings,
    const telemetry_settings &telemetry_settings, std::string_view name,
    double value, std::optional<std::string> tags)
{
    SPDLOG_TRACE("submit_metric_ffi: name: {}, value: {}, tags: {}", name,
        value, tags.has_value() ? tags.value() : "(none)"sv);

    if (!is_sidecar_ready()) {
        SPDLOG_DEBUG("Sidecar is not ready, skipping metric submission");
        return;
    }

    CharSlice tags_ffi;
    CharSlice *tags_ffi_ptr = nullptr;
    if (tags.has_value()) {
        tags_ffi = to_ffi_string(*tags);
        tags_ffi_ptr = &tags_ffi;
    }
    ddog_MaybeError result = ffi::ddog_sidecar_enqueue_telemetry_point(
        to_ffi_string(sc_settings.session_id),
        to_ffi_string(sc_settings.runtime_id),
        to_ffi_string(telemetry_settings.service_name),
        to_ffi_string(telemetry_settings.env_name), to_ffi_string(name), value,
        tags_ffi_ptr);

    if (result.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
        ddog_CharSlice const error_msg = ffi::ddog_Error_message(&result.some);
        SPDLOG_INFO("Failed to enqueue telemetry point, error: {}",
            std::string_view{error_msg.ptr, error_msg.len});
        ffi::ddog_Error_drop(&result.some);
    } else {
        SPDLOG_DEBUG("Sent telemetry point via sidecar-ffi: {} of value {}",
            name, value);
    }
}

bool service::metrics_impl::is_sidecar_ready()
{
    auto val = sidecar_status_.load(std::memory_order_relaxed);

    if (val == sidecar_status::READY) {
        return true;
    }

    if (val == sidecar_status::FAILED) {
        return false;
    }

    bool const wait_result = wait_for_sidecar_ready();
    if (wait_result) {
        sidecar_status_.store(sidecar_status::READY, std::memory_order_relaxed);
        return true;
    }

    sidecar_status_.store(sidecar_status::FAILED, std::memory_order_relaxed);
    return false;
}

void service::register_known_metrics(const sidecar_settings &sc_settings,
    const telemetry_settings &telemetry_settings)
{
    static constexpr std::chrono::minutes kRegisterInterval = 25min;
    // register known metrics every 25 minutes
    // libdatadog has a cleanup task that deletes TelemetryCachedClients
    // if they are 30 minutes without being used
    auto registered = metrics_registered_at_.load(std::memory_order_relaxed);
    auto now = std::chrono::steady_clock::now();
    if (registered + kRegisterInterval > now) {
        return;
    }
    if (!metrics_registered_at_.compare_exchange_strong(
            registered, now, std::memory_order_relaxed)) {
        SPDLOG_DEBUG("Metrics concurrent registration attempt");
        return;
    }

    for (auto &&metric : metrics::known_metrics) {
        metrics_impl::register_metric_ffi(
            sc_settings, telemetry_settings, metric.name, metric.type);
    }
}

void service::handle_worker_count_metrics(const sidecar_settings &sc_settings)
{
    auto cur_st = num_workers_.load(std::memory_order_relaxed);
    if (cur_st.latest_count_sent) {
        return;
    }

    metrics_impl::submit_metric_ffi(sc_settings, telemetry_settings_,
        metrics::helper_worker_count, static_cast<double>(cur_st.count),
        std::nullopt);

    auto new_st = cur_st;
    new_st.latest_count_sent = true;

    bool const success = num_workers_.compare_exchange_strong(
        cur_st, new_st, std::memory_order_relaxed);

    if (!success) {
        SPDLOG_DEBUG("Worker count changed while submitting metric point; "
                     "latest metric is not up to date");
    }
}

} // namespace dds

namespace {

bool wait_for_sidecar_ready()
{
    constexpr int max_attempts = 50;
    constexpr int sleep_ms = 100;

    for (int attempt = 0; attempt < max_attempts; ++attempt) {
        ddog_SidecarTransport *transport = nullptr;
        ddog_MaybeError const connect_result =
            dds::ffi::ddog_sidecar_connect(&transport);

        if (connect_result.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
            SPDLOG_DEBUG(
                "Sidecar not ready yet (attempt {}), waiting...", attempt + 1);
            std::this_thread::sleep_for(std::chrono::milliseconds(sleep_ms));
            continue;
        }

        ddog_MaybeError ping_result = dds::ffi::ddog_sidecar_ping(&transport);
        dds::ffi::ddog_sidecar_transport_drop(transport);

        if (ping_result.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
            auto error_message =
                dds::ffi::ddog_Error_message(&ping_result.some);
            SPDLOG_DEBUG(
                "Sidecar ping failed with error {} (attempt {}), waiting...",
                dds::to_sv(error_message), attempt + 1);
            std::this_thread::sleep_for(std::chrono::milliseconds(sleep_ms));
            dds::ffi::ddog_Error_drop(&ping_result.some);
            continue;
        }

        SPDLOG_INFO("Sidecar is ready after {} attempts", attempt + 1);
        return true;
        ;
    }

    SPDLOG_WARN(
        "Sidecar did not become ready after {} attempts, not trying again",
        max_attempts);
    return false;
}
} // namespace
