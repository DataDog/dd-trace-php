#ifndef PHP_DATADOG_PROFILING_H
#define PHP_DATADOG_PROFILING_H

#include <php_config.h>

#include <Zend/zend_extensions.h>

#define PHP_DATADOG_PROFILING_VERSION "0.2.0"

ZEND_API int datadog_profiling_startup(zend_extension *);
ZEND_API void datadog_profiling_activate(void);
ZEND_API void datadog_profiling_deactivate(void);
ZEND_API void datadog_profiling_shutdown(zend_extension *);

ZEND_COLD void datadog_profiling_info_diagnostics_row(const char *col_a,
                                                      const char *col_b);

#if defined(ZTS)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif // PHP_DATADOG_PROFILING_H
