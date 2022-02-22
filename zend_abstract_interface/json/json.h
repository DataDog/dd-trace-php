#ifndef ZAI_JSON_H
#define ZAI_JSON_H

#include <stdbool.h>

#include "php.h"

#if PHP_VERSION_ID < 70000
#include "ext/standard/php_smart_str.h"
#else
#include "zend_smart_str.h"
#endif

#define PHP_JSON_OBJECT_AS_ARRAY (1 << 0)

/* The JSON extension is a required module and ZAI JSON must work under the
 * following environments:
 *
 * 1. ext/json is built in tree (statically) with the json.h header installed
 * 2. ext/json is built statically without the json.h header installed
 * 3. ext/json is loaded as a shared library
 *
 * In order to accommodate all three of these scenarios, the symbol addresses
 * need to be resolved to ZAI-flavored function pointers at runtime. The edge
 * case where the symbol addresses cannot be resolved must be handled gracefully
 * to avoid a crash.
 *
 * WARNING: php_json_encode will not null terminate buf; Always do smart_str_0
 * on buf or risk shenanigans.
 */

#if PHP_VERSION_ID < 70100
extern void (*zai_json_encode)(smart_str *buf, zval *val, int options TSRMLS_DC);
extern void (*zai_json_decode_ex)(zval *return_value, char *str, int str_len, int options, long depth TSRMLS_DC);

static inline void zai_json_decode_assoc(zval *return_value, const char *str, int str_len, long depth TSRMLS_DC) {
    zai_json_decode_ex(return_value, (char *)str, str_len, PHP_JSON_OBJECT_AS_ARRAY, depth TSRMLS_CC);
}
#elif PHP_VERSION_ID < 80000
extern int (*zai_json_encode)(smart_str *buf, zval *val, int options);
extern int (*zai_json_decode_ex)(zval *return_value, char *str, size_t str_len, zend_long options, zend_long depth);

static inline int zai_json_decode_assoc(zval *return_value, const char *str, int str_len, zend_long depth) {
    return zai_json_decode_ex(return_value, (char *)str, str_len, PHP_JSON_OBJECT_AS_ARRAY, depth);
}
#else
extern int (*zai_json_encode)(smart_str *buf, zval *val, int options);
extern int (*zai_json_decode_ex)(zval *return_value, const char *str, size_t str_len, zend_long options,
                                 zend_long depth);

static inline int zai_json_decode_assoc(zval *return_value, const char *str, int str_len, zend_long depth) {
    return zai_json_decode_ex(return_value, str, str_len, PHP_JSON_OBJECT_AS_ARRAY, depth);
}
#endif

bool zai_json_setup_bindings(void);

#endif  // ZAI_JSON_H
