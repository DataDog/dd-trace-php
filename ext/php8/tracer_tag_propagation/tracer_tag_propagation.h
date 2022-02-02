#ifndef DDTRACE_TRACER_TAG_PROPAGATION_H
#define DDTRACE_TRACER_TAG_PROPAGATION_H

#include <php.h>

void ddtrace_add_tracer_tags_from_header(zend_string *headerstr);
void ddtrace_add_tracer_tags_from_array(zend_array *array);

zval ddtrace_get_propagated_tags(void);
zend_string *ddtrace_format_propagated_tags(void);

#endif  // DDTRACE_TRACER_TAG_PROPAGATION_H
