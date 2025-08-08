#include <components-rs/ddtrace.h>
#include <Zend/zend_API.h>
#include <components/log/log.h>

#include "ddshared.h"
#include "configuration.h"
#include "ddtrace.h"
#include "uri_normalization.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddshared_minit(void) {
    ddtrace_set_container_cgroup_path((ddog_CharSlice){ .ptr = DDTRACE_G(cgroup_file), .len = strlen(DDTRACE_G(cgroup_file)) });
}

bool dd_glob_rule_is_wildcards_only(zval *pattern) {
    if (Z_TYPE_P(pattern) != IS_STRING || Z_STRLEN_P(pattern) == 0) {
        return false;
    }
    char *p = Z_STRVAL_P(pattern);
    while (*p == '*') {
        ++p;
    }
    return *p == 0;
}

bool dd_rule_matches(zval *pattern, zval *prop, int rulesFormat) {
    if (Z_TYPE_P(pattern) != IS_STRING) {
        return false;
    }
    zend_string *str;
    if (Z_TYPE_P(prop) == IS_STRING) {
        str = zend_string_copy(Z_STR_P(prop));
    } else {
        if (Z_TYPE_P(prop) == IS_TRUE) {
#if PHP_VERSION_ID < 80200
            str = zend_string_init("true", 4, 0);
#else
            str = ZSTR_KNOWN(ZEND_STR_TRUE);
#endif
        } else if (Z_TYPE_P(prop) == IS_FALSE) {
#if PHP_VERSION_ID < 80200
            str = zend_string_init("false", 5, 0);
#else
            str = ZSTR_KNOWN(ZEND_STR_FALSE);
#endif
        } else if (Z_TYPE_P(prop) == IS_LONG) {
            str = zend_long_to_str(Z_LVAL_P(prop));
        } else if (Z_TYPE_P(prop) == IS_DOUBLE) {
            zend_long to_long = zend_dval_to_lval(Z_DVAL_P(prop));
            if (Z_DVAL_P(prop) == (double)to_long) {
                str = zend_long_to_str(to_long);
            } else {
                return dd_glob_rule_is_wildcards_only(pattern);
            }
        } else {
            return Z_STRLEN_P(pattern) == 0 || dd_glob_rule_is_wildcards_only(pattern);
        }
    }

    bool result;
    if (rulesFormat == DD_TRACE_SAMPLING_RULES_FORMAT_GLOB) {
        result = dd_glob_rule_matches(pattern, str);
    } else {
        result = zai_match_regex(Z_STR_P(pattern), str);
    }
    zend_string_release(str);
    return result;
}

bool dd_glob_rule_matches(zval *pattern, zend_string *value) {
    if (Z_TYPE_P(pattern) != IS_STRING) {
        return false;
    }

    char *p = Z_STRVAL_P(pattern);
    char *s = ZSTR_VAL(value);

    int wildcards = 0;
    while (*p) {
        if (*(p++) == '*') {
            ++wildcards;
        }
    }

    // If there are no wildcards, no need to go through the whole string if pattern is shorter than the input string
    // Indeed wildcards (i.e. '*') can replace multiple characters while '?' can only replace one
    if (wildcards == 0 && Z_STRLEN_P(pattern) < ZSTR_LEN(value)) {
        return false;
    }

    p = Z_STRVAL_P(pattern);

    ALLOCA_FLAG(use_heap)
    char **backtrack_points = do_alloca(wildcards * 2 * sizeof(char *), use_heap);
    int backtrack_idx = 0;

    while (*p) {
        if (!*s) {
            while (*p == '*') {
                ++p;
            }
            free_alloca(backtrack_points, use_heap);
            return !*p;
        }
        // equal or case-insensitive match
        if (*s == *p || *p == '?' || ((*s | ' ') == (*p | ' ') && (*p | ' ') >= 'a' && (*p | ' ') <= 'z')) {
            ++s, ++p;
        } else if (*p == '*') {
            do {
                ++p;
            } while (*p == '*');
            backtrack_points[backtrack_idx++] = p;
            backtrack_points[backtrack_idx++] = s;
        } else {
            do {
                if (backtrack_idx > 0) {
                    backtrack_idx -= 2;
                    p = backtrack_points[backtrack_idx];
                    s = ++backtrack_points[backtrack_idx + 1];
                } else {
                    free_alloca(backtrack_points, use_heap);
                    return false;
                }
            } while (!*s);
            backtrack_idx += 2;
        }
    }

    free_alloca(backtrack_points, use_heap);

    return true;
}
