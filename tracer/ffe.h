#ifndef DDTRACE_FFE_H
#define DDTRACE_FFE_H

#include <stdbool.h>
#include <stddef.h>
#include <zend.h>

bool ddtrace_ffe_record_evaluation_metric(zend_string *flag_key, zend_string *variant, const char *reason, const char *error_type, zend_string *allocation_key);
bool ddtrace_ffe_flush_evaluation_metrics(void);

void ddtrace_ffe_record_exposure(zend_string *flag_key, zend_string *targeting_key, zend_string *subject_attributes_json, zend_string *allocation_key, zend_string *variant);
bool ddtrace_ffe_flush_exposures(void);

// APM feature-flag span enrichment (PHP-01): request-scoped, gate-gated tag
// staging written onto the root span when it closes.
void ddtrace_ffe_set_span_enrichment_tags(zend_string *flags_enc, zend_string *subjects_enc, zend_string *runtime_defaults);
bool ddtrace_ffe_has_span_enrichment_tags(void);
void ddtrace_ffe_clear_span_enrichment_tags(void);
void ddtrace_ffe_flush_span_enrichment_tags(zend_array *meta);

#endif // DDTRACE_FFE_H
