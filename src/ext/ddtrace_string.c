#include "ddtrace_string.h"

#include <Zend/zend_operators.h>

extern inline ddtrace_string ddtrace_string_cstring_ctor(char *ptr);
extern inline bool ddtrace_isspace(unsigned char c);
extern inline char *ddtrace_ltrim(char *restrict begin, char *restrict end);
extern inline char *ddtrace_rtrim(char *restrict begin, char *restrict end);
extern inline ddtrace_string ddtrace_trim(ddtrace_string src);
extern inline bool ddtrace_string_equals(ddtrace_string a, ddtrace_string b);

/* Annoyingly, zend_memnstr changed from char* to const char* in PHP 5.6, but
 * not for `end`! That happened in 7.
 * We aren't modifying anything, so we'll use const and just cast inside.
 */
static const char *_dd_memnstr(const char *haystack, const char *needle, int needle_len, const char *end) {
#if PHP_VERSION_ID < 50600
    return zend_memnstr((char *)haystack, (char *)needle, needle_len, (char *)end);
#elif PHP_VERSION_ID < 70000
    return zend_memnstr((const char *)haystack, (const char *)needle, needle_len, (char *)end);
#else
    return zend_memnstr((const char *)haystack, (const char *)needle, needle_len, (const char *)end);
#endif
}

bool ddtrace_string_contains_in_csv(ddtrace_string haystack, ddtrace_string needle) {
    const char *match, *begin, *end;
    begin = haystack.ptr;
    end = begin + haystack.len;
    while ((match = _dd_memnstr(begin, needle.ptr, needle.len, end))) {
        const char *match_end = match + needle.len;
        if ((match == begin || *(match - 1) == ',') && (match_end == end || *match_end == ',')) {
            return true;
        }
        begin = match + 1;
    }
    return false;
}
