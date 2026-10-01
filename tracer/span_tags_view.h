#ifndef DD_SPAN_TAGS_VIEW_H
#define DD_SPAN_TAGS_VIEW_H

#include <php.h>
#include <stdbool.h>

#include "span.h"

// SpanData::$meta / $metrics are views onto SpanData::$attributes: meta holds the non-numeric
// entries, metrics the numeric ones.
extern zend_class_entry *ddtrace_ce_span_tags_view;

void ddtrace_register_span_tags_view(void);
void ddtrace_span_tags_view_install(zend_object_handlers *handlers);
void ddtrace_span_tags_views_release(ddtrace_span_data *span);
// Handles assignments to $meta/$metrics; false for any other property.
bool ddtrace_span_tags_view_write_property(zend_object *obj, zend_string *name, zval *value);

#endif  // DD_SPAN_TAGS_VIEW_H
