#ifndef ZAI_STRING_H
#define ZAI_STRING_H

// Include this before standard headers, or you may get strange errors like:
// error: unknown type name 'siginfo_t'
#include <Zend/zend.h>

#include <zai_assert/zai_assert.h>

#include <stdbool.h>
#include <stddef.h>
#include <string.h>

extern char *ZAI_STRING_EMPTY_PTR;

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
    {.ptr = (data), .len = (size)}

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
    ZAI_STR_FROM_RAW_PARTS(ZAI_STRING_EMPTY_PTR, 0)

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
    if (ptr) {
        return (zai_str)ZAI_STR_FROM_RAW_PARTS(ptr, len);
    }
    return (zai_str)ZAI_STR_EMPTY;
}

/**
 * Creates a zai_str from a possibly-null C-string. Returns
 * ZAI_STR_EMPTY if the pointer is null.
 *
 * If the pointer is known to be non-null, use ZAI_STR_FROM_CSTR directly.
 */
inline zai_str zai_str_from_cstr(const char *cstr) {
    if (cstr) {
        return (zai_str)ZAI_STR_FROM_CSTR(cstr);
    }
    return (zai_str)ZAI_STR_EMPTY;
}

/**
 * Creates a zai_str from a possibly-null zend_string. Returns
 * ZAI_STR_EMPTY if the pointer is null.
 *
 * If the pointer is known to be non-null, use ZAI_STR_FROM_ZSTR directly.
 */
inline zai_str zai_str_from_zstr(zend_string *zstr) {
    if (zstr) {
        return (zai_str)ZAI_STR_FROM_ZSTR(zstr);
    }
    return (zai_str)ZAI_STR_EMPTY;
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
    return s.len == len && zend_binary_strncasecmp(s.ptr, s.len, str, len, len) == 0;
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
    return (zai_option_str) {
        .ptr = str.len ? str.ptr : ZAI_STRING_EMPTY_PTR,
        .len = str.len,
    };
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
    *view = zai_option_str_is_some(self) ? value : (zai_str)ZAI_STR_EMPTY;
    ZEND_ASSERT(view->ptr != NULL);
    return self.ptr;
}

/**
 * Represents a owned string.
 *
 * When initializing the struct, use one of the initialization macros or
 * functions. Do not initialize the struct directly.
 *
 * It is currently guaranteed that zai_string has the same layout as zai_str.
 * This makes it safe to memcpy a zai_string into a zai_str. However, you
 * shouldn't re-interpret a zai_string as a zai_str, because you are likely to
 * commit an aliasing violation.
 */
typedef struct zai_string_s {
    /**
     * Guaranteed to hold .len + 1 bytes. The byte at self.ptr[self.len] is
     * guaranteed to be a null character, but after initialization, this last
     * byte must only be read, never written.
     */
    char *ptr;
    size_t len;
} zai_string;

#define ZAI_STRING_EMPTY \
    {.ptr = ZAI_STRING_EMPTY_PTR, .len = 0}

/**
 * Creates a zai_str view of the zai_string. Make sure the zai_str does not
 * outlive the zai_string.
 */
#ifndef _WIN32
__attribute__((pure))
#endif
static inline zai_str zai_string_as_str(const zai_string *string) {
    zai_str str;
    memcpy(&str, string, sizeof(zai_str));
    return str;
}

static inline zai_string zai_string_from_str(zai_str str) {
    if (str.len == 0) {
        return (zai_string)ZAI_STRING_EMPTY;
    }

    // plus 1 for the null byte
    char *bytes = (char *)pemalloc(str.len + 1, true);
    memcpy(bytes, str.ptr, str.len);
    bytes[str.len] = '\0';
    return (zai_string) {.ptr = bytes, .len = str.len};
}

inline zai_string zai_string_concat3(zai_str first, zai_str second, zai_str third) {
    size_t len = first.len + second.len + third.len;

    if (len == 0) {
        return (zai_string)ZAI_STRING_EMPTY;
    }

    // plus 1 for the null byte
    char *bytes = (char *)pemalloc(len + 1, true);

    memcpy(bytes, first.ptr, first.len);
    memcpy(bytes + first.len, second.ptr, second.len);
    memcpy(bytes + first.len + second.len, third.ptr, third.len);

    bytes[len] = '\0';
    return (zai_string) {.ptr = bytes, .len = len};
}

/**
 * Destroys the contents of the string. It is considered to be uninitialized
 * after this call.
 */
static inline void zai_string_destroy(zai_string *string) {
    if (EXPECTED(string->ptr != ZAI_STRING_EMPTY_PTR)) {
        pefree(string->ptr, true);
        // Because this project runs asan builds, we don't re-assign the
        // pointer to ZAI_STRING_EMPTY_PTR or similar because we want the
        // analyzer to complain if this gets used in some way after free.
    }
}

#endif  // ZAI_STRING_H
