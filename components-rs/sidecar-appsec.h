#ifndef DDOG_SIDECAR_APPSEC_H
#define DDOG_SIDECAR_APPSEC_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include "common.h"
void ddog_set_rc_notify_fn(ddog_InProcNotifyFn notify_fn);


/**
 * Connect to an already-running sidecar.  **Never** tries to spawn one.
 * Used by the AppSec helper's `SidecarReadyFuture` from within the sidecar
 * process to verify the sidecar is accepting connections.
 */
ddog_MaybeError ddog_sidecar_connect(struct ddog_SidecarTransport **connection);

void ddog_sidecar_transport_drop(struct ddog_SidecarTransport*);

ddog_MaybeError ddog_sidecar_ping(struct ddog_SidecarTransport **transport);

char *ddog_remote_config_path(const struct ddog_ConfigInvariants *id,
                              const struct ddog_Arc_Target *target);

void ddog_remote_config_path_free(char *path);

/**
 * # Safety
 * Pointers must be valid; strings must be non-null.
 */
ddog_MaybeError ddog_sidecar_enqueue_telemetry_log(ddog_CharSlice session_id,
                                                   ddog_CharSlice runtime_id,
                                                   ddog_CharSlice service_name,
                                                   ddog_CharSlice env_name,
                                                   ddog_CharSlice identifier,
                                                   enum ddog_LogLevel level,
                                                   ddog_CharSlice message,
                                                   ddog_CharSlice *stack_trace,
                                                   ddog_CharSlice *tags,
                                                   bool is_sensitive);

/**
 * # Safety
 * Pointers must be valid; strings must be non-null.
 */
ddog_MaybeError ddog_sidecar_enqueue_telemetry_point(ddog_CharSlice session_id,
                                                     ddog_CharSlice runtime_id,
                                                     ddog_CharSlice service_name,
                                                     ddog_CharSlice env_name,
                                                     ddog_CharSlice metric_name,
                                                     double value,
                                                     ddog_CharSlice *tags);

/**
 * # Safety
 * Pointers must be valid; strings must be non-null.
 */
ddog_MaybeError ddog_sidecar_enqueue_telemetry_metric(ddog_CharSlice session_id,
                                                      ddog_CharSlice runtime_id,
                                                      ddog_CharSlice service_name,
                                                      ddog_CharSlice env_name,
                                                      ddog_CharSlice metric_name,
                                                      enum ddog_MetricType metric_type,
                                                      enum ddog_MetricNamespace metric_namespace);

/**
 * Open a remote-config shared-memory reader at `path`.
 * Used by the AppSec helper to read rule updates pushed by the sidecar.
 */
struct ddog_RemoteConfigReader *ddog_remote_config_reader_for_path(const char *path);

bool ddog_remote_config_read(struct ddog_RemoteConfigReader *reader, ddog_CharSlice *data);

void ddog_remote_config_reader_drop(struct ddog_RemoteConfigReader*);

#endif  /* DDOG_SIDECAR_APPSEC_H */
