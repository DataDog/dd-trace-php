#ifndef ZAI_STRING_H
#define ZAI_STRING_H

// Include this before standard headers, or you may get strange errors like:
// error: unknown type name 'siginfo_t'
#include <Zend/zend.h>

#include <zai_assert/zai_assert.h>

#include <stdbool.h>
#include <stddef.h>
#include <string.h>

/**
 * Represents a non-owning view of a string.
 *
 * When initializing the struct, use one of the initialization macros or
 * functions. Do not initialize the struct directly e.g. `{"", 0}`.
 */
typedef struct zai_str_s {
    const char *ptr;
    size_t len;
} zai_str;

/** Private, you probably want to use ZAI_STR_NEW or zai_str_new. */
#define ZAI_STR_FROM_RAW_PARTS(data, size) \
    (zai_str) {.ptr = (data), .len = (size)}

/**
 * ZAI_STR_NEW creates a zai_str from the given pointer and length. Use if the
 * pointer is known to be non-null, use zai_str_new otherwise.
 */
#if ZEND_DEBUG
#define ZAI_STR_NEW(data, size) \
    ZAI_STR_FROM_RAW_PARTS( \
        ZAI_ASSERT((data) != NULL) ? (data) : NULL, \
        (size))
#else
#define ZAI_STR_NEW(data, size) \
    ZAI_STR_FROM_RAW_PARTS((data), (size))
#endif

#define ZAI_STRL(literal) \
    ZAI_STR_FROM_RAW_PARTS("" literal, sizeof(literal) - 1)

#define ZAI_STR_EMPTY \
    ZAI_STR_FROM_RAW_PARTS("", 0)

/** Use if cstr is known to be non-null, use zai_str_from_cstr otherwise. */
#define ZAI_STR_FROM_CSTR(cstr)  \
    ZAI_STR_NEW((cstr), strlen(cstr))

/** Use if zstr is known to be non-null, use zai_str_from_zstr otherwise. */
#define ZAI_STR_FROM_ZSTR(zstr)  \
    ZAI_STR_NEW(ZSTR_VAL(zstr), ZSTR_LEN(zstr))

/**
 * Creates a zai_str from the given pointer and length. If the pointer
 * is null, then ZAI_STR_EMPTY will be returned.
 *
 * If the pointer is known to be non-null, use ZAI_STR_NEW directly.
 */
static inline zai_str zai_str_new(const char *ptr, size_t len) {
    return ptr ? ZAI_STR_FROM_RAW_PARTS(ptr, len) : ZAI_STR_EMPTY;
}

/**
 * Creates a zai_str from a possibly-null C-string. Returns
 * ZAI_STR_EMPTY if the pointer is null.
 *
 * If the pointer is known to be non-null, use ZAI_STR_FROM_CSTR directly.
 */
static inline zai_str zai_str_from_cstr(const char *cstr) {
    return cstr ? ZAI_STR_FROM_CSTR(cstr) : ZAI_STR_EMPTY;
}

/**
 * Creates a zai_str from a possibly-null zend_string. Returns
 * ZAI_STR_EMPTY if the pointer is null.
 *
 * If the pointer is known to be non-null, use ZAI_STR_FROM_ZSTR directly.
 */
static inline zai_str zai_str_from_zstr(zend_string *zstr) {
    return zstr ? ZAI_STR_FROM_ZSTR(zstr) : ZAI_STR_EMPTY;
}

/** Returns whether the string is empty. */
static inline bool zai_str_is_empty(zai_str self) {
    ZEND_ASSERT(self.ptr != NULL);
    return self.len == 0;
}

static inline bool zai_str_eq(zai_str a, zai_str b) {
    ZEND_ASSERT(a.ptr != NULL);
    ZEND_ASSERT(b.ptr != NULL);
    return a.len == b.len && memcmp(a.ptr, b.ptr, b.len) == 0;
}

static inline bool zai_str_eq_ci_cstr(zai_str s, const char *str) {
    ZEND_ASSERT(s.ptr != NULL);
    ZEND_ASSERT(str != NULL);
    size_t len = strlen(str);
    return s.len == len && strncasecmp(s.ptr, str, len) == 0;
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
zai_option_str zai_option_str_from_str(zai_str str) {
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
 * Creates a zai_str from the option and assigns it to `view`. If the
 * option doesn't hold a value, then it will assign the empty string.
 *
 * Returns true if the option holds a value, false if it doesn't. This can be
 * used to distinguish between an empty option vs a non-empty option holding an
 * empty string.
 */
static inline
bool zai_option_str_get(zai_option_str self, zai_str *view) {
    // The .ptr may be null here, but if it is, then zai_option_str_is_some()
    // will return false, and the ill-formed zai_str won't be assigned.
    // Doing it in this order made slightly better assembly, and a ZEND_ASSERT
    // guards this on debug builds in case of a mistake.
    zai_str value = ZAI_STR_FROM_RAW_PARTS(self.ptr, self.len);
    *view = zai_option_str_is_some(self) ? value : ZAI_STR_EMPTY;
    ZEND_ASSERT(view->ptr != NULL);
    return self.ptr;
}

#endif  // ZAI_STRING_H
