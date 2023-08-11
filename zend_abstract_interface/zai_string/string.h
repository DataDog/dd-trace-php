#ifndef ZAI_STRING_H
#define ZAI_STRING_H

#include <stdbool.h>
#include <stddef.h>
#include <string.h>

#include <Zend/zend.h>

/**
 * Represents a non-owning view of a string.
 *
 * When initializing the struct, use one of the initialization macros or
 * functions. Do not initialize the struct directly e.g. `{0, null}`.
 *
 * todo: move .ptr to come before .len to match ddtrace_string and Rust and
 *       ensure it's never null.
 */
typedef struct zai_string_view_s {
    size_t len;
    const char *ptr;
} zai_string_view;

/** Use if data is known to be non-null, use zai_string_view_new otherwise. */
#define ZAI_STR_NEW(data, size)   \
    (zai_string_view) {.len = (size), .ptr = (data)}

#define ZAI_STRL(literal) \
    ZAI_STR_NEW("" literal, sizeof(literal) - 1) \

#define ZAI_STRING_EMPTY \
    ZAI_STR_NEW("", 0)

/** Use if cstr is known to be non-null, use zai_str_from_cstr otherwise. */
#define ZAI_STR_FROM_CSTR(cstr)  \
    ZAI_STR_NEW((cstr), strlen(cstr))

/** Use if zstr is known to be non-null, use zai_str_from_zstr otherwise. */
#define ZAI_STRING_FROM_ZSTR(zstr)  \
    ZAI_STR_NEW(ZSTR_VAL(zstr), ZSTR_LEN(zstr))

/**
 * Creates a zai_string_view from the given pointer and length. If the pointer
 * is null, then ZAI_STRING_EMPTY will be returned.
 *
 * If the pointer is known to be non-null, use ZAI_STR_NEW directly.
 */
static inline zai_string_view zai_string_view_new(const char *ptr, size_t len) {
    return ptr ? ZAI_STR_NEW(ptr, len) : ZAI_STRING_EMPTY;
}

/**
 * Creates a zai_string_view from a possibly-null C-string. Returns
 * ZAI_STRING_EMPTY if the pointer is null.
 *
 * If the pointer is known to be non-null, use ZAI_STR_FROM_CSTR directly.
 */
static inline zai_string_view zai_str_from_cstr(const char *cstr) {
    return cstr ? ZAI_STR_FROM_CSTR(cstr) : ZAI_STRING_EMPTY;
}

/**
 * Creates a zai_string_view from a possibly-null zend_string. Returns
 * ZAI_STRING_EMPTY if the pointer is null.
 *
 * If the pointer is known to be non-null, use ZAI_STRING_FROM_ZSTR directly.
 */
static inline zai_string_view zai_str_from_zstr(zend_string *zstr) {
    return zstr ? ZAI_STRING_FROM_ZSTR(zstr) : ZAI_STRING_EMPTY;
}

/** Returns whether the string is empty. */
static inline bool zai_str_is_empty(zai_string_view self) {
    return self.len == 0 || self.ptr == NULL;
}

static inline bool zai_str_eq(zai_string_view a, zai_string_view b) {
    return a.len == b.len && (b.len == 0 || memcmp(a.ptr, b.ptr, b.len) == 0);
}

static inline bool zai_str_equals_ci_cstr(zai_string_view s, const char *str) {
    size_t len = strlen(str);
    return s.len == len && (len == 0 || strncasecmp(s.ptr, str, strlen(str)) == 0);
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

/** Returns true if the option holds a value. */
static inline bool zai_option_str_is_some(zai_option_str self) {
    return self.ptr != NULL;
}

/** Returns true if the option does not hold a value. */
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
