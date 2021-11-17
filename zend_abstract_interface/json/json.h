#ifndef ZAI_JSON_H
#define ZAI_JSON_H

#include "php.h"
#include "../zai_compat.h"
#if PHP_VERSION_ID < 70000
#include "ext/standard/php_smart_str.h"
#else
#include "zend_smart_str_public.h"
#endif

#define php_json_encode _dd_json_encode
#define php_json_decode_ex _dd_json_decode_ex

#define PHP_JSON_OBJECT_AS_ARRAY (1 << 0)

extern int (*php_json_encode)(smart_str *buf, zval *val, int options ZAI_TSRMLS_CC);
extern int (*php_json_decode_ex)(zval *return_value, const char *str, size_t str_len, zend_long options,
                                 zend_long depth ZAI_TSRMLS_CC);

static inline int php_json_decode(zval *return_value, const char *str, int str_len, bool assoc, zend_long depth) {
    return php_json_decode_ex(return_value, str, str_len, assoc ? PHP_JSON_OBJECT_AS_ARRAY : 0, depth);
}

void zai_json_setup_bindings(void);

#endif  // ZAI_JSON_H
