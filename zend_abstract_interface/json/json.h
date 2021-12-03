#ifndef ZAI_JSON_H
#define ZAI_JSON_H

#include "php.h"

#define PHP_JSON_OBJECT_AS_ARRAY (1 << 0)

/**
 * This interface is 1:1 with ext/json, but with different names to avoid possible symbol clashes and confusion
 *
 * WARNING: php_json_encode will not null terminate buf; Always do smart_str_0 on buf or risk shenanigans
 **/

#if PHP_VERSION_ID < 70000
#include "ext/standard/php_smart_str.h"

extern void (*zai_json_encode)(smart_str *buf, zval *val, int options TSRMLS_DC);
extern void (*zai_json_decode_ex)(zval *return_value, char *str, int str_len, int options, long depth TSRMLS_DC);

static inline void zai_json_decode(zval *return_value, char *str, int str_len, zend_bool assoc, long depth TSRMLS_DC) {
    zai_json_decode_ex(return_value, str, str_len, assoc ? PHP_JSON_OBJECT_AS_ARRAY : 0, depth TSRMLS_CC);
}
#else
#include <stdbool.h>

#include "zend_smart_str.h"

extern int (*zai_json_encode)(smart_str *buf, zval *val, int options);
extern int (*zai_json_decode_ex)(zval *return_value, const char *str, size_t str_len, zend_long options,
                                 zend_long depth);

static inline int zai_json_decode(zval *return_value, const char *str, int str_len, bool assoc, zend_long depth) {
    return zai_json_decode_ex(return_value, str, str_len, assoc ? PHP_JSON_OBJECT_AS_ARRAY : 0, depth);
}
#endif

void zai_json_setup_bindings(void);

#endif  // ZAI_JSON_H
