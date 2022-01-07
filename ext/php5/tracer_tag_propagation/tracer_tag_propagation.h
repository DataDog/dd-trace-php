#ifndef DDTRACE_TRACER_TAG_PROPAGATION_H
#define DDTRACE_TRACER_TAG_PROPAGATION_H

#include <php.h>
#include <zai_string/string.h>

void ddtrace_add_tracer_tags_from_header(zai_string_view *headerstr TSRMLS_DC);
zai_string_view ddtrace_format_propagated_tags(TSRMLS_D);

#endif  // DDTRACE_TRACER_TAG_PROPAGATION_H
