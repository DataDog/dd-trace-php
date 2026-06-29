#ifndef DD_TRACE_URI_NORMALIZATION_H
#define DD_TRACE_URI_NORMALIZATION_H

#include <uri_normalization/uri_normalization.h>

#include "configuration.h"

static inline zend_string *ddtrace_uri_normalize_incoming_path(zend_string *path) {
    return zai_uri_normalize_path(path, get_DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX(),
                                  get_DD_TRACE_RESOURCE_URI_MAPPING_INCOMING());
}
static inline zend_string *ddtrace_uri_normalize_outgoing_path(zend_string *path) {
    return zai_uri_normalize_path(path, get_DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX(),
                                  get_DD_TRACE_RESOURCE_URI_MAPPING_OUTGOING());
}

#endif  // DD_TRACE_URI_NORMALIZATION_H
