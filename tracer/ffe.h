#ifndef DDTRACE_FFE_H
#define DDTRACE_FFE_H

#include <stdbool.h>
#include <stddef.h>
#include <zend.h>

bool datadog_ffe_record_evaluation_metric(const char *flag_key, size_t flag_key_len, const char *variant, size_t variant_len, const char *reason, size_t reason_len, const char *error_type, size_t error_type_len, const char *allocation_key, size_t allocation_key_len);
bool datadog_ffe_flush_evaluation_metrics(void);

void ddtrace_ffe_record_exposure(zend_string *flag_key, zend_string *targeting_key, zend_string *subject_attributes_json, zend_string *allocation_key, zend_string *variant);
bool ddtrace_ffe_flush_exposures(void);

#endif // DDTRACE_FFE_H
