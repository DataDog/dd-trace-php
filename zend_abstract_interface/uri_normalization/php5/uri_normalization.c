#include "../uri_normalization.h"

#include <stdbool.h>

#include <ext/pcre/php_pcre.h>
#include <ext/standard/php_smart_str.h>
#include <ext/standard/php_string.h>

static zend_bool zai_starts_with_protocol(const char *str, size_t len) {
    // See: https://tools.ietf.org/html/rfc3986#page-17
    if (str[0] < 'a' || str[0] > 'z') {
        return false;
    }
    for (const char *ptr = str + 1, *end = str + len - 2; ptr < end; ++ptr) {
        if (ptr[0] == ':' && ptr[1] == '/' && ptr[2] == '/') {
            return true;
        }
        if ((*ptr < 'a' || *ptr > 'z') && (*ptr < 'A' || *ptr > 'Z') && (*ptr < '0' || *ptr > '9') && *ptr != '+' &&
            *ptr != '-' && *ptr != '.') {
            return false;
        }
    }
    return false;
}

static void zai_apply_fragment_regex(char **path, int *pathlen, char *fragment_regex, int fragment_len TSRMLS_DC) {
    // limit regex to only apply between two slashes (or slash and end)
    bool start_anchor = fragment_regex[0] == '^', end_anchor = fragment_regex[fragment_len - 1] == '$';
    char *regex;
    int regexlen = asprintf(&regex, "((?<=/)(?=[^/]++(.*$))%s%.*s%s(?=\\1))", start_anchor ? "" : "[^/]*",
                            fragment_len - start_anchor - end_anchor, fragment_regex + start_anchor,
                            end_anchor ? "(?=/|$)" : "[^/]*");
    zval replacementzv;
    ZVAL_STRING(&replacementzv, "?", 0);
    int replacements;
    char *substituted_path =
        php_pcre_replace(regex, regexlen, *path, *pathlen, &replacementzv, 0, pathlen, -1, &replacements TSRMLS_CC);
    if (substituted_path) {  // NULL on invalid regex
        efree(*path);
        *path = substituted_path;
    }
    free(regex);
}

zai_string_view zai_uri_normalize_path(zai_string_view path, HashTable *fragmentRegex, HashTable *mapping) {
    if (path.ptr == NULL || path.len == 0 || (path.len == 1 && path.ptr[0] == '/') || path.ptr[0] == '?') {
        return (zai_string_view){.ptr = estrdup("/"), .len = 1};
    }

    TSRMLS_FETCH();

    char *pathstr = estrndup(path.ptr, path.len);
    int pathlen = (int)path.len;

    // Removing query string
    char *query_str = strchr(pathstr, '?');
    if (query_str) {
        pathlen = (int)(query_str - pathstr);
        pathstr = erealloc(pathstr, pathlen + 1);
        pathstr[pathlen] = 0;
    }

    // We always expect leading slash if it is a pure path, while urls with RFC3986 complaint schemes are preserved.
    if (pathstr[0] != '/' && !zai_starts_with_protocol(pathstr, pathlen)) {
        pathstr = erealloc(pathstr, ++pathlen + 1);
        memmove(pathstr + 1, pathstr, pathlen);  // incl. trailing 0 byte
        pathstr[0] = '/';
    }

    HashPosition pos;
    zval **patternzv;
    for (zend_hash_internal_pointer_reset_ex(mapping, &pos);
         zend_hash_get_current_data_ex(mapping, (void **)&patternzv, &pos) == SUCCESS;
         zend_hash_move_forward_ex(mapping, &pos)) {
        ulong num_key;
        char *str_key;
        int str_keylen;
        if (zend_hash_get_current_key_ex(mapping, &str_key, (uint *)&str_keylen, &num_key, 0, &pos) ==
            HASH_KEY_IS_STRING) {
            zval pattern;
            php_trim(str_key, str_keylen, NULL, 0, &pattern, 3 TSRMLS_CC);
            if (Z_STRLEN(pattern)) {
                // build a regex starting with a /, properly escaped and * replaced by [^/]+
                size_t newlen;  // Used by smart_str_alloc macro
                smart_str regex = {0}, replacement = {0};
                smart_str_alloc(&regex, Z_STRLEN(pattern) * 4 + 10, 0);
                smart_str_alloc(&replacement, Z_STRLEN(pattern), 0);
                smart_str_appends(&regex, "((?<=/)");
                for (char *ptr = Z_STRVAL(pattern), *end = Z_STRVAL(pattern) + Z_STRLEN(pattern); ptr < end; ++ptr) {
                    if (*ptr == '*') {
                        smart_str_appends(&regex, "[^/]+");
                        smart_str_appendc(&replacement, '?');
                    } else {
                        if (strchr(".\\+?[^]$(){}=!><|:-#", *ptr)) {
                            smart_str_appendc(&regex, '\\');
                        }
                        smart_str_appendc(&regex, *ptr);
                        smart_str_appendc(&replacement, *ptr);
                    }
                }
                smart_str_appendc(&regex, ')');
                smart_str_0(&regex);
                smart_str_0(&replacement);

                zval replacementzv;
                ZVAL_STRINGL(&replacementzv, replacement.c, replacement.len, 0);

                int replacements;
                char *substituted_path = php_pcre_replace(regex.c, (int)regex.len, pathstr, pathlen, &replacementzv, 0,
                                                          &pathlen, -1, &replacements TSRMLS_CC);
                efree(pathstr);
                pathstr = substituted_path;

                smart_str_free(&regex);
                smart_str_free(&replacement);
            }
            efree(Z_STRVAL(pattern));
        }
    }

    zai_apply_fragment_regex(&pathstr, &pathlen, ZEND_STRL("^\\d+$") TSRMLS_CC);
    zai_apply_fragment_regex(
        &pathstr, &pathlen,
        ZEND_STRL("^[0-9a-fA-F]{8}-?[0-9a-fA-F]{4}-?[1-5][0-9a-fA-F]{3}-?[89abAB][0-9a-fA-F]{3}-?[0-9a-fA-F]{12}$")
            TSRMLS_CC);
    zai_apply_fragment_regex(&pathstr, &pathlen, ZEND_STRL("^[0-9a-fA-F]{8,128}$") TSRMLS_CC);

    zval **fragementzv;
    for (zend_hash_internal_pointer_reset_ex(fragmentRegex, &pos);
         zend_hash_get_current_data_ex(fragmentRegex, (void **)&fragementzv, &pos) == SUCCESS;
         zend_hash_move_forward_ex(fragmentRegex, &pos)) {
        ulong num_key;
        char *str_key;
        int str_keylen;
        if (zend_hash_get_current_key_ex(fragmentRegex, &str_key, (uint *)&str_keylen, &num_key, 0, &pos) ==
            HASH_KEY_IS_STRING) {
            zval trimmed_regex;
            php_trim(str_key, str_keylen, ZEND_STRL(" \t\n\r\v\0/"), &trimmed_regex, 3 TSRMLS_CC);
            if (Z_STRLEN(trimmed_regex)) {
                zai_apply_fragment_regex(&pathstr, &pathlen, Z_STRVAL(trimmed_regex),
                                         Z_STRLEN(trimmed_regex) TSRMLS_CC);
            }
            efree(Z_STRVAL(trimmed_regex));
        }
    }

    return (zai_string_view){.ptr = pathstr, .len = pathlen};
}

zai_string_view zai_filter_query_string(zai_string_view queryString, HashTable *whitelist) {
    if (zend_hash_num_elements(whitelist) == 0) {
        return (zai_string_view){.len = 0, .ptr = ecalloc(1, 1)};
    }
    if (zend_hash_num_elements(whitelist) == 1) {  // * is wildcard
        char *str;
        zend_ulong numkey;
        zend_hash_get_current_key(whitelist, &str, &numkey, 0);
        if (strcmp(str, "*") == 0) {
            return (zai_string_view){.ptr = estrndup(queryString.ptr, queryString.len), .len = queryString.len};
        }
    }

    smart_str filtered = {0};

    for (const char *start = queryString.ptr, *ptr = start, *end = ptr + queryString.len; ptr < end; ++ptr) {
        if (*ptr == '&') {
            if (ptr != start) {
                char *dup = estrndup(start, ptr - start);
                if (zend_hash_exists(whitelist, dup, ptr - start + 1)) {
                    efree(dup);
                add_str:
                    if (filtered.c) {
                        smart_str_appendc(&filtered, '&');
                    }
                    smart_str_appendl(&filtered, start, ptr - start);
                } else {
                    efree(dup);
                }
            }
            start = ptr + 1;
        } else if (*ptr == '=') {
            char *dup = estrndup(start, ptr - start);
            zend_bool keep = zend_hash_exists(whitelist, dup, ptr - start + 1);
            efree(dup);
            while (ptr < end && *ptr != '&') {
                ++ptr;
            }
            if (keep) {
                goto add_str;
            }
            start = ptr + 1;
        }
    }

    if (filtered.c) {
        smart_str_0(&filtered);
        return (zai_string_view){.len = filtered.len, .ptr = filtered.c};
    }
    return (zai_string_view){.len = 0, .ptr = ecalloc(1, 1)};
}
