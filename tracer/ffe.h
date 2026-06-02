#ifndef DDTRACE_FFE_H
#define DDTRACE_FFE_H

#include <stdbool.h>
#include <zend.h>

void ddtrace_ffe_record_exposure(zend_string *flag_key, zend_string *targeting_key, zend_string *subject_attributes_json, zend_string *allocation_key, zend_string *variant);
bool ddtrace_ffe_flush_exposures(void);

#endif // DDTRACE_FFE_H
