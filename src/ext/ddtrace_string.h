#ifndef DDTRACE_STRING_H
#define DDTRACE_STRING_H

#include <php_version.h>
#include <stdbool.h>
#include <stddef.h>
#include <string.h>

#if PHP_VERSION_ID < 70000
typedef int ddtrace_zppstrlen_t;
#else
typedef size_t ddtrace_zppstrlen_t;
#endif

struct ddtrace_string {
    char *ptr;
    ddtrace_zppstrlen_t len;
};
typedef struct ddtrace_string ddtrace_string;

inline ddtrace_string ddtrace_string_cstring_ctor(char *ptr) {
    ddtrace_string string = {
        .ptr = ptr,
        .len = ptr ? strlen(ptr) : 0,
    };
    return string;
}

// this is the same set of character's that PHP's trim treats as whitespace
inline bool ddtrace_isspace(unsigned char c) {
    return (c <= ' ' && (c == ' ' || c == '\n' || c == '\r' || c == '\t' || c == '\v' || c == '\0'));
}

inline char *ddtrace_ltrim(char *restrict begin, char *restrict end) {
    while (begin != end && ddtrace_isspace((unsigned char)*(begin))) {
        ++begin;
    }
    return begin;
}

inline char *ddtrace_rtrim(char *restrict begin, char *restrict end) {
    while (end != begin && ddtrace_isspace((unsigned char)*(end - 1))) {
        --end;
    }
    return end;
}

// str.ptr must be non-null and src.len >= 0; does not copy!
inline ddtrace_string ddtrace_trim(ddtrace_string src) {
    char *begin = src.ptr;
    char *end = begin + src.len;
    begin = ddtrace_ltrim(begin, end);
    end = ddtrace_rtrim(begin, end);
    ddtrace_string result = {
        .ptr = begin,
        .len = end - begin,
    };
    return result;
}

inline bool ddtrace_string_equals(ddtrace_string a, ddtrace_string b) {
    return a.len == b.len && (a.ptr == b.ptr || !memcmp(a.ptr, b.ptr, a.len));
}

/* This function is _case sensitive_.
 * Only call if haystack and needle have len > 0.
 */
bool ddtrace_string_contains_in_csv(ddtrace_string haystack, ddtrace_string needle);

#endif  // DDTRACE_STRING_H
