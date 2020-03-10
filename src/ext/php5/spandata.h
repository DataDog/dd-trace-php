#ifndef DDTRACE_SPANDATA_HH
#define DDTRACE_SPANDATA_HH

#include "compatibility.h"

extern zend_class_entry *ddtrace_spandata_ce;

// todo: fully implement this on PHP 5 if the feature proves valuable

void ddtrace_spandata_register_ce(TSRMLS_D);

#endif  // DDTRACE_SPANDATA_HH
