#ifndef HAVE_ZAI_VALUE_H
#define HAVE_ZAI_VALUE_H

/*
 * The Value interface shall provide macros to abstract away the difference
 * between PHP5 and PHP7+ with respect to allocation (heap in 5, stack in 7+)
 */

#include <php.h>

#if PHP_VERSION_ID < 70000
// clang-format off
static zval zai_value_null = {
    .refcount__gc = 2,
    .type         = IS_NULL,
    .is_ref__gc   = 0,
};
// clang-format on
#endif

/* {{{ void ZAI_VALUE_MAKE(zval *zv) */
#if PHP_VERSION_ID < 70000
#define ZAI_VALUE_MAKE MAKE_STD_ZVAL
#else
#define ZAI_VALUE_MAKE(n) \
    zval _##n;            \
                          \
    n = &_##n;            \
                          \
    ZVAL_UNDEF(n)
#endif /* }}} */

/* {{{ void ZAI_VALUE_INIT(zval *result) */
#if PHP_VERSION_ID < 70000
#define ZAI_VALUE_INIT(n)    \
    do {                     \
        n = &zai_value_null; \
    } while (0)
#else
#define ZAI_VALUE_INIT(n) \
    zval _##n;            \
                          \
    n = &_##n;            \
                          \
    ZVAL_NULL(n)
#endif /* }}} */

/* {{{ void ZAI_VALUE_STRINGL(zval *dest, char *s, size_t|int len) */
#if PHP_VERSION_ID < 70000
#define ZAI_VALUE_STRINGL(d, s, l) \
    do {                           \
        ZVAL_STRINGL(d, s, l, 1);  \
    } while (0)
#else
#define ZAI_VALUE_STRINGL ZVAL_STRINGL
#endif /* }}} */

/* {{{ void ZAI_VALUE_COPY(zval *dest, zval *src) */
#if PHP_VERSION_ID < 70000
#define ZAI_VALUE_COPY(dest, src)   \
    do {                            \
        ZVAL_ZVAL(dest, src, 1, 0); \
    } while (0)
#else
#define ZAI_VALUE_COPY ZVAL_COPY
#endif /* }}} */

/* {{{ void ZAI_VALUE_DTOR(zval *zv) */
#if PHP_VERSION_ID < 70000
#define ZAI_VALUE_DTOR(n)  \
    do {                   \
        zval_ptr_dtor(&n); \
    } while (0)
#else
#define ZAI_VALUE_DTOR zval_ptr_dtor
#endif /* }}} */

#endif
