#ifndef DD_LIBRARY_LOADER_COMPAT_PHP_H
# define DD_LIBRARY_LOADER_COMPAT_PHP_H

#include <php.h>

zval* ddloader_zend_hash_set_bucket_key(int php_api_no, HashTable *ht, Bucket *b, zend_string *key);

#endif	/* DD_LIBRARY_LOADER_COMPAT_PHP_H */
