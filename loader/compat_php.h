#ifndef DD_LIBRARY_LOADER_COMPAT_PHP_H
#define DD_LIBRARY_LOADER_COMPAT_PHP_H

#include <php.h>

zend_string *ddloader_zend_string_alloc(int php_api_no, size_t len, int persistent);
zend_string *ddloader_zend_string_init(int php_api_no, const char *str, size_t len, bool persistent);
void ddloader_zend_string_release(int php_api_no, zend_string *s);
zval *ddloader_zend_hash_set_bucket_key(int php_api_no, HashTable *ht, Bucket *b, zend_string *key);
void *ddloader_zend_hash_str_find_ptr(int php_api_no, const HashTable *ht, const char *str, size_t len);
void ddloader_zend_hash_str_del(int php_api_no, HashTable *ht, const char *str, size_t len);
void ddloader_replace_zend_error_cb(int php_api_no);
void ddloader_restore_zend_error_cb();
zval *ddloader_zend_hash_update(HashTable *ht, zend_string *key, zval *pData);
bool ddloader_zend_ini_parse_bool(zend_string *str);

#if PHP_VERSION_ID < 80000
typedef int zend_result;
#endif

typedef struct {
    const char *name;
    ZEND_INI_MH((*on_modify));
    void *mh_arg1;
    void *mh_arg2;
    void *mh_arg3;
    const char *value;
    void (*displayer)(zend_ini_entry *ini_entry, int type);
    int modifiable;

    uint name_length;
    uint value_length;
} php7_0_to_2_zend_ini_entry_def;

#undef ZEND_INI_ENTRY3_EX
#define ZEND_INI_ENTRY3_EX(_name, _default_value, _modifiable, _on_modify, _arg1, _arg2, _arg3, _displayer) \
	{ .name = _name, .on_modify = _on_modify, .mh_arg1 = _arg1, .mh_arg2 = _arg2, .mh_arg3 = _arg3, .value = _default_value, .displayer = _displayer, .modifiable = _modifiable, .name_length = sizeof(_name)-1, .value_length = sizeof(_default_value)-1 },


#endif /* DD_LIBRARY_LOADER_COMPAT_PHP_H */
