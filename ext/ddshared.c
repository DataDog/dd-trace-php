#include <components-rs/ddtrace.h>
#include <Zend/zend_API.h>
#include <components/log/log.h>

#include "ddshared.h"
#include "configuration.h"
#include "ddtrace.h"
#include "uri_normalization.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

zend_string *ddtrace_php_version;

void ddshared_minit(void) {
    ddtrace_set_container_cgroup_path((ddog_CharSlice){ .ptr = DDTRACE_G(cgroup_file), .len = strlen(DDTRACE_G(cgroup_file)) });

    ddtrace_php_version = Z_STR_P(zend_get_constant_str(ZEND_STRL("PHP_VERSION")));
}

 bool dd_rule_matches(zval *pattern, zval *prop, int rulesFormat) {
    if (Z_TYPE_P(pattern) != IS_STRING) {
        return false;
    }
    if (Z_TYPE_P(prop) != IS_STRING) {
        return true;  // default case unset or null must be true, everything else is too then...
    }

    if (rulesFormat == DD_TRACE_SAMPLING_RULES_FORMAT_GLOB) {
        return dd_glob_rule_matches(pattern, Z_STR_P(prop));
    }
    else {
        return zai_match_regex(Z_STR_P(pattern), Z_STR_P(prop));
    }
}

bool dd_glob_rule_matches(zval *pattern, zend_string* value) {
    if (Z_TYPE_P(pattern) != IS_STRING) {
        return false;
    }

    char *p = Z_STRVAL_P(pattern);
    char *s = ZSTR_VAL(value);

    int wildcards = 0;
    int patternLength = 0;
    int stringLength = ZSTR_LEN(value);
    while (*p) {
        if (*(p++) == '*') {
            ++wildcards;
        }
        patternLength++;
    }

    // If there are no wildcards, no need to go through the whole string if pattern is shorter than the input string
    // Indeed wildcards (ie '*') can replace multiple characters while '?' canonly replace one
    if (wildcards == 0 && patternLength < stringLength) {
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
        if (*s == *p || *p == '?') {
            ++s, ++p;
        } else if (*p == '*') {
            backtrack_points[backtrack_idx++] = ++p;
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
