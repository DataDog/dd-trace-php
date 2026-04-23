struct _zend_string;
#ifndef DDTRACE_PHP_H
#define DDTRACE_PHP_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include "common.h"
#include "telemetry.h"
#include "sidecar.h"

extern ddog_Uuid ddtrace_runtime_id;

extern void (*ddog_log_callback)(ddog_CharSlice);

extern ddog_VecRemoteConfigProduct DDTRACE_REMOTE_CONFIG_PRODUCTS;

extern ddog_VecRemoteConfigCapabilities DDTRACE_REMOTE_CONFIG_CAPABILITIES;

extern const uint8_t *DDOG_PHP_FUNCTION;

/**
 * # Safety
 * Must be called from a single-threaded context, such as MINIT.
 */
void ddtrace_generate_runtime_id(void);

void ddtrace_format_runtime_id(uint8_t (*buf)[36]);

ddog_CharSlice ddtrace_get_container_id(void);

void ddtrace_set_container_cgroup_path(ddog_CharSlice path);

char *ddtrace_strip_invalid_utf8(const char *input, uintptr_t *len);

void ddtrace_drop_rust_string(char *input, uintptr_t len);

struct ddog_Endpoint *ddtrace_parse_agent_url(ddog_CharSlice url);

void ddtrace_endpoint_as_crashtracker_config(const struct ddog_Endpoint *endpoint,
                                             void (*callback)(ddog_crasht_EndpointConfig, void*),
                                             void *userdata);

ddog_Configurator *ddog_library_configurator_new_dummy(bool debug_logs, ddog_CharSlice language);

int posix_spawn_file_actions_addchdir_np(void *file_actions, const char *path);

uint64_t dd_fnv1a_64(const uint8_t *data, uintptr_t len);

const char *ddog_normalize_process_tag_value(ddog_CharSlice tag_value);

void ddog_free_normalized_tag_value(const char *ptr);

/**
 * Read all agent /info data in one SHM read and apply env, container-hash and concentrator
 * config atomically.
 *
 * Fills `env_out` with the agent's `config.default_env` (zero-length slice if absent).
 * Fills `container_hash_out` with `container_tags_hash` (zero-length slice if absent).
 * Both slices borrow from the reader's cached info — valid until the next `reader.read()`.
 *
 * Concentrator config (peer tags, span kinds, trace filters) is applied only when the
 * SHM has changed since the last read (`changed == true`).  Calling this once at RINIT
 * ensures the config is always applied before the first span is processed, so the
 * per-span `ddog_apply_agent_info_concentrator_config` can safely rely on `changed` alone.
 *
 * # Safety
 * `reader` must be a valid pointer to an `AgentInfoReader`.
 */
void ddog_apply_agent_info(struct ddog_AgentInfoReader *reader,
                           ddog_CharSlice *env_out,
                           ddog_CharSlice *container_hash_out);

/**
 * Apply concentrator config changes from the agent /info SHM.
 *
 * Cheap no-op when the SHM has not changed (`changed == false`).  Only applies when
 * new data has arrived mid-request — `ddog_apply_agent_info` at RINIT guarantees the
 * initial configuration is already in place, so `changed` alone is sufficient here.
 *
 * # Safety
 * `reader` must be a valid pointer to an `AgentInfoReader`.
 */
void ddog_apply_agent_info_concentrator_config(struct ddog_AgentInfoReader *reader);

bool ddog_shall_log(enum ddog_Log category);

void ddog_set_error_log_level(bool once);

void ddog_set_log_level(ddog_CharSlice level, bool once);

void ddog_log(enum ddog_Log category, bool once, ddog_CharSlice msg);

void ddog_reset_logger(void);

uint32_t ddog_get_logs_count(ddog_CharSlice level);

void ddog_init_remote_config(struct ddog_DdogRemoteConfigFlags flags);

struct ddog_RemoteConfigState *ddog_init_remote_config_state(const struct ddog_Endpoint *endpoint,
                                                             bool di_enabled);

const char *ddog_remote_config_get_path(const struct ddog_RemoteConfigState *remote_config);

bool ddog_process_remote_configs(struct ddog_RemoteConfigState *remote_config);

bool ddog_type_can_be_instrumented(const struct ddog_RemoteConfigState *remote_config,
                                   ddog_CharSlice typename_);

bool ddog_global_log_probe_limiter_inc(const struct ddog_RemoteConfigState *remote_config);

struct ddog_Vec_CChar *ddog_CharSlice_to_owned(ddog_CharSlice str);

bool ddog_remote_configs_service_env_change(struct ddog_RemoteConfigState *remote_config,
                                            ddog_CharSlice service,
                                            ddog_CharSlice env,
                                            ddog_CharSlice version,
                                            const struct ddog_Vec_Tag *tags,
                                            const struct ddog_Vec_Tag *process_tags);

bool ddog_remote_config_alter_dynamic_config(struct ddog_RemoteConfigState *remote_config,
                                             ddog_CharSlice config,
                                             ddog_OwnedZendString new_value);

void ddog_setup_remote_config(ddog_DynamicConfigUpdate update_config,
                              const struct ddog_LiveDebuggerSetup *setup);

/**
 * Enable or disable dynamic instrumentation.
 * When disabling: all installed probe hooks are removed (but kept in `active` for reinstallation).
 * When enabling: all probes in `active` that have no installed hook are (re-)installed.
 */
void ddog_set_dynamic_instrumentation_enabled(struct ddog_RemoteConfigState *remote_config,
                                              bool enabled);

void ddog_rshutdown_remote_config(struct ddog_RemoteConfigState *remote_config);

void ddog_shutdown_remote_config(struct ddog_RemoteConfigState*);

void ddog_log_debugger_data(const struct ddog_Vec_DebuggerPayload *payloads);

void ddog_log_debugger_datum(const struct ddog_DebuggerPayload *payload);

ddog_MaybeError ddog_send_debugger_diagnostics(const struct ddog_RemoteConfigState *remote_config_state,
                                               struct ddog_SidecarTransport **transport,
                                               const struct ddog_InstanceId *instance_id,
                                               ddog_QueueId queue_id,
                                               const struct ddog_Probe *probe,
                                               uint64_t timestamp);

/**
 * Load a UFC JSON config string directly into the FFE engine.
 * Used by tests to load config without Remote Config.
 */
bool ddog_ffe_load_config(const char *json);

/**
 * Check if FFE configuration is loaded.
 */
bool ddog_ffe_has_config(void);

/**
 * Return the current FFE config version counter.
 *
 * Bumped on every `store_config` / `clear_config`. Consumers track their last
 * observed value and detect changes by comparing. Multiple independent
 * subscribers can detect transitions without racing (unlike a drain-on-read
 * `changed` flag where only the first reader sees the transition).
 *
 * Wraps on overflow; in practice the counter is a `u64` and will not wrap
 * within a reasonable process lifetime.
 */
uint64_t ddog_ffe_config_version(void);

/**
 * Evaluate a feature flag using the stored Configuration.
 *
 * Accepts structured attributes from C instead of a JSON blob.
 * `targeting_key` may be null (no targeting key).
 * `attributes` / `attributes_count` describe an array of `FfeAttribute`.
 * Returns null if no config is loaded.
 */
struct ddog_FfeResult *ddog_ffe_evaluate(const char *flag_key,
                                         int32_t expected_type,
                                         const char *targeting_key,
                                         const struct ddog_FfeAttribute *attributes,
                                         uintptr_t attributes_count);

const char *ddog_ffe_result_value(const struct ddog_FfeResult *r);

const char *ddog_ffe_result_variant(const struct ddog_FfeResult *r);

const char *ddog_ffe_result_allocation_key(const struct ddog_FfeResult *r);

int32_t ddog_ffe_result_reason(const struct ddog_FfeResult *r);

int32_t ddog_ffe_result_error_code(const struct ddog_FfeResult *r);

bool ddog_ffe_result_do_log(const struct ddog_FfeResult *r);

void ddog_ffe_free_result(struct ddog_FfeResult *r);

/**
 * Set the service context (DD_SERVICE, DD_ENV, DD_VERSION) for exposure payloads.
 * Called once during provider initialization. Values are stored and reused for all
 * subsequent batch payloads.
 *
 * # Safety
 * All parameters must be valid null-terminated C strings or null.
 */
void ddog_ffe_set_service_context(const char *service, const char *env, const char *version);

/**
 * Enqueue an exposure event for dedup and batched delivery.
 *
 * Dedup key   = (flag_key, targeting_key).
 * Dedup value = (allocation_key, variant_key).
 *
 * Returns `true` (event enqueued) when the (flag, targeting) pair is unseen,
 * or when its cached (allocation, variant) differs from the incoming value.
 * Returns `false` when both allocation and variant are unchanged from the
 * last cached value — duplicate, skipped.
 *
 * Semantics match dd-trace-go `openfeature/exposure.go`.
 *
 * Batch buffer is capped at EXPOSURE_BATCH_LIMIT (1000). If full, new events
 * are silently dropped (per D-11) and the function returns false.
 *
 * # Safety
 * - `event_json` must be a valid null-terminated C string.
 * - `flag_key`, `allocation_key`, `variant_key` must be valid null-terminated C strings.
 * - `targeting_key` may be null.
 */
bool ddog_ffe_enqueue_exposure(const char *event_json,
                               const char *flag_key,
                               const char *allocation_key,
                               const char *targeting_key,
                               const char *variant_key);

/**
 * Flush all buffered exposure events as a batched JSON payload.
 *
 * Returns a JSON string containing the batch payload with service context and all
 * buffered events. The batch buffer is cleared after flushing.
 *
 * In production, the sidecar's periodic flush loop calls this function and sends the
 * result to the agent EVP proxy.
 *
 * Returns `CharSlice::default()` (null ptr, zero len) if:
 * - The batch buffer is empty (nothing to flush)
 * - The mutex is poisoned
 *
 * # Memory
 * The returned CharSlice points to a heap-allocated string that must be freed
 * with `ddog_ffe_free_flush_result()`.
 */
ddog_CharSlice ddog_ffe_flush_exposures(void);

/**
 * Free a flush result previously returned by `ddog_ffe_flush_exposures`.
 *
 * # Safety
 * `slice` must be a CharSlice previously returned by `ddog_ffe_flush_exposures`,
 * or a default (null) CharSlice.
 */
void ddog_ffe_free_flush_result(ddog_CharSlice slice);

/**
 * Reset exposure state for testing. Clears the dedup cache, batch buffer,
 * and service context.
 */
void ddog_ffe_reset_exposure_state(void);

void ddog_sidecar_enable_appsec(ddog_CharSlice shared_lib_path,
                                ddog_CharSlice socket_file_path,
                                ddog_CharSlice lock_file_path,
                                ddog_CharSlice log_file_path,
                                ddog_CharSlice log_level);

ddog_MaybeError ddog_sidecar_connect_php(struct ddog_SidecarTransport **connection,
                                         const char *error_path,
                                         ddog_CharSlice log_level,
                                         bool enable_telemetry,
                                         void (*on_reconnect)(struct ddog_SidecarTransport*),
                                         const struct ddog_Endpoint *crashtracker_endpoint,
                                         uint64_t backpressure_bytes,
                                         uint64_t backpressure_queue);

void ddtrace_sidecar_reconnect(struct ddog_SidecarTransport **transport,
                               struct ddog_SidecarTransport *(*factory)(void));

bool ddog_shm_limiter_inc(const struct ddog_MaybeShmLimiter *limiter, uint32_t limit);

bool ddog_exception_hash_limiter_inc(struct ddog_SidecarTransport *connection,
                                     uint64_t hash,
                                     uint32_t granularity_seconds);

/**
 * Returns true once the agent /info has been received and applied.
 * Used by the PHP extension to skip stats computation until the concentrator
 * has been properly initialised with peer-tag keys and span kinds.
 */
bool ddog_is_agent_info_ready(void);

/**
 * Look up (or lazily create) the concentrator for `(env, version, service)` and invoke
 * `callback` with a shared reference to it while holding the global read lock.
 *
 * The callback is **always** invoked — even before the sidecar has created the backing SHM.
 * When the SHM is not yet available a *virtual* concentrator is used: peer-tag keys and
 * span-kinds come from `DESIRED_CONFIG` so eligibility and peer-tag extraction still work
 * correctly.  The C callback should call `ddog_span_concentrator_has_shm` to decide whether to
 * write to the SHM (real concentrator) or store the stats for the IPC path (virtual).
 *
 * A virtual concentrator is always considered stale so it will be transparently upgraded to a
 * real one on the next call once the sidecar has created the SHM.
 *
 * Returns `true` after the callback returns, `false` only on an internal locking error.
 *
 * # Safety
 * `env`, `version`, and `service` must be valid `CharSlice`s.  `callback` must be a valid
 * function pointer. `userdata` is forwarded to `callback` as-is.
 */
bool ddog_span_concentrator_with(ddog_CharSlice env,
                                 ddog_CharSlice version,
                                 ddog_CharSlice service,
                                 void (*callback)(const struct ddog_SpanConcentrator*, void*),
                                 void *userdata);

/**
 * Returns `true` when the concentrator is backed by a real SHM and
 * `ddog_span_concentrator_add_php_span` will actually persist data.
 * Returns `false` for virtual concentrators (SHM not yet available) — the C callback should
 * store the stats for the IPC fallback path in that case.
 */
bool ddog_span_concentrator_has_shm(const struct ddog_SpanConcentrator *c);

/**
 * Return a pointer to the concentrator's peer-tag-key array and write the count to `*out_count`.
 *
 * The returned pointer is valid for the lifetime of the guard passed to this call.
 * May return null when there are no peer tag keys.
 */
const ddog_CharSlice *ddog_span_concentrator_peer_tag_keys(const struct ddog_SpanConcentrator *c,
                                                           uintptr_t *out_count);

/**
 * Add a PHP span to the concentrator for stats computation.
 *
 * Fast eligibility pre-check: returns true if a span with these attributes would be accepted
 * by `ddog_span_concentrator_add_php_span`.
 *
 * Call this before constructing the full `PhpSpanStats`.  If it returns false, skip the span
 * entirely.  If it returns true, fill the remaining fields and call `add_php_span`.
 */
bool ddog_span_concentrator_is_eligible(const struct ddog_SpanConcentrator *c,
                                        bool has_top_level,
                                        bool is_measured,
                                        ddog_CharSlice span_kind,
                                        bool is_partial_snapshot);

/**
 * Write a PHP span to the concentrator's backing SHM.
 *
 * Only valid when `ddog_span_concentrator_has_shm` returns `true`.  For virtual concentrators
 * (no SHM) the caller should use the IPC path instead.
 *
 * All `CharSlice` fields in `span` (and in the `peer_tags` array it points to) must remain valid
 * for the duration of this call.
 *
 * # Safety
 * `span` must point to a valid `PhpSpanStats`.  The concentrator must have a backing SHM
 * (`ddog_span_concentrator_has_shm` returns `true`).
 */
void ddog_span_concentrator_add_php_span(const struct ddog_SpanConcentrator *c,
                                         const struct ddog_PhpSpanStats *span);

/**
 * IPC fallback: send a PHP span directly to the sidecar's SHM concentrator for (env, version).
 *
 * Called when the SHM is not yet available.  The sidecar processes IPC messages sequentially,
 * and `set_universal_service_tags` is always sent before this message, so the concentrator
 * is guaranteed to exist when the sidecar handles this call.  The sidecar resolves the service
 * dimension from the session's `DD_SERVICE` config.
 *
 * # Safety
 * All pointers must be valid.
 */
void ddog_sidecar_add_php_span_to_concentrator(struct ddog_SidecarTransport **transport,
                                               ddog_CharSlice env,
                                               ddog_CharSlice version,
                                               const struct ddog_PhpSpanStats *span);

bool ddtrace_detect_composer_installed_json(struct ddog_SidecarTransport **transport,
                                            const struct ddog_InstanceId *instance_id,
                                            const ddog_QueueId *queue_id,
                                            ddog_CharSlice path);

struct ddog_SidecarActionsBuffer *ddog_sidecar_telemetry_buffer_alloc(void);

void ddog_sidecar_telemetry_buffer_drop(struct ddog_SidecarActionsBuffer*);

void ddog_sidecar_telemetry_addIntegration_buffer(struct ddog_SidecarActionsBuffer *buffer,
                                                  ddog_CharSlice integration_name,
                                                  ddog_CharSlice integration_version,
                                                  bool integration_enabled);

void ddog_sidecar_telemetry_addDependency_buffer(struct ddog_SidecarActionsBuffer *buffer,
                                                 ddog_CharSlice dependency_name,
                                                 ddog_CharSlice dependency_version);

/**
 * Enqueues an endpoint into a telemetry actions buffer (to be sent via ddog_sidecar_telemetry_buffer_flush).
 */
void ddog_sidecar_telemetry_addEndpoint_buffer(struct ddog_SidecarActionsBuffer *buffer,
                                               enum ddog_Method method,
                                               ddog_CharSlice path,
                                               ddog_CharSlice operation_name,
                                               ddog_CharSlice resource_name);

void ddog_sidecar_telemetry_enqueueConfig_buffer(struct ddog_SidecarActionsBuffer *buffer,
                                                 ddog_CharSlice config_key,
                                                 ddog_CharSlice config_value,
                                                 enum ddog_ConfigurationOrigin origin,
                                                 ddog_CharSlice config_id);

ddog_MaybeError ddog_sidecar_telemetry_buffer_flush(struct ddog_SidecarTransport **transport,
                                                    const struct ddog_InstanceId *instance_id,
                                                    const ddog_QueueId *queue_id,
                                                    struct ddog_SidecarActionsBuffer *buffer);

ddog_MaybeError ddog_sidecar_telemetry_register_metric(struct ddog_SidecarTransport **transport,
                                                       ddog_CharSlice metric_name,
                                                       enum ddog_MetricType metric_type,
                                                       enum ddog_MetricNamespace namespace_);

void ddog_sidecar_telemetry_add_span_metric_point_buffer(struct ddog_SidecarActionsBuffer *buffer,
                                                         ddog_CharSlice metric_name,
                                                         double metric_value,
                                                         ddog_CharSlice tags);

void ddog_sidecar_telemetry_add_integration_log_buffer(enum ddog_Log category,
                                                       struct ddog_SidecarActionsBuffer *buffer,
                                                       ddog_CharSlice log);

ddog_ShmCacheMap *ddog_sidecar_telemetry_cache_new(void);

void ddog_sidecar_telemetry_cache_drop(ddog_ShmCacheMap*);

bool ddog_sidecar_telemetry_config_sent(ddog_ShmCacheMap *cache,
                                        ddog_CharSlice service,
                                        ddog_CharSlice env);

ddog_MaybeError ddog_sidecar_telemetry_filter_flush(struct ddog_SidecarTransport **transport,
                                                    const struct ddog_InstanceId *instance_id,
                                                    const ddog_QueueId *queue_id,
                                                    struct ddog_SidecarActionsBuffer *buffer,
                                                    ddog_ShmCacheMap *cache,
                                                    ddog_CharSlice service,
                                                    ddog_CharSlice env);

bool ddog_sidecar_telemetry_are_endpoints_collected(ddog_ShmCacheMap *cache,
                                                    ddog_CharSlice service,
                                                    ddog_CharSlice env);

/**
 * Check whether the trace rooted at `resource` / `root_span` passes all configured trace
 * filters (filter_tags, filter_tags_regex, ignore_resources from agent /info).
 *
 * Returns `true` to include in the pipeline, `false` to drop the entire trace (no sending,
 * no stats).  Filters are evaluated against the root span — the decision applies uniformly
 * to all spans of the trace.
 *
 * * **Common case**: `filter_tags` and literal-key `filter_tags_regex` entries — one O(1)
 *   `lookup_fn` call per filter entry.
 * * **Rare case**: `filter_tags_regex` entries with regex key patterns — `iter_fn` is invoked
 *   to scan all meta entries for those filters.  Pass `NULL` when not needed.
 * * **Fast path**: returns `true` immediately when no filters are configured.
 */
bool ddog_check_stats_trace_filter(ddog_CharSlice resource,
                                   const void *root_span,
                                   ddog_RootTagLookupFn lookup_fn,
                                   ddog_RootMetaIterFn iter_fn);

void ddog_init_span_func(void (*free_func)(ddog_OwnedZendString),
                         void (*addref_func)(struct _zend_string*),
                         ddog_OwnedZendString (*init_func)(ddog_CharSlice));

void ddog_set_span_service_zstr(ddog_SpanBytes *ptr, struct _zend_string *str);

void ddog_set_span_name_zstr(ddog_SpanBytes *ptr, struct _zend_string *str);

void ddog_set_span_resource_zstr(ddog_SpanBytes *ptr, struct _zend_string *str);

void ddog_set_span_type_zstr(ddog_SpanBytes *ptr, struct _zend_string *str);

void ddog_add_span_meta_zstr(ddog_SpanBytes *ptr,
                             struct _zend_string *key,
                             struct _zend_string *val);

void ddog_add_CharSlice_span_meta_zstr(ddog_SpanBytes *ptr,
                                       ddog_CharSlice key,
                                       struct _zend_string *val);

void ddog_add_zstr_span_meta_str(ddog_SpanBytes *ptr, struct _zend_string *key, const char *val);

void ddog_add_str_span_meta_str(ddog_SpanBytes *ptr, const char *key, const char *val);

void ddog_add_str_span_meta_zstr(ddog_SpanBytes *ptr, const char *key, struct _zend_string *val);

void ddog_add_str_span_meta_CharSlice(ddog_SpanBytes *ptr, const char *key, ddog_CharSlice val);

void ddog_del_span_meta_zstr(ddog_SpanBytes *ptr, struct _zend_string *key);

void ddog_del_span_meta_str(ddog_SpanBytes *ptr, const char *key);

bool ddog_has_span_meta_zstr(ddog_SpanBytes *ptr, struct _zend_string *key);

bool ddog_has_span_meta_str(ddog_SpanBytes *ptr, const char *key);

ddog_CharSlice ddog_get_span_meta_str(ddog_SpanBytes *span, const char *key);

void ddog_add_span_metrics_zstr(ddog_SpanBytes *ptr, struct _zend_string *key, double val);

bool ddog_has_span_metrics_zstr(ddog_SpanBytes *ptr, struct _zend_string *key);

void ddog_del_span_metrics_zstr(ddog_SpanBytes *ptr, struct _zend_string *key);

void ddog_add_span_metrics_str(ddog_SpanBytes *ptr, const char *key, double val);

bool ddog_get_span_metrics_str(ddog_SpanBytes *ptr, const char *key, double *result);

void ddog_del_span_metrics_str(ddog_SpanBytes *ptr, const char *key);

void ddog_add_span_meta_struct_zstr(ddog_SpanBytes *ptr,
                                    struct _zend_string *key,
                                    struct _zend_string *val);

void ddog_add_zstr_span_meta_struct_CharSlice(ddog_SpanBytes *ptr,
                                              struct _zend_string *key,
                                              ddog_CharSlice val);

#endif  /* DDTRACE_PHP_H */
