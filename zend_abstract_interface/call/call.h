#ifndef ZAI_CALL_H
#define ZAI_CALL_H

#include "php.h"

#include "../zai_string/string.h"
#include "zai_compat.h"

#include <stdbool.h>
#include <stdint.h>

// clang-format off
typedef enum {
    /* The next parameter is expected to be zend_class_entry* */
    ZAI_CALL_SCOPE_STATIC,
    /* The next parameter is expected to be zval* and Z_TYPE_P is IS_OBJECT */
    ZAI_CALL_SCOPE_OBJECT,
    /* The next parameter is expected to be null */
    ZAI_CALL_SCOPE_GLOBAL,
    /* The next parameter is zai_string_view* (FQCN) */
    ZAI_CALL_SCOPE_NAMED
} zai_call_scope_t;

typedef enum {
    /* The next parameter is zend_function* */
    ZAI_CALL_FUNCTION_KNOWN,
    /* The next parameter is zai_string_view* */
    ZAI_CALL_FUNCTION_NAMED
} zai_call_function_t;

zend_class_entry*
    zai_call_lookup_class(
        zai_call_scope_t scope_type, zai_string_view* scope ZAI_TSRMLS_DC);

zend_function*
    zai_call_lookup_function(
        zai_call_scope_t scope_type, void *scope,
        zai_call_function_t function_type, void *function ZAI_TSRMLS_DC);

bool zai_call(
    zai_call_scope_t scope_type, void *scope,
    zai_call_function_t function_type, void *function,
    zval **rv ZAI_TSRMLS_DC,
    uint32_t argc, ...);

bool zai_call_new(zval *zv, zend_class_entry *ce ZAI_TSRMLS_DC, uint32_t argc, ...);
// clang-format on
#endif  // ZAI_CALL_H
