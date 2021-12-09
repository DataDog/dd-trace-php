#ifndef PHP_DATADOG_PROFILING_H
#define PHP_DATADOG_PROFILING_H

#include <ddtrace_attributes.h>
#include <php_config.h>

#include <Zend/zend_extensions.h>

#include "ext/version.h"

#define PHP_DATADOG_PROFILING_VERSION PHP_DDTRACE_VERSION

DDTRACE_COLD int datadog_profiling_startup(zend_extension *);
void datadog_profiling_activate(void);
void datadog_profiling_deactivate(void);
DDTRACE_COLD void datadog_profiling_shutdown(zend_extension *);
DDTRACE_COLD void datadog_profiling_diagnostics(void);

#if defined(ZTS)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif // PHP_DATADOG_PROFILING_H
