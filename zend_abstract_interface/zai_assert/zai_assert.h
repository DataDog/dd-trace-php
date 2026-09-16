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
#elif defined(__GNUC__)
// GCC has had these builtins a long, long time. Not guarding them.
// Both branches must be void: in C++ (unlike C) the conditional operator
// requires operand types to unify, and __builtin_unreachable() is void.
#define ZAI_ASSUME(cond) \
    (__builtin_expect(!(cond), 0) ? (void)__builtin_unreachable() : (void)0)
#else
#define ZAI_ASSUME(cond) true
#endif

#if ZEND_DEBUG
#define ZAI_ASSERT_IMPL(cond) assert(cond)
#else
#define ZAI_ASSERT_IMPL(cond) ZAI_ASSUME(cond)
#endif

/**
 * ZAI_ASSERT is like ZEND_ASSERT and C assert that it will expand into a valid
 * expression which returns true (if it fails, it will not return at all).
 *
 * Prevent -Wunused-value on GCC/clang, but use the comma operator on MSVC which doesn't support __extension__.
 */
#if defined(__GNUC__)
#define ZAI_ASSERT(cond) (__extension__({ ZAI_ASSERT_IMPL(cond); true; }))
#else
#define ZAI_ASSERT(cond) (ZAI_ASSERT_IMPL(cond), true)
#endif

#endif  // ZAI_ASSERT_H
