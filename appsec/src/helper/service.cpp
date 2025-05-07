// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "service.hpp"
#include "sidecar_settings.hpp"

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

namespace dds {

namespace {
inline CharSlice to_ffi_string(std::string_view sv)
{
    return {sv.data(), sv.length()};
}

using ddog_sidecar_enqueue_telemetry_log_t =
    decltype(&ddog_sidecar_enqueue_telemetry_log);
ddog_sidecar_enqueue_telemetry_log_t fn_ddog_sidecar_enqueue_telemetry_log;

using ddog_Error_message_t = decltype(&ddog_Error_message);
ddog_Error_message_t fn_ddog_Error_message;

using ddog_Error_drop_t = decltype(&ddog_Error_drop);
ddog_Error_drop_t fn_ddog_Error_drop;
} // namespace

service::service(std::shared_ptr<engine> engine,
    std::shared_ptr<service_config> service_config,
    std::unique_ptr<dds::remote_config::client_handler> client_handler,
    std::shared_ptr<metrics_impl> msubmitter, std::string rc_path,
    sidecar_settings sc_settings,
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
      rc_path_{std::move(rc_path)}, msubmitter_{std::move(msubmitter)},
      sidecar_settings_{std::move(sc_settings)}
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
    const remote_config::settings &rc_settings, sidecar_settings sc_settings)
{
    std::shared_ptr<metrics_impl> msubmitter =
        std::make_shared<metrics_impl>(sc_settings);

    const std::shared_ptr<engine> engine_ptr =
        engine::from_settings(eng_settings, *msubmitter);

    auto service_config = std::make_shared<dds::service_config>();

    auto client_handler = remote_config::client_handler::from_settings(
        eng_settings, service_config, rc_settings, engine_ptr, msubmitter);

    return create_shared(engine_ptr, std::move(service_config),
        std::move(client_handler), std::move(msubmitter),
        rc_settings.shmem_path, std::move(sc_settings),
        eng_settings.schema_extraction);
}

void service::metrics_impl::submit_log(
    uint64_t queue_id, const tel_log &log) const
{
    SPDLOG_DEBUG("submit_log (ffi): [{}][{}]: {}", log.level, log.identifier,
        log.message);

    if (fn_ddog_sidecar_enqueue_telemetry_log == nullptr) {
        SPDLOG_WARN(
            "ddog_sidecar_enqueue_telemetry_log function pointer is null. "
            "Symbol resolution likely failed.");
        return;
    }

    CharSlice const session_id_ffi = to_ffi_string(sc_settings_.session_id);
    CharSlice const runtime_id_ffi = to_ffi_string(sc_settings_.runtime_id);

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

    ddog_MaybeError result = fn_ddog_sidecar_enqueue_telemetry_log(
        session_id_ffi, runtime_id_ffi, queue_id, identifier_ffi, c_level,
        message_ffi, stack_trace_ffi_ptr, tags_ffi_ptr, log.is_sensitive);

    if (result.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
        ddog_CharSlice const error_msg = fn_ddog_Error_message(&result.some);
        SPDLOG_INFO("Failed to enqueue telemetry log, error: {}",
            std::string_view{error_msg.ptr, error_msg.len});
        fn_ddog_Error_drop(&result.some);
        return;
    }
}

void service::resolve_symbols()
{
    if (fn_ddog_sidecar_enqueue_telemetry_log == nullptr) {
        fn_ddog_sidecar_enqueue_telemetry_log =
            // NOLINTNEXTLINE
            reinterpret_cast<ddog_sidecar_enqueue_telemetry_log_t>(
                dlsym(RTLD_DEFAULT, "ddog_sidecar_enqueue_telemetry_log"));
        if (fn_ddog_sidecar_enqueue_telemetry_log == nullptr) {
            throw std::runtime_error{
                "Failed to resolve ddog_sidecar_enqueue_telemetry_log"};
        }
    }

    if (fn_ddog_Error_message == nullptr) {
        fn_ddog_Error_message =
            // NOLINTNEXTLINE
            reinterpret_cast<ddog_Error_message_t>(
                dlsym(RTLD_DEFAULT, "ddog_Error_message"));
        if (fn_ddog_Error_message == nullptr) {
            throw std::runtime_error{"Failed to resolve ddog_Error_message"};
        }
    }

    if (fn_ddog_Error_drop == nullptr) {
        fn_ddog_Error_drop =
            // NOLINTNEXTLINE
            reinterpret_cast<ddog_Error_drop_t>(
                dlsym(RTLD_DEFAULT, "ddog_Error_drop"));
        if (fn_ddog_Error_drop == nullptr) {
            throw std::runtime_error{"Failed to resolve ddog_Error_drop"};
        }
    }
}

} // namespace dds
