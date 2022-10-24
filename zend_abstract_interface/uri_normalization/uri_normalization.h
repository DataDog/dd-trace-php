#ifndef ZAI_URI_NORMALIZATION_H
#define ZAI_URI_NORMALIZATION_H

#include <main/php.h>
#include <zai_string/string.h>

/*
 * Given a uri path in the form '/user/123/path/Name' it returns a normalized path applying the rules:
 * e.g. '/user/?/path/?'
 * Note: it also accepts full urls which are preserved: http://example.com/int/123 ---> http://example.com/int/?
 */
zend_string *zai_uri_normalize_path(zend_string *path, zend_array *fragmentRegex, zend_array *mapping);
zend_string *zai_filter_query_string(zai_string_view queryString, zend_array *whitelist, zend_string *pattern);
#endif  // ZAI_URI_NORMALIZATION_H
