#ifndef ZAI_EXCEPTIONS_H
#define ZAI_EXCEPTIONS_H

#include <main/php.h>

#include <ext/standard/php_smart_str_public.h>
// dummy comment here to prevent clang format fixer from reordering includes here
#include <Zend/zend_exceptions.h>
#include <properties/properties.h>
#include <zai_string/string.h>

#define ZAI_EXCEPTION_PROPERTY(objzv, name) \
    zai_read_property_direct_literal(zend_exception_get_default(TSRMLS_C), objzv, name)

zai_string_view zai_exception_message(zval *ex TSRMLS_DC);  // fallback string if message invalid
smart_str zai_get_trace_without_args(HashTable *trace);
smart_str zai_get_trace_without_args_from_exception(zval *ex TSRMLS_DC);

#endif  // ZAI_EXCEPTIONS_H
