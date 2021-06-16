#ifndef ZAI_STRING_H
#define ZAI_STRING_H

typedef struct zai_string_view_s {
    size_t len;
    const char *ptr;
} zai_string_view;

#define ZAI_STRL_VIEW(cstr) \
    (zai_string_view) { .len = sizeof(cstr) - 1, .ptr = (cstr) }

#endif  // ZAI_STRING_H
