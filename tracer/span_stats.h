#ifndef DD_SPAN_STATS_H
#define DD_SPAN_STATS_H

#include "span.h"

/**
 * Fields precomputed by the serializer and shared with the span stats subsystem.
 *
 * The serializer computes service (with mapping), name, resource, and type once for its own
 * purposes.  This struct carries those results so the concentrator callback can use them directly
 * instead of re-fetching them.
 *
 * Owned strings (service, name, resource, type) are zend_string* that must be released with
 * ddtrace_free_span_precomputed() when no longer needed.  NULL means the field is absent.
 *
 * attributes is a borrowed pointer (no ownership transfer); all attribute lookups read it.
 */
typedef struct {
    zend_array *attributes;

    /* Owned strings — release with ddtrace_free_span_precomputed() */
    zend_string *service;    /* mapped service name */
    zend_string *name;       /* operation name */
    zend_string *resource;   /* resolved resource; falls back to name when property_resource is empty */
    zend_string *type;       /* span type */
    zend_string *env;        /* from span->property_env; NULL when empty */
    zend_string *version;    /* from span->property_version; NULL when empty */

    zend_long span_kind;     /* SpanData::$spanKind (DDTrace\SpanKind) */
    bool has_exception; /* when span->property_exception holds a Throwable */
    bool ignore_error;  /* SpanData::$ignoreError */

    /* Stats-specific fields — precomputed to avoid repeated lookups across two call sites. */
    bool has_top_level;       /* ddtrace_span_is_entrypoint_root() */
    bool is_measured;         /* attributes["_dd.measured"] != 0 */
    bool is_partial_snapshot; /* always false until partial-flush is implemented */
} ddtrace_span_precomputed;

/**
 * Fill *pre from the span's properties and attributes.
 */
void ddtrace_precompute_span(ddtrace_span_data *span, ddtrace_span_precomputed *pre);

/** Release the owned strings inside *pre (does not free pre itself). */
void ddtrace_free_span_precomputed(ddtrace_span_precomputed *pre);

/**
 * Compute whether a span should be marked as an error.
 * Checks the error.message/error.type tags, pre->has_exception, and pre->ignore_error.
 * Accurate for both sampled and non-sampled spans (HTTP errors are in the attributes via
 * dd_ser_response_committed before the span is closed).
 */
bool dd_compute_span_is_error(const ddtrace_span_precomputed *pre);

/**
 * Feed a closed PHP span into the appropriate per-(env,version) concentrator.
 * pre must have been filled by ddtrace_precompute_span().
 */
void ddtrace_feed_span_to_concentrator(ddtrace_span_data *span, const ddtrace_span_precomputed *pre);

#endif  // DD_SPAN_STATS_H
