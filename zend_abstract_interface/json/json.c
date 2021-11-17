#include "json.h"

int (*php_json_encode)(smart_str *buf, zval *val, int options ZAI_TSRMLS_CC);
int (*php_json_decode_ex)(zval *return_value, const char *str, size_t str_len, zend_long options,
                          zend_long depth ZAI_TSRMLS_CC);

void zai_json_setup_bindings(void) {
    zend_module_entry *json_me;
#if PHP_VERSION_ID < 70000
    zend_hash_find(&module_registry, ZEND_STRS("json"), (void **)&json_me)
#else
    json_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("json"));
#endif

        php_json_encode = DL_FETCH_SYMBOL(json_me->handle, "php_json_encode");
    if (php_json_encode == NULL) {
        php_json_encode = DL_FETCH_SYMBOL(json_me->handle, "_php_json_encode");
    }

    php_json_decode_ex = DL_FETCH_SYMBOL(json_me->handle, "php_json_decode_ex");
    if (php_json_decode_ex == NULL) {
        php_json_decode_ex = DL_FETCH_SYMBOL(json_me->handle, "_php_json_decode_ex");
    }
}
