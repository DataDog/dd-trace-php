#ifndef DDTRACE_TRACER_TAG_PROPAGATION_H
#define DDTRACE_TRACER_TAG_PROPAGATION_H

#include <php.h>

void ddtrace_clean_tracer_tags(zend_array *root_meta, zend_array *propagated_tags);
void ddtrace_add_tracer_tags_from_header(zend_string *headerstr, zend_array *root_meta, zend_array *propagated_tags);
void ddtrace_add_tracer_tags_from_array(zend_array *array, zend_array *root_meta, zend_array *propagated_tags);

void ddtrace_get_propagated_tags(zend_array *tags);
zend_string *ddtrace_format_root_propagated_tags(void);
zend_string *ddtrace_format_propagated_tags(zend_array *propagated, zend_array *tags);

#endif  // DDTRACE_TRACER_TAG_PROPAGATION_H
