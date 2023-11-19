#ifndef ZAI_JSON_H
#define ZAI_JSON_H

#include <stdbool.h>

#include "php.h"
#include "zend_smart_str.h"

#define PHP_JSON_OBJECT_AS_ARRAY (1 << 0)
#define PHP_JSON_PRETTY_PRINT    (1 << 7)

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

int zai_json_decode_assoc_safe(zval *return_value, const char *str, int str_len, long depth, bool persistent);

#if PHP_VERSION_ID < 70100
extern void (*zai_json_encode)(smart_str *buf, zval *val, int options);
extern void (*zai_json_decode_ex)(zval *return_value, char *str, int str_len, int options, long depth);
#elif PHP_VERSION_ID < 80000
extern int (*zai_json_encode)(smart_str *buf, zval *val, int options);
#else
extern int (*zai_json_encode)(smart_str *buf, zval *val, int options);
#endif

#ifdef __APPLE__
extern __attribute__((weak, weak_import)) zend_class_entry *php_json_serializable_ce;
#else
extern __attribute__((weak)) zend_class_entry *php_json_serializable_ce;
#endif

void zai_json_release_persistent_array(HashTable *ht);
void zai_json_dtor_pzval(zval *pval);
bool zai_json_setup_bindings(void);

#endif  // ZAI_JSON_H
