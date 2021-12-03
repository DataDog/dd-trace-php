#include "json.h"

#if PHP_VERSION_ID < 70000
void (*zai_json_encode)(smart_str *buf, zval *val, int options TSRMLS_DC);
void (*zai_json_decode_ex)(zval *return_value, char *str, int str_len, int options, long depth TSRMLS_DC);
#else
int (*zai_json_encode)(smart_str *buf, zval *val, int options);
int (*zai_json_decode_ex)(zval *return_value, const char *str, size_t str_len, zend_long options, zend_long depth);
#endif

void zai_json_setup_bindings(void) {
    zend_module_entry *json_me;
#if PHP_VERSION_ID < 70000
    zend_hash_find(&module_registry, ZEND_STRS("json"), (void **)&json_me);
#else
    json_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("json"));
#endif

    zai_json_encode = DL_FETCH_SYMBOL(json_me->handle, "php_json_encode");
    if (zai_json_encode == NULL) {
        zai_json_encode = DL_FETCH_SYMBOL(json_me->handle, "_php_json_encode");
    }

    zai_json_decode_ex = DL_FETCH_SYMBOL(json_me->handle, "php_json_decode_ex");
    if (zai_json_decode_ex == NULL) {
        zai_json_decode_ex = DL_FETCH_SYMBOL(json_me->handle, "_php_json_decode_ex");
    }
}
