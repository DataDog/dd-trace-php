#include "json.h"

#if PHP_VERSION_ID < 70100
void (*zai_json_encode)(smart_str *buf, zval *val, int options TSRMLS_DC);
void (*zai_json_decode_ex)(zval *return_value, char *str, int str_len, int options, long depth TSRMLS_DC);

__attribute__((weak)) void php_json_encode(smart_str *buf, zval *val, int options TSRMLS_DC);
__attribute__((weak)) void php_json_decode_ex(zval *return_value, char *str, int str_len, int options,
                                              long depth TSRMLS_DC);
#elif PHP_VERSION_ID < 80000
int (*zai_json_encode)(smart_str *buf, zval *val, int options);
int (*zai_json_decode_ex)(zval *return_value, char *str, size_t str_len, zend_long options, zend_long depth);

__attribute__((weak)) int php_json_encode(smart_str *buf, zval *val, int options);
__attribute__((weak)) int php_json_decode_ex(zval *return_value, char *str, size_t str_len, zend_long options,
                                             zend_long depth);
#else
int (*zai_json_encode)(smart_str *buf, zval *val, int options);
int (*zai_json_decode_ex)(zval *return_value, const char *str, size_t str_len, zend_long options, zend_long depth);

__attribute__((weak)) int php_json_encode(smart_str *buf, zval *val, int options);
__attribute__((weak)) int php_json_decode_ex(zval *return_value, const char *str, size_t str_len, zend_long options,
                                             zend_long depth);
#endif
__attribute__((weak)) zend_class_entry *php_json_serializable_ce = NULL;

void zai_fetch_json_serializable_ce(zend_module_entry *json_me) {
    if (php_json_serializable_ce) return;

    if (json_me == NULL) {
        json_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("json"));
    }

    zend_class_entry **tmp_json_serializable_ce = (zend_class_entry **)DL_FETCH_SYMBOL(json_me->handle, "php_json_serializable_ce");
    if (tmp_json_serializable_ce == NULL) {
        tmp_json_serializable_ce = (zend_class_entry **)DL_FETCH_SYMBOL(json_me->handle, "_php_json_serializable_ce");
    }
    if (tmp_json_serializable_ce != NULL) {
        php_json_serializable_ce = *tmp_json_serializable_ce;
    }
}

bool zai_json_setup_bindings(void) {
    if (php_json_encode && php_json_decode_ex) {
        zai_json_encode = php_json_encode;
        zai_json_decode_ex = php_json_decode_ex;
        zai_fetch_json_serializable_ce(NULL);
        return true;
    }

    zend_module_entry *json_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("json"));

    if (!json_me) return false;

    zai_json_encode = DL_FETCH_SYMBOL(json_me->handle, "php_json_encode");
    if (zai_json_encode == NULL) {
        zai_json_encode = DL_FETCH_SYMBOL(json_me->handle, "_php_json_encode");
    }

    zai_json_decode_ex = DL_FETCH_SYMBOL(json_me->handle, "php_json_decode_ex");
    if (zai_json_decode_ex == NULL) {
        zai_json_decode_ex = DL_FETCH_SYMBOL(json_me->handle, "_php_json_decode_ex");
    }

    zai_fetch_json_serializable_ce(json_me);

    return zai_json_encode && zai_json_decode_ex;
}
