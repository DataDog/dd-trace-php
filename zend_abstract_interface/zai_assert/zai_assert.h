#ifndef ZAI_ASSERT_H
#define ZAI_ASSERT_H

#include <main/php.h>
#include <Zend/zend_portability.h>

#ifndef NDEBUG
#include <assert.h>
#include <ctype.h>
#include <stdbool.h>

#define zai_assert_is_lower(str, message)      \
    do {                                       \
        char *p = (char *)str;                 \
        while (*p) {                           \
            if (isalpha(*p) && !islower(*p)) { \
                assert(false && message);      \
            }                                  \
            p++;                               \
        }                                      \
    } while (0)

#define zai_assert_is_upper(str, message)      \
    do {                                       \
        char *p = (char *)str;                 \
        while (*p) {                           \
            if (isalpha(*p) && !isupper(*p)) { \
                assert(false && message);      \
            }                                  \
            p++;                               \
        }                                      \
    } while (0)
#else
#define zai_assert_is_lower(str, message)
#define zai_assert_is_upper(str, message)
#endif

// __has_builtin will get defined by zend_portability.h if it doesn't exist.
#if __has_builtin(__builtin_assume)
// At the time of writing, this is clang-only.
#define ZAI_ASSUME(cond) __builtin_assume(cond)
#elif defined(__GCC__)
// GCC has had these builtins a long, long time. Not guarding them.
#define ZAI_ASSUME(cond) \
    (__builtin_expect(!(cond), 0) ? __builtin_unreachable() : true)
#else
#define ZAI_ASSUME(cond) true
#endif

/**
 * ZAI_ASSERT is like ZEND_ASSERT and C assert that it will expand into a valid
 * expression which returns true (if it fails, it will not return at all).
 */
#if ZEND_DEBUG
#define ZAI_ASSERT(cond) (assert(cond), true)
#else
#define ZAI_ASSERT(cond) (ZAI_ASSUME(cond), true)
#endif

#endif  // ZAI_ASSERT_H
