/* ddog_php_experiment extension for PHP (c) 2023 Levi Morrison <levi.morrison@datadoghq.com> */

#ifndef PHP_DDOG_PHP_EXPERIMENT_H
#define PHP_DDOG_PHP_EXPERIMENT_H

extern zend_module_entry ddog_php_experiment_module_entry;
#define phpext_ddog_php_experiment_ptr &ddog_php_experiment_module_entry

#define PHP_DDOG_PHP_EXPERIMENT_VERSION "0.1.0"

#if defined(ZTS) && defined(COMPILE_DL_DDOG_PHP_EXPERIMENT)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif /* PHP_DDOG_PHP_EXPERIMENT_H */
