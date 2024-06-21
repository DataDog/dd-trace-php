#ifndef DD_LIBRARY_LOADER_COMPAT_PHP_H
#define DD_LIBRARY_LOADER_COMPAT_PHP_H

#include <php.h>

zend_string *ddloader_zend_string_alloc(int php_api_no, size_t len, int persistent);
zend_string *ddloader_zend_string_init(int php_api_no, const char *str, size_t len, bool persistent);
void ddloader_zend_string_release(int php_api_no, zend_string *s);
zval *ddloader_zend_hash_set_bucket_key(int php_api_no, HashTable *ht, Bucket *b, zend_string *key);
void ddloader_replace_zend_error_cb(int php_api_no);
void ddloader_restore_zend_error_cb();
zval *ddloader_zend_hash_update(HashTable *ht, zend_string *key, zval *pData);

#endif /* DD_LIBRARY_LOADER_COMPAT_PHP_H */
