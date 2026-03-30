#ifndef DD_SPAN_STATS_H
#define DD_SPAN_STATS_H

#include "span.h"

/**
 * Fields precomputed by the serializer and shared with the span stats subsystem.
 *
 * The serializer computes service (with mapping), name, resource, and type once for its own
 * purposes.  This struct carries those results so the concentrator callback can use them directly
 * instead of re-fetching from meta/metrics.
 *
 * Owned strings (service, name, resource, type) are zend_string* that must be released with
 * ddtrace_free_span_precomputed() when no longer needed.  NULL means the field is absent.
 *
 * meta and metrics are borrowed pointers (no ownership transfer).
 * zval* fields below are also borrowed from meta/metrics and share the same lifetime.
 */
typedef struct {
    zend_array *meta;
    zend_array *metrics;

    /* Owned strings — release with ddtrace_free_span_precomputed() */
    zend_string *service;    /* mapped service name */
    zend_string *name;       /* resolved operation name, lowercased when from operation.name meta */
    zend_string *resource;   /* resolved resource; falls back to name when property_resource is empty */
    zend_string *type;       /* resolved span type */
    zend_string *env;        /* from span->property_env; NULL when empty */
    zend_string *version;    /* from span->root->property_version; NULL when empty */
    zend_string *span_kind;   /* meta["span.kind"], NULL if absent or not a string */

    /* True when the value came from a meta override (serializer must delete that meta key) */
    bool service_from_meta;
    bool name_from_meta;
    bool resource_from_meta;
    bool type_from_meta;

    /* True when the span's meta hash contains a deprecated "env"/"version" key */
    bool env_deprecated;
    bool version_deprecated;

    /* True when span->property_exception holds a Throwable; used by dd_compute_span_is_error() */
    bool has_exception;

    /* Stats-specific fields — precomputed to avoid repeated lookups across two call sites. */
    bool has_top_level;       /* ddtrace_span_is_entrypoint_root() */
    bool is_measured;         /* metrics["_dd.measured"] != 0 */
    bool is_partial_snapshot; /* always false until partial-flush is implemented */
} ddtrace_span_precomputed;

/**
 * Fill *pre from span's properties and meta hash tables.
 * Must be called before any meta modifications (OTel remapping, meta key deletion).
 */
void ddtrace_precompute_span(ddtrace_span_data *span, ddtrace_span_precomputed *pre);

/** Release the owned strings inside *pre (does not free pre itself). */
void ddtrace_free_span_precomputed(ddtrace_span_precomputed *pre);

/**
 * Compute whether a span should be marked as an error.
 * Checks meta["error.message"], meta["error.type"], pre->has_exception, and meta["error.ignored"].
 * Accurate for both sampled and non-sampled spans (HTTP errors are in PHP meta via
 * dd_ser_response_committed before the span is closed).
 */
bool dd_compute_span_is_error(const ddtrace_span_precomputed *pre);

/**
 * Feed a closed PHP span into the appropriate per-(env,version) concentrator.
 * pre must have been filled by ddtrace_precompute_span().
 */
void ddtrace_feed_span_to_concentrator(ddtrace_span_data *span, const ddtrace_span_precomputed *pre);

#endif  // DD_SPAN_STATS_H
