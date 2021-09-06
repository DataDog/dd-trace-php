#ifndef ZAI_STRING_H
#define ZAI_STRING_H

#include <stdbool.h>

typedef struct zai_string_view_s {
    size_t len;
    const char *ptr;
} zai_string_view;

#define ZAI_STRL_VIEW(cstr) \
    (zai_string_view) { .len = sizeof(cstr) - 1, .ptr = (cstr) }

#define ZAI_STRING_EMPTY \
    (zai_string_view) { .len = 0, .ptr = "" }

static inline bool zai_string_stuffed(zai_string_view s) { return s.ptr && s.len; }

#endif  // ZAI_STRING_H
