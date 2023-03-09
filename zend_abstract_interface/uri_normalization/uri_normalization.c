#include "uri_normalization.h"

#include <Zend/zend_smart_str.h>
#include <stdbool.h>

#include <ext/pcre/php_pcre.h>
#include <ext/standard/php_string.h>

#if PHP_VERSION_ID < 70200
#define zend_strpprintf strpprintf
#define ZSTR_CHAR(chr) (CG(one_char_string)[chr] ? CG(one_char_string)[chr] : zend_string_init((char[]){chr, 0}, 1, 0))
static inline zend_string *zai_php_pcre_replace(zend_string *regex, zend_string *subject_str, char *subject,
                                                int subject_len, zend_string *replace_str, int limit,
                                                size_t *replace_count) {
    zval replacementzv;
    ZVAL_STR(&replacementzv, replace_str);
    return php_pcre_replace(regex, subject_str, subject, subject_len, &replacementzv, 0, limit, (int *)replace_count);
}
#define php_pcre_replace zai_php_pcre_replace
#elif PHP_VERSION_ID < 70300
#define php_pcre_replace(regex, subj, subjstr, subjlen, replace, limit, replacements) \
    php_pcre_replace(regex, subj, subjstr, subjlen, replace, limit, (int *)replacements)
#endif

static zend_bool zai_starts_with_protocol(zend_string *str) {
    // See: https://tools.ietf.org/html/rfc3986#page-17
    if (ZSTR_VAL(str)[0] < 'a' || ZSTR_VAL(str)[0] > 'z') {
        return false;
    }
    for (char *ptr = ZSTR_VAL(str) + 1, *end = ZSTR_VAL(str) + ZSTR_LEN(str) - 2; ptr < end; ++ptr) {
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

static void zai_apply_fragment_regex(zend_string **path, char *fragment_regex, int fragment_len) {
    // limit regex to only apply between two slashes (or slash and end)
    bool start_anchor = fragment_regex[0] == '^', end_anchor = fragment_regex[fragment_len - 1] == '$';
    zend_string *regex = zend_strpprintf(0, "((?<=/)(?=[^/]++(.*$))%s%.*s%s(?=\\1))", start_anchor ? "" : "[^/]*",
                                         fragment_len - start_anchor - end_anchor, fragment_regex + start_anchor,
                                         end_anchor ? "(?=/|$)" : "[^/]*");
    size_t replacements;
    zend_string *question_mark = ZSTR_CHAR('?');
    zend_string *substituted_path =
        php_pcre_replace(regex, *path, ZSTR_VAL(*path), ZSTR_LEN(*path), question_mark, -1, &replacements);
    if (substituted_path) {  // NULL on invalid regex
        zend_string_release(*path);
        *path = substituted_path;
    }
    zend_string_release(question_mark);
    zend_string_release(regex);
}

zend_string *zai_uri_normalize_path(zend_string *path, zend_array *fragmentRegex, zend_array *mapping) {
    if (path == NULL || ZSTR_LEN(path) == 0 || (ZSTR_LEN(path) == 1 && ZSTR_VAL(path)[0] == '/') ||
        ZSTR_VAL(path)[0] == '?') {
        return ZSTR_CHAR('/');
    }

    path = zend_string_copy(path);

    // Removing query string
    char *query_str = strchr(ZSTR_VAL(path), '?');
    if (query_str) {
        size_t new_len = query_str - ZSTR_VAL(path);
        path = zend_string_truncate(path, new_len, 0);
        ZSTR_VAL(path)[new_len] = 0;
    }

    // We always expect leading slash if it is a pure path, while urls with RFC3986 complaint schemes are preserved.
    if (ZSTR_VAL(path)[0] != '/' && !zai_starts_with_protocol(path)) {
        path = zend_string_realloc(path, ZSTR_LEN(path) + 1, 0);
        memmove(ZSTR_VAL(path) + 1, ZSTR_VAL(path), ZSTR_LEN(path));  // incl. trailing 0 byte
        ZSTR_VAL(path)[0] = '/';
    }

    zend_string *pattern;
    ZEND_HASH_FOREACH_STR_KEY(mapping, pattern) {
        pattern = php_trim(pattern, NULL, 0, 3);
        if (ZSTR_LEN(pattern)) {
            // build a regex starting with a /, properly escaped and * replaced by [^/]+
            smart_str regex = {0}, replacement = {0};
            smart_str_alloc(&regex, ZSTR_LEN(pattern) * 4 + 10, 0);
            smart_str_alloc(&replacement, ZSTR_LEN(pattern), 0);
            smart_str_appends(&regex, "((?<=/)");
            for (char *ptr = ZSTR_VAL(pattern), *end = ZSTR_VAL(pattern) + ZSTR_LEN(pattern); ptr < end; ++ptr) {
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

            size_t replacements;
            zend_string *substituted_path =
                php_pcre_replace(regex.s, path, ZSTR_VAL(path), ZSTR_LEN(path), replacement.s, -1, &replacements);
            zend_string_release(path);
            path = substituted_path;

            smart_str_free(&regex);
            smart_str_free(&replacement);
        }
        zend_string_release(pattern);
    }
    ZEND_HASH_FOREACH_END();

    zai_apply_fragment_regex(&path, ZEND_STRL("^\\d+$"));
    zai_apply_fragment_regex(
        &path,
        ZEND_STRL("^[0-9a-fA-F]{8}-?[0-9a-fA-F]{4}-?[1-5][0-9a-fA-F]{3}-?[89abAB][0-9a-fA-F]{3}-?[0-9a-fA-F]{12}$"));
    zai_apply_fragment_regex(&path, ZEND_STRL("^[0-9a-fA-F]{8,128}$"));

    zend_string *fragment_regex;
    ZEND_HASH_FOREACH_STR_KEY(fragmentRegex, fragment_regex) {
        zend_string *trimmed_regex = php_trim(fragment_regex, ZEND_STRL(" \t\n\r\v\0/"), 3);
        if (ZSTR_LEN(trimmed_regex)) {
            zai_apply_fragment_regex(&path, ZSTR_VAL(trimmed_regex), ZSTR_LEN(trimmed_regex));
        }
        zend_string_release(trimmed_regex);
    }
    ZEND_HASH_FOREACH_END();

    return path;
}

zend_string *zai_filter_query_string(zai_string_view queryString, zend_array *whitelist, zend_string *pattern) {
    if (zend_hash_num_elements(whitelist) == 0) {
        return ZSTR_EMPTY_ALLOC();
    }
    if (zend_hash_num_elements(whitelist) == 1) {  // * is wildcard
        zend_string *str;
        zend_ulong numkey;
        zend_hash_get_current_key(whitelist, &str, &numkey);
        if (zend_string_equals_literal(str, "*")) {
            zend_string *qs = zend_string_init(queryString.ptr, queryString.len, 0);
            if (pattern) {
                zend_string *replacement = zend_string_init(ZEND_STRL("<redacted>"), 0);
                zend_string *regex = zend_strpprintf(0, "(%.*s)", (int)ZSTR_LEN(pattern), ZSTR_VAL(pattern));

                zend_string *redacted_qs =
                    php_pcre_replace(regex, qs, ZSTR_VAL(qs), ZSTR_LEN(qs), replacement, -1, NULL);

                zend_string_release(regex);
                zend_string_release(replacement);

                if (redacted_qs) {
                    zend_string_release(qs);
                    return redacted_qs;
                }
            }
            return qs;
        }
    }

    smart_str filtered = {0};

    for (const char *start = queryString.ptr, *ptr = start, *end = ptr + queryString.len; ptr < end; ++ptr) {
        if (*ptr == '&') {
            if (ptr != start && zend_hash_str_exists(whitelist, start, ptr - start)) {
            add_str:
                if (filtered.s) {
                    smart_str_appendc(&filtered, '&');
                }
                smart_str_appendl(&filtered, start, ptr - start);
            }
            start = ptr + 1;
        } else if (*ptr == '=') {
            bool keep = zend_hash_str_exists(whitelist, start, ptr - start);
            while (ptr < end && *ptr != '&') {
                ++ptr;
            }
            if (keep) {
                goto add_str;
            }
            start = ptr + 1;
        }
    }

    if (filtered.s) {
        smart_str_0(&filtered);
        return filtered.s;
    }
    return ZSTR_EMPTY_ALLOC();
}

bool zai_match_regex(zend_string *pattern, zend_string *subject) {
    // If the subject matches the pattern, return true.
    // If the subject does not match the pattern, return false.

    if (ZSTR_LEN(pattern) == 0) {
        return false;
    }

    // Use php_pcre_match_impl() to match the subject against the pattern.
    // If the subject matches the pattern, return true.
    // If the subject does not match the pattern, return false.
    // If an error occurs, return false.

    zend_string *regex = zend_strpprintf(0, "(%s)", ZSTR_VAL(pattern));
    pcre_cache_entry *pce = pcre_get_compiled_regex_cache(regex);
    zval ret;
#if PHP_VERSION_ID < 70400
    php_pcre_match_impl(pce, ZSTR_VAL(subject), ZSTR_LEN(subject), &ret, NULL, 0, 0, 0, 0);
#else
    php_pcre_match_impl(pce, subject, &ret, NULL, 0, 0, 0, 0);
#endif
    zend_string_release(regex);
    return Z_TYPE(ret) == IS_LONG && Z_LVAL(ret) > 0;
}