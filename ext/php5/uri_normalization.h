#ifndef DD_TRACE_URI_NORMALIZATION_H
#define DD_TRACE_URI_NORMALIZATION_H

#include <uri_normalization/uri_normalization.h>

#include "configuration.h"

static inline zai_string_view ddtrace_uri_normalize_incoming_path(zai_string_view path) {
    return zai_uri_normalize_path(path, get_DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX(),
                                  get_DD_TRACE_RESOURCE_URI_MAPPING_INCOMING());
}
static inline zai_string_view ddtrace_uri_normalize_outgoing_path(zai_string_view path) {
    return zai_uri_normalize_path(path, get_DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX(),
                                  get_DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING());
}

#endif  // DD_TRACE_URI_NORMALIZATION_H
