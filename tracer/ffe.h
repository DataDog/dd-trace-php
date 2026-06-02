#ifndef DDTRACE_FFE_H
#define DDTRACE_FFE_H

#include <stdbool.h>
#include <stddef.h>

bool datadog_ffe_record_evaluation_metric(const char *flag_key, size_t flag_key_len, const char *variant, size_t variant_len, const char *reason, size_t reason_len, const char *error_type, size_t error_type_len, const char *allocation_key, size_t allocation_key_len);
bool datadog_ffe_flush_evaluation_metrics(void);

#endif // DDTRACE_FFE_H
