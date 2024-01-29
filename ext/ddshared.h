#ifndef DD_TRACE_SHARED_H
#define DD_TRACE_SHARED_H

#include <Zend/zend_string.h>

extern zend_string *ddtrace_php_version;

void ddshared_minit(void);
bool dd_rule_matches(zval *pattern, zval *prop, int rulesFormat);
bool dd_glob_rule_matches(zval *pattern, zend_string* value);

#endif  // DD_TRACE_SHARED_H
