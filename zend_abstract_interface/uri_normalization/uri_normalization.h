#ifndef ZAI_URI_NORMALIZATION_H
#define ZAI_URI_NORMALIZATION_H

#include <main/php.h>
#include <zai_string/string.h>

/*
 * Given a uri path in the form '/user/123/path/Name' it returns a normalized path applying the rules:
 * e.g. '/user/?/path/?'
 * Note: it also accepts full urls which are preserved: http://example.com/int/123 ---> http://example.com/int/?
 */
#if PHP_VERSION_ID < 70000
zai_string_view zai_uri_normalize_path(zai_string_view path, HashTable *fragmentRegex, HashTable *mapping);
zai_string_view zai_filter_query_string(zai_string_view queryString, HashTable *whitelist);
#else
zend_string *zai_uri_normalize_path(zend_string *path, zend_array *fragmentRegex, zend_array *mapping);
zend_string *zai_filter_query_string(zai_string_view queryString, zend_array *whitelist);
#endif
#endif  // ZAI_URI_NORMALIZATION_H
