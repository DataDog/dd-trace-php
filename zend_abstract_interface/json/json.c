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

bool zai_json_setup_bindings(void) {
    if (php_json_encode && php_json_decode_ex) {
        zai_json_encode = php_json_encode;
        zai_json_decode_ex = php_json_decode_ex;
        return true;
    }

    zend_module_entry *json_me = NULL;
#if PHP_VERSION_ID < 70000
    zend_hash_find(&module_registry, ZEND_STRS("json"), (void **)&json_me);
#else
    json_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("json"));
#endif

    if (!json_me) return false;

    zai_json_encode = DL_FETCH_SYMBOL(json_me->handle, "php_json_encode");
    if (zai_json_encode == NULL) {
        zai_json_encode = DL_FETCH_SYMBOL(json_me->handle, "_php_json_encode");
    }

    zai_json_decode_ex = DL_FETCH_SYMBOL(json_me->handle, "php_json_decode_ex");
    if (zai_json_decode_ex == NULL) {
        zai_json_decode_ex = DL_FETCH_SYMBOL(json_me->handle, "_php_json_decode_ex");
    }

    return zai_json_encode && zai_json_decode_ex;
}
