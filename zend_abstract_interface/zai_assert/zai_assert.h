#ifndef ZAI_ASSERT_H
#define ZAI_ASSERT_H

#include <main/php.h>

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

#endif  // ZAI_ASSERT_H
