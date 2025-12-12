// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "service.hpp"
#include "metrics.hpp"
#include "sidecar_settings.hpp"
#include <common.h>

#include <utility>

extern "C" {
#include <dlfcn.h>
// push -Wno-nested-anon-types and -Wno-gnu-anonymous-struct on clang
#if defined(__clang__)
#    pragma clang diagnostic push
#    pragma clang diagnostic ignored "-Wnested-anon-types"
#    pragma clang diagnostic ignored "-Wgnu-anonymous-struct"
#endif
#include <sidecar.h>
#if defined(__clang__)
#    pragma clang diagnostic pop
#endif
}

using CharSlice = ddog_Slice_CChar;

namespace {
inline CharSlice to_ffi_string(std::string_view sv)
{
    return {sv.data(), sv.length()};
}

using ddog_sidecar_enqueue_telemetry_log_t =
    decltype(&ddog_sidecar_enqueue_telemetry_log);
ddog_sidecar_enqueue_telemetry_log_t fn_ddog_sidecar_enqueue_telemetry_log;

using ddog_sidecard_enqueue_telemetry_point_t = decltype(&ddog_sidecar_enqueue_telemetry_point);
ddog_sidecard_enqueue_telemetry_point_t fn_ddog_sidecar_enqueue_telemetry_point;

using ddog_sidecar_enqueue_telemetry_metric_t = decltype(&ddog_sidecar_enqueue_telemetry_metric);
ddog_sidecar_enqueue_telemetry_metric_t fn_ddog_sidecar_enqueue_telemetry_metric;

using ddog_Error_message_t = decltype(&ddog_Error_message);
ddog_Error_message_t fn_ddog_Error_message;

using ddog_Error_drop_t = decltype(&ddog_Error_drop);
ddog_Error_drop_t fn_ddog_Error_drop;
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

    if (fn_ddog_sidecar_enqueue_telemetry_log == nullptr) {
        SPDLOG_WARN(
            "ddog_sidecar_enqueue_telemetry_log function pointer is null. "
            "Symbol resolution likely failed.");
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
        fn_ddog_sidecar_enqueue_telemetry_log(session_id_ffi, runtime_id_ffi,
            service_name_ffi, env_name_ffi, identifier_ffi, c_level,
            message_ffi, stack_trace_ffi_ptr, tags_ffi_ptr, log.is_sensitive);

    if (result.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
        ddog_CharSlice const error_msg = fn_ddog_Error_message(&result.some);
        SPDLOG_INFO("Failed to enqueue telemetry log, error: {}",
            std::string_view{error_msg.ptr, error_msg.len});
        fn_ddog_Error_drop(&result.some);
        return;
    }
}

void service::metrics_impl::register_metric_ffi(
    const sidecar_settings &sc_settings,
    const telemetry_settings &telemetry_settings,
    std::string_view name, ddog_MetricType type)
{
    SPDLOG_TRACE("register_metric_ffi: name: {}, type: {}", name, type);

    if (fn_ddog_sidecar_enqueue_telemetry_metric == nullptr) {
        throw std::runtime_error("Failed to resolve ddog_sidecar_enqueue_telemetry_metric");
    }
    ddog_MaybeError result = fn_ddog_sidecar_enqueue_telemetry_metric(
        to_ffi_string(sc_settings.session_id),
        to_ffi_string(sc_settings.runtime_id),
        to_ffi_string(telemetry_settings.service_name),
        to_ffi_string(telemetry_settings.env_name),
        to_ffi_string(name),
        type,
        DDOG_METRIC_NAMESPACE_APPSEC
    );

    if (result.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
        ddog_CharSlice const error_msg = fn_ddog_Error_message(&result.some);
        SPDLOG_INFO("Failed to register telemetry metric, error: {}",
            std::string_view{error_msg.ptr, error_msg.len});
        fn_ddog_Error_drop(&result.some);
    }
}

void service::metrics_impl::submit_metric_ffi(
    const sidecar_settings &sc_settings,
    const telemetry_settings &telemetry_settings,
    std::string_view name,
    double value,
    std::optional<std::string> tags)
{
    SPDLOG_TRACE(
        "submit_metric_ffi: name: {}, value: {}, tags: {}", name,
        value, tags.has_value() ? tags.value() : "(none)"sv);

    if (fn_ddog_sidecar_enqueue_telemetry_point == nullptr) {
        throw std::runtime_error("Failed to resolve ddog_sidecar_enqueue_telemetry_point");
    }
    CharSlice tags_ffi;
    CharSlice *tags_ffi_ptr = nullptr;
    if (tags.has_value()) {
        tags_ffi = to_ffi_string(*tags);
        tags_ffi_ptr = &tags_ffi;
    }
    ddog_MaybeError result = fn_ddog_sidecar_enqueue_telemetry_point(
        to_ffi_string(sc_settings.session_id),
        to_ffi_string(sc_settings.runtime_id),
        to_ffi_string(telemetry_settings.service_name),
        to_ffi_string(telemetry_settings.env_name),
        to_ffi_string(name),
        value,
        tags_ffi_ptr
    );

    if (result.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
        ddog_CharSlice const error_msg = fn_ddog_Error_message(&result.some);
        SPDLOG_INFO("Failed to enqueue telemetry point, error: {}",
            std::string_view{error_msg.ptr, error_msg.len});
        fn_ddog_Error_drop(&result.some);
    }
}

void service::register_known_metrics(const sidecar_settings &sc_settings,
    const telemetry_settings &telemetry_settings)
{
    for (auto &&metric : metrics::known_metrics) {
        msubmitter_->register_metric_ffi(
            sc_settings, telemetry_settings, metric.name, metric.type);
    }
}

void service::handle_worker_count_metrics(const sidecar_settings &sc_settings)
{
    auto cur_st = num_workers_.load(std::memory_order_relaxed);
    if (cur_st.latest_count_sent) {
        return;
    }

    msubmitter_->submit_metric_ffi(sc_settings, telemetry_settings_,
        metrics::helper_worker_count, static_cast<double>(cur_st.count),
        std::nullopt);

    auto new_st = cur_st;
    new_st.latest_count_sent = true;

    bool success = num_workers_.compare_exchange_strong(
        cur_st, new_st, std::memory_order_relaxed);

    if (!success) {
        SPDLOG_DEBUG("Worker count changed while submitting metric point; "
                     "latest metric is not up to date");
    }
}

// NOLINTNEXTLINE(cppcoreguidelines-macro-usage)
#define RESOLVE_FFI_SYMBOL(symbol_name)                                        \
    do {                                                                       \
        if (fn_##symbol_name == nullptr) {                                     \
            fn_##symbol_name =                                                 \
                reinterpret_cast<decltype(fn_##symbol_name)>(/* NOLINT */      \
                    dlsym(RTLD_DEFAULT, #symbol_name));                        \
            if (fn_##symbol_name == nullptr) {                                 \
                throw std::runtime_error{"Failed to resolve " #symbol_name};   \
            }                                                                  \
        }                                                                      \
    } while (0)

void service::resolve_symbols()
{
    RESOLVE_FFI_SYMBOL(ddog_sidecar_enqueue_telemetry_log);
    RESOLVE_FFI_SYMBOL(ddog_sidecar_enqueue_telemetry_point);
    RESOLVE_FFI_SYMBOL(ddog_sidecar_enqueue_telemetry_metric);
    RESOLVE_FFI_SYMBOL(ddog_Error_message);
    RESOLVE_FFI_SYMBOL(ddog_Error_drop);
}

} // namespace dds
