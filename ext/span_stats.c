// Copyright 2024-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#include "span_stats.h"

#include <math.h>  // NAN
#include <php.h>
#include <stdbool.h>
#include <Zend/zend_exceptions.h>

#include <components-rs/ddtrace.h>
#include <components/log/log.h>

#include "compat_string.h"
#include "configuration.h"
#include "ddtrace.h"
#include "sidecar.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

// gRPC status-code meta keys in the same order as PHP_GRPC_KEY_COUNT / grpc_meta[] in stats.rs.
static const char *const GRPC_META_KEYS[] = {
    "rpc.grpc.status_code",
    "grpc.code",
    "rpc.grpc.status.code",
    "grpc.status.code",
};
static const size_t GRPC_META_KEY_LENS[] = {
    sizeof("rpc.grpc.status_code") - 1,
    sizeof("grpc.code") - 1,
    sizeof("rpc.grpc.status.code") - 1,
    sizeof("grpc.status.code") - 1,
};

// Maximum number of peer tags we handle per span (a hard cap to bound stack usage).
#define DDTRACE_MAX_PEER_TAGS 32

void ddtrace_precompute_span(ddtrace_span_data *span, ddtrace_span_precomputed *pre) {
    pre->meta    = ddtrace_property_array(&span->property_meta);
    pre->metrics = ddtrace_property_array(&span->property_metrics);

    // Service: meta["service.name"] override then span property, then apply DD_SERVICE_MAPPING.
    zval *service_name_meta = pre->meta ? zend_hash_str_find(pre->meta, ZEND_STRL("service.name")) : NULL;
    pre->service_from_meta = (service_name_meta != NULL);
    pre->service = NULL;
    if (service_name_meta) {
        pre->service = ddtrace_convert_to_str(service_name_meta);
    } else {
        zval *prop_service = &span->property_service;
        ZVAL_DEREF(prop_service);
        if (Z_TYPE_P(prop_service) > IS_NULL) {
            pre->service = ddtrace_convert_to_str(prop_service);
        }
    }
    if (pre->service) {
        zval *mapped = zend_hash_find(get_DD_SERVICE_MAPPING(), pre->service);
        if (mapped) {
            zend_string_release(pre->service);
            pre->service = zend_string_copy(Z_STR_P(mapped));
        }
    }

    // Name: meta["operation.name"] (lowercased!) or span property.
    zval *operation_name = pre->meta ? zend_hash_str_find(pre->meta, ZEND_STRL("operation.name")) : NULL;
    pre->name = NULL;
    if (operation_name && Z_TYPE_P(operation_name) == IS_STRING) {
        pre->name = zend_string_tolower(Z_STR_P(operation_name));
        pre->name_from_meta = true;
    } else {
        zval *prop_name = &span->property_name;
        ZVAL_DEREF(prop_name);
        if (Z_TYPE_P(prop_name) > IS_NULL) {
            pre->name = ddtrace_convert_to_str(prop_name);
        }
        pre->name_from_meta = false;
    }

    // Resource: meta["resource.name"] or span property, falling back to name.
    zval *resource_name = pre->meta ? zend_hash_str_find(pre->meta, ZEND_STRL("resource.name")) : NULL;
    pre->resource_from_meta = (resource_name != NULL);
    pre->resource = NULL;
    if (resource_name) {
        pre->resource = ddtrace_convert_to_str(resource_name);
    } else {
        zval *prop_resource = &span->property_resource;
        ZVAL_DEREF(prop_resource);
        if (Z_TYPE_P(prop_resource) > IS_FALSE &&
            (Z_TYPE_P(prop_resource) != IS_STRING || Z_STRLEN_P(prop_resource) > 0)) {
            pre->resource = ddtrace_convert_to_str(prop_resource);
        }
    }
    if (!pre->resource && pre->name) {
        pre->resource = zend_string_copy(pre->name);
    }

    // Type: meta["span.type"] or span property.
    zval *span_type = pre->meta ? zend_hash_str_find(pre->meta, ZEND_STRL("span.type")) : NULL;
    pre->type_from_meta = (span_type != NULL);
    pre->type = NULL;
    zval *prop_type = span_type ? span_type : &span->property_type;
    ZVAL_DEREF(prop_type);
    if (Z_TYPE_P(prop_type) > IS_NULL) {
        pre->type = ddtrace_convert_to_str(prop_type);
    }

    // Env: prefer deprecated meta["env"] (with a warning), else span property.
    pre->env = NULL;
    zval *meta_env = pre->meta ? zend_hash_str_find(pre->meta, ZEND_STRL("env")) : NULL;
    if (meta_env) {
        pre->env_deprecated = true;
        LOG(DEPRECATED, "Using \"env\" in meta is deprecated. Instead specify the env property directly on the span.");
        zend_string *str = ddtrace_convert_to_str(meta_env);
        if (ZSTR_LEN(str) > 0) {
            pre->env = str;
        } else {
            zend_string_release(str);
        }
    } else {
        pre->env_deprecated = false;
        zval *prop_env = &span->property_env;
        ZVAL_DEREF(prop_env);
        if (Z_TYPE_P(prop_env) > IS_NULL) {
            zend_string *str = ddtrace_convert_to_str(prop_env);
            if (ZSTR_LEN(str) > 0) {
                pre->env = str;
            } else {
                zend_string_release(str);
            }
        }
    }

    // Version: prefer deprecated meta["version"] (with a warning), else the span's own property.
    pre->version = NULL;
    zval *meta_version = pre->meta ? zend_hash_str_find(pre->meta, ZEND_STRL("version")) : NULL;
    if (meta_version) {
        pre->version_deprecated = true;
        LOG(DEPRECATED, "Using \"version\" in meta is deprecated. Instead specify the version property directly on the span.");
        zend_string *str = ddtrace_convert_to_str(meta_version);
        if (ZSTR_LEN(str) > 0) {
            pre->version = str;
        } else {
            zend_string_release(str);
        }
    } else {
        pre->version_deprecated = false;
        zval *prop_version = &span->property_version;
        ZVAL_DEREF(prop_version);
        if (Z_TYPE_P(prop_version) > IS_NULL) {
            zend_string *str = ddtrace_convert_to_str(prop_version);
            if (ZSTR_LEN(str) > 0) {
                pre->version = str;
            } else {
                zend_string_release(str);
            }
        }
    }

    // has_exception: used by dd_compute_span_is_error() for exception-based error detection.
    zval *exception_zv = &span->property_exception;
    pre->has_exception = Z_TYPE_P(exception_zv) == IS_OBJECT &&
                         instanceof_function(Z_OBJCE_P(exception_zv), zend_ce_throwable);

    zval *error_ignored_zv = pre->meta ? zend_hash_str_find(pre->meta, ZEND_STRL("error.ignored")) : NULL;
    pre->ignore_error = error_ignored_zv && zend_is_true(error_ignored_zv);

    // Stats eligibility fields — fetched once here to avoid duplicate lookups in the two
    // call sites (ddtrace_span_concentrator_feed_cb and ddtrace_feed_span_to_concentrator).
    pre->has_top_level = ddtrace_span_is_entrypoint_root(span);
    zval *is_measured = pre->metrics ? zend_hash_str_find(pre->metrics, ZEND_STRL("_dd.measured")) : NULL;
    pre->is_measured = is_measured && zval_get_double(is_measured) != 0.0;
    pre->is_partial_snapshot = false;
    zval *span_kind_zv = pre->meta ? zend_hash_str_find(pre->meta, ZEND_STRL("span.kind")) : NULL;
    pre->span_kind = (span_kind_zv && Z_TYPE_P(span_kind_zv) == IS_STRING) ? Z_STR_P(span_kind_zv) : NULL;
}

bool dd_compute_span_is_error(const ddtrace_span_precomputed *pre) {
    if (pre->ignore_error) {
        return false;
    }
    if (pre->meta && (zend_hash_str_find(pre->meta, ZEND_STRL("error.message")) != NULL ||
                      zend_hash_str_find(pre->meta, ZEND_STRL("error.type")) != NULL)) {
        return true;
    }
    return pre->has_exception;
}

void ddtrace_free_span_precomputed(ddtrace_span_precomputed *pre) {
    if (pre->service) {
        zend_string_release(pre->service);
    }
    if (pre->name) {
        zend_string_release(pre->name);
    }
    if (pre->resource) {
        zend_string_release(pre->resource);
    }
    if (pre->type) {
        zend_string_release(pre->type);
    }
    if (pre->env) {
        zend_string_release(pre->env);
    }
    if (pre->version) {
        zend_string_release(pre->version);
    }
}

typedef struct {
    ddtrace_span_data *span;
    const ddtrace_span_precomputed *pre;
    // Set by the callback when the concentrator has no backing SHM (virtual concentrator).
    // The caller should then forward ipc_stats to the sidecar via IPC.
    bool needs_ipc;
    ddog_PhpSpanStats ipc_stats;
    // Owned storage for peer_tags when going through the IPC path.
    // ipc_stats.peer_tags points into this array.
    ddog_PhpPeerTag ipc_peer_tags[DDTRACE_MAX_PEER_TAGS];
} ddtrace_concentrator_cb_data;

// Build the stats fields for a span (all except peer_tags).
// All CharSlice fields in the returned struct borrow from PHP memory valid for this call.
// peer_tags_count = 0 and peer_tags = NULL in the returned struct; fill them in separately.
// span_kind_slice is passed in to avoid recomputing it when the caller already has it (e.g.
// for the eligibility check that precedes this call).
static ddog_PhpSpanStats ddtrace_build_span_stats_core(
    ddtrace_span_data *span, const ddtrace_span_precomputed *pre, ddog_CharSlice span_kind_slice
) {
    zend_array *meta    = pre->meta;
    zend_array *metrics = pre->metrics;

    ddog_CharSlice service_slice  = dd_zend_string_to_CharSlice(pre->service);
    ddog_CharSlice name_slice     = dd_zend_string_to_CharSlice(pre->name);
    ddog_CharSlice resource_slice = dd_zend_string_to_CharSlice(pre->resource);
    ddog_CharSlice type_slice     = dd_zend_string_to_CharSlice(pre->type);

    bool is_root_span  = span->std.ce == ddtrace_ce_root_span_data;
    bool is_trace_root = is_root_span && (span->root->parent_id == 0);
    bool is_error = dd_compute_span_is_error(pre);

    // HTTP and gRPC fields only appear on service entry spans, which are always stats-eligible.
    // They are fetched here (after the eligibility check) rather than in ddtrace_precompute_span.
    zval *http_status_str_zv = meta ? zend_hash_str_find(meta, ZEND_STRL("http.status_code")) : NULL;
    ddog_CharSlice http_status_str_slice = http_status_str_zv && Z_TYPE_P(http_status_str_zv) == IS_STRING
        ? dd_zend_string_to_CharSlice(Z_STR_P(http_status_str_zv))
        : DDOG_CHARSLICE_C("");

    zval *http_method_zv = meta ? zend_hash_str_find(meta, ZEND_STRL("http.method")) : NULL;
    ddog_CharSlice http_method_slice = http_method_zv && Z_TYPE_P(http_method_zv) == IS_STRING
        ? dd_zend_string_to_CharSlice(Z_STR_P(http_method_zv))
        : DDOG_CHARSLICE_C("");

    zval *http_endpoint_zv = meta ? zend_hash_str_find(meta, ZEND_STRL("http.endpoint")) : NULL;
    ddog_CharSlice http_endpoint_slice = http_endpoint_zv && Z_TYPE_P(http_endpoint_zv) == IS_STRING
        ? dd_zend_string_to_CharSlice(Z_STR_P(http_endpoint_zv))
        : DDOG_CHARSLICE_C("");

    zval *http_route_zv = meta ? zend_hash_str_find(meta, ZEND_STRL("http.route")) : NULL;
    ddog_CharSlice http_route_slice = http_route_zv && Z_TYPE_P(http_route_zv) == IS_STRING
        ? dd_zend_string_to_CharSlice(Z_STR_P(http_route_zv))
        : DDOG_CHARSLICE_C("");

    zval *origin_zv = &span->root->property_origin;
    ZVAL_DEREF(origin_zv);
    ddog_CharSlice origin_slice = Z_TYPE_P(origin_zv) == IS_STRING && ZSTR_LEN(Z_STR_P(origin_zv)) > 0
        ? dd_zend_string_to_CharSlice(Z_STR_P(origin_zv))
        : DDOG_CHARSLICE_C("");

    zval *service_source_zv = meta ? zend_hash_str_find(meta, ZEND_STRL("_dd.svc_src")) : NULL;
    ddog_CharSlice service_source_slice = service_source_zv && Z_TYPE_P(service_source_zv) == IS_STRING
        ? dd_zend_string_to_CharSlice(Z_STR_P(service_source_zv))
        : DDOG_CHARSLICE_C("");

    ddog_CharSlice grpc_meta[4];
    double         grpc_metrics[4];
    for (int i = 0; i < 4; i++) {
        grpc_meta[i]    = DDOG_CHARSLICE_C("");
        grpc_metrics[i] = NAN;
        if (meta) {
            zval *gm = zend_hash_str_find(meta, GRPC_META_KEYS[i], GRPC_META_KEY_LENS[i]);
            if (gm && Z_TYPE_P(gm) == IS_STRING) {
                grpc_meta[i] = dd_zend_string_to_CharSlice(Z_STR_P(gm));
            }
        }
        if (metrics) {
            zval *gv = zend_hash_str_find(metrics, GRPC_META_KEYS[i], GRPC_META_KEY_LENS[i]);
            if (gv) {
                grpc_metrics[i] = zval_get_double(gv);
            }
        }
    }

    ddog_PhpSpanStats stats = {
        .service  = service_slice,
        .resource = resource_slice,
        .name     = name_slice,
        .type     = type_slice,

        .start    = (int64_t)span->start,
        .duration = (int64_t)span->duration,

        .is_error            = is_error,
        .is_trace_root       = is_trace_root,
        .is_measured         = pre->is_measured,
        .has_top_level       = pre->has_top_level,
        .is_partial_snapshot = pre->is_partial_snapshot,

        .span_kind        = span_kind_slice,
        .http_status_code = http_status_str_slice,
        .http_method      = http_method_slice,
        .http_endpoint    = http_endpoint_slice,
        .http_route       = http_route_slice,
        .origin           = origin_slice,
        .service_source   = service_source_slice,

        .grpc_meta    = {grpc_meta[0], grpc_meta[1], grpc_meta[2], grpc_meta[3]},
        .grpc_metrics = {grpc_metrics[0], grpc_metrics[1], grpc_metrics[2], grpc_metrics[3]},

        .peer_tags_count = 0,
        .peer_tags       = NULL,
    };
    return stats;
}

static void ddtrace_span_concentrator_feed_cb(const ddog_SpanConcentrator *c, void *data_ptr) {
    ddtrace_concentrator_cb_data *data = data_ptr;
    ddtrace_span_data *span = data->span;
    const ddtrace_span_precomputed *pre = data->pre;

    ddog_CharSlice span_kind_slice = dd_zend_string_to_CharSlice(pre->span_kind);

    if (!ddog_span_concentrator_is_eligible(c, pre->has_top_level, pre->is_measured, span_kind_slice, pre->is_partial_snapshot)) {
        return;
    }

    ddog_PhpSpanStats stats = ddtrace_build_span_stats_core(span, pre, span_kind_slice);

    // Peer tag extraction rules by span kind (spec: Peer Tags in Aggregation):
    //   client/producer/consumer → all configured peer tag keys
    //   server                   → no peer tags
    //   internal / no span.kind  → only _dd.base_service if a service override is present
    ddog_PhpPeerTag peer_tags[DDTRACE_MAX_PEER_TAGS];
    size_t actual_peer_tags = 0;

    if (pre->span_kind && (zend_string_equals_literal(pre->span_kind, "client") || zend_string_equals_literal(pre->span_kind, "producer") || zend_string_equals_literal(pre->span_kind, "consumer"))) {
        size_t peer_tag_keys_count = 0;
        const ddog_CharSlice *peer_tag_keys = ddog_span_concentrator_peer_tag_keys(c, &peer_tag_keys_count);
        if (peer_tag_keys_count > DDTRACE_MAX_PEER_TAGS) {
            peer_tag_keys_count = DDTRACE_MAX_PEER_TAGS;
        }
        if (peer_tag_keys_count > 0 && peer_tag_keys) {
            for (size_t i = 0; i < peer_tag_keys_count; i++) {
                const ddog_CharSlice *k = &peer_tag_keys[i];
                zval *val = zend_hash_str_find(pre->meta, k->ptr, k->len);
                if (val && Z_TYPE_P(val) == IS_STRING) {
                    peer_tags[actual_peer_tags].key   = *k;
                    peer_tags[actual_peer_tags].value = dd_zend_string_to_CharSlice(Z_STR_P(val));
                    actual_peer_tags++;
                }
            }
        }
    } else if (!pre->span_kind || !zend_string_equals_literal(pre->span_kind, "server")) {
        // internal or no span.kind: use _dd.base_service only if it marks a service override
        static const ddog_CharSlice BASE_SERVICE_KEY = DDOG_CHARSLICE_C_BARE("_dd.base_service");
        zval *base_svc_zv = zend_hash_str_find(pre->meta, ZEND_STRL("_dd.base_service"));
        if (base_svc_zv && Z_TYPE_P(base_svc_zv) == IS_STRING) {
            peer_tags[0].key   = BASE_SERVICE_KEY;
            peer_tags[0].value = dd_zend_string_to_CharSlice(Z_STR_P(base_svc_zv));
            actual_peer_tags   = 1;
        }
    }
    // "server" spans have no special handling
    stats.peer_tags_count = actual_peer_tags;
    stats.peer_tags = actual_peer_tags > 0 ? peer_tags : NULL;

    if (ddog_span_concentrator_has_shm(c)) {
        ddog_span_concentrator_add_php_span(c, &stats);
    } else {
        // No backing SHM yet; submit via sidecar IPC instead.
        for (size_t i = 0; i < actual_peer_tags; i++) {
            data->ipc_peer_tags[i] = peer_tags[i];
        }
        data->ipc_stats = stats;
        data->ipc_stats.peer_tags_count = actual_peer_tags;
        data->ipc_stats.peer_tags       = actual_peer_tags > 0 ? data->ipc_peer_tags : NULL;
        data->needs_ipc = true;
    }
}

void ddtrace_feed_span_to_concentrator(ddtrace_span_data *span, const ddtrace_span_precomputed *pre) {
    ddog_CharSlice env_slice     = dd_zend_string_to_CharSlice(pre->env);
    ddog_CharSlice version_slice = dd_zend_string_to_CharSlice(pre->version);
    // Use the process-level DD_SERVICE as the concentrator key so all spans from this PHP
    // process share one SHM concentrator regardless of per-request service overrides.
    ddog_CharSlice service_slice = dd_zend_string_to_CharSlice(get_global_DD_SERVICE());

    ddtrace_concentrator_cb_data data = { .span = span, .pre = pre, .needs_ipc = false };
    ddog_span_concentrator_with(env_slice, version_slice, service_slice, ddtrace_span_concentrator_feed_cb, &data);

    if (data.needs_ipc && ddtrace_sidecar) {
        ddog_sidecar_add_php_span_to_concentrator(&ddtrace_sidecar, env_slice, version_slice, &data.ipc_stats);
    }
}
