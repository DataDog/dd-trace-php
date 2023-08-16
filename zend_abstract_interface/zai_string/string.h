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

/** Represents an optional string view. Please treat this as opaque. */
typedef struct zai_option_str_s {
    /* If null, this is a None. */
    const char *ptr;

    /* If ptr is null, this must be 0, use ZAI_OPTION_STR_NONE and
     * zai_option_str_from_raw_parts to help manage it.
     */
    size_t len;
} zai_option_str;

/** Creates a zai_option_str which is empty. */
#define ZAI_OPTION_STR_NONE \
    (zai_option_str) {.ptr = NULL, .len = 0}

/**
 * Creates a zai_option_str from the given `ptr` and `len`. If `ptr` is null,
 * then it will be a None.
 */
static inline
zai_option_str zai_option_str_from_raw_parts(const char *ptr, size_t len) {
    zai_option_str value = {.ptr = ptr, .len = len};
    return ptr ? value : ZAI_OPTION_STR_NONE;
}

/**
 * Creates a zai_option_str from the given `str`. The option always holds a
 * value in this case.
 */
static inline
zai_option_str zai_option_str_from_str(zai_string_view str) {
    return (zai_option_str) {.ptr = str.len ? str.ptr : "", .len = str.len};
}

/** Returns true of the option holds a value. */
static inline bool zai_option_str_is_some(zai_option_str self) {
    return self.ptr != NULL;
}

/** Returns true of the option does not hold a value. */
static inline bool zai_option_str_is_none(zai_option_str self) {
    return self.ptr == NULL;
}

/**
 * Creates a zai_string_view from the option and assigns it to `view`. If the
 * option doesn't hold a value, then it will assign the empty string.
 *
 * Returns true if the option holds a value, false if it doesn't. This can be
 * used to distinguish between an empty option vs a non-empty option holding an
 * empty string.
 */
static inline
bool zai_option_str_get(zai_option_str self, zai_string_view *view) {
    zai_string_view value = {.len = self.len, .ptr = self.ptr};
    *view = zai_option_str_is_some(self) ? value : ZAI_STRING_EMPTY;
    return self.ptr;
}

#endif  // ZAI_STRING_H
