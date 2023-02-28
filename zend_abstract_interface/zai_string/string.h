#ifndef ZAI_STRING_H
#define ZAI_STRING_H

#include <stdbool.h>
#include <stddef.h>
#include <string.h>

typedef struct zai_string_view_s {
    size_t len;
    const char *ptr;
} zai_string_view;

#define ZAI_STRL_VIEW(cstr) \
    (zai_string_view) { .len = sizeof(cstr) - 1, .ptr = (cstr) }

#define ZAI_STRING_EMPTY \
    (zai_string_view) { .len = 0, .ptr = "" }

#define ZAI_STRING_FROM_ZSTR(str) \
    (zai_string_view) { .len = ZSTR_LEN(str), .ptr = ZSTR_VAL(str) }

static inline bool zai_string_stuffed(zai_string_view s) { return s.ptr && s.len; }

static inline bool zai_string_equals_literal(zai_string_view s, const char *str) {
    return s.len == strlen(str) && (strlen(str) == 0 || strncmp(s.ptr, str, strlen(str)) == 0);
}

static inline bool zai_string_equals_literal_ci(zai_string_view s, const char *str) {
    return s.len == strlen(str) && (strlen(str) == 0 || strncasecmp(s.ptr, str, strlen(str)) == 0);
}

#endif  // ZAI_STRING_H
