/**
 * C header for libdd-data-pipeline-ffi TraceExporter API.
 *
 * These functions are linked from the ddtrace-php static library which
 * re-exports libdd-data-pipeline-ffi symbols.
 *
 * The TraceExporter is a standalone trace sending pipeline that can
 * optionally compute client-side stats via a SpanConcentrator.
 */

#ifndef DDOG_TRACE_EXPORTER_H
#define DDOG_TRACE_EXPORTER_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include "common.h"

#ifdef __cplusplus
extern "C" {
#endif

/**
 * ByteSlice: a pointer + length pair for raw byte data (Rust Slice<u8>).
 * Same layout as ddog_CharSlice but typed as uint8_t.
 */
typedef struct ddog_Slice_U8 {
  const uint8_t *ptr;
  uintptr_t len;
} ddog_Slice_U8;

typedef struct ddog_Slice_U8 ddog_ByteSlice;

/* Opaque types - internal Rust structures */
typedef struct ddog_TraceExporter ddog_TraceExporter;
typedef struct ddog_TraceExporterConfig ddog_TraceExporterConfig;

typedef enum {
    DDOG_EXPORTER_ERROR_CODE_ADDRESS_IN_USE,
    DDOG_EXPORTER_ERROR_CODE_CONNECTION_ABORTED,
    DDOG_EXPORTER_ERROR_CODE_CONNECTION_REFUSED,
    DDOG_EXPORTER_ERROR_CODE_CONNECTION_RESET,
    DDOG_EXPORTER_ERROR_CODE_HTTP_BODY_FORMAT,
    DDOG_EXPORTER_ERROR_CODE_HTTP_BODY_TOO_LONG,
    DDOG_EXPORTER_ERROR_CODE_HTTP_CLIENT,
    DDOG_EXPORTER_ERROR_CODE_HTTP_EMPTY_BODY,
    DDOG_EXPORTER_ERROR_CODE_HTTP_PARSE,
    DDOG_EXPORTER_ERROR_CODE_HTTP_SERVER,
    DDOG_EXPORTER_ERROR_CODE_HTTP_UNKNOWN,
    DDOG_EXPORTER_ERROR_CODE_HTTP_WRONG_STATUS,
    DDOG_EXPORTER_ERROR_CODE_INVALID_ARGUMENT,
    DDOG_EXPORTER_ERROR_CODE_INVALID_DATA,
    DDOG_EXPORTER_ERROR_CODE_INVALID_INPUT,
    DDOG_EXPORTER_ERROR_CODE_INVALID_URL,
    DDOG_EXPORTER_ERROR_CODE_IO_ERROR,
    DDOG_EXPORTER_ERROR_CODE_NETWORK_UNKNOWN,
    DDOG_EXPORTER_ERROR_CODE_SERDE,
    DDOG_EXPORTER_ERROR_CODE_SHUTDOWN,
    DDOG_EXPORTER_ERROR_CODE_TIMED_OUT,
    DDOG_EXPORTER_ERROR_CODE_TELEMETRY,
    DDOG_EXPORTER_ERROR_CODE_INTERNAL,
    DDOG_EXPORTER_ERROR_CODE_PANIC,
} ddog_ExporterErrorCode;

typedef struct {
    ddog_ExporterErrorCode code;
    char *msg;
} ddog_ExporterError;

typedef struct ddog_ExporterResponse ddog_ExporterResponse;

/* Configuration API */
void ddog_trace_exporter_config_new(ddog_TraceExporterConfig **out_handle);

void ddog_trace_exporter_config_free(ddog_TraceExporterConfig *handle);

ddog_ExporterError *ddog_trace_exporter_config_set_url(
    ddog_TraceExporterConfig *config,
    ddog_CharSlice url);

ddog_ExporterError *ddog_trace_exporter_config_set_tracer_version(
    ddog_TraceExporterConfig *config,
    ddog_CharSlice tracer_version);

ddog_ExporterError *ddog_trace_exporter_config_set_language(
    ddog_TraceExporterConfig *config,
    ddog_CharSlice language);

ddog_ExporterError *ddog_trace_exporter_config_set_lang_version(
    ddog_TraceExporterConfig *config,
    ddog_CharSlice lang_version);

ddog_ExporterError *ddog_trace_exporter_config_set_lang_interpreter(
    ddog_TraceExporterConfig *config,
    ddog_CharSlice lang_interpreter);

ddog_ExporterError *ddog_trace_exporter_config_set_hostname(
    ddog_TraceExporterConfig *config,
    ddog_CharSlice hostname);

ddog_ExporterError *ddog_trace_exporter_config_set_env(
    ddog_TraceExporterConfig *config,
    ddog_CharSlice env);

ddog_ExporterError *ddog_trace_exporter_config_set_version(
    ddog_TraceExporterConfig *config,
    ddog_CharSlice version);

ddog_ExporterError *ddog_trace_exporter_config_set_service(
    ddog_TraceExporterConfig *config,
    ddog_CharSlice service);

/**
 * Enable client-side stats computation via SpanConcentrator.
 * When enabled, the TraceExporter will aggregate span stats and send
 * them to the agent's /v0.6/stats endpoint.
 */
ddog_ExporterError *ddog_trace_exporter_config_set_compute_stats(
    ddog_TraceExporterConfig *config,
    bool is_enabled);

/**
 * Set Datadog-Client-Computed-Stats header.
 * Do NOT use this when compute_stats is enabled (would cause double-counting).
 */
ddog_ExporterError *ddog_trace_exporter_config_set_client_computed_stats(
    ddog_TraceExporterConfig *config,
    bool client_computed_stats);

/* Exporter lifecycle */
ddog_ExporterError *ddog_trace_exporter_new(
    ddog_TraceExporter **out_handle,
    const ddog_TraceExporterConfig *config);

void ddog_trace_exporter_free(ddog_TraceExporter *handle);

/**
 * Send traces via the data pipeline.
 *
 * @param handle       The TraceExporter instance.
 * @param trace        Pointer to msgpack-encoded v0.4 trace data.
 * @param trace_count  Number of traces in the payload.
 * @param response_out Optional pointer to receive agent response info.
 * @return             NULL on success, ExporterError on failure.
 */
ddog_ExporterError *ddog_trace_exporter_send(
    const ddog_TraceExporter *handle,
    ddog_ByteSlice trace,
    size_t trace_count,
    ddog_ExporterResponse **response_out);

/* Error handling */
void ddog_trace_exporter_error_free(ddog_ExporterError *error);

/* Response handling */
const uint8_t *ddog_trace_exporter_response_get_body(
    const ddog_ExporterResponse *response,
    size_t *out_len);

void ddog_trace_exporter_response_free(ddog_ExporterResponse *response);

#ifdef __cplusplus
}
#endif

#endif /* DDOG_TRACE_EXPORTER_H */
