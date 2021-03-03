#ifndef DD_COMPATIBILITY_H
#define DD_COMPATIBILITY_H

#include <TSRM/TSRM.h>
#include <Zend/zend.h>
#include <php_version.h>

#if !defined(ZEND_ASSERT)
#if ZEND_DEBUG
#include <assert.h>
#define ZEND_ASSERT(c) assert(c)
#else
// the void cast is there to avoid warnings about empty statements from linters
#define ZEND_ASSERT(c) ((void)0)
#endif
#endif

/* BC only */
#define TSRMLS_D void
#define TSRMLS_DC
#define TSRMLS_C
#define TSRMLS_CC
#define TSRMLS_FETCH()

#define UNUSED_1(x) (void)(x)
#define UNUSED_2(x, y) \
    do {               \
        UNUSED_1(x);   \
        UNUSED_1(y);   \
    } while (0)
#define UNUSED_3(x, y, z) \
    do {                  \
        UNUSED_1(x);      \
        UNUSED_1(y);      \
        UNUSED_1(z);      \
    } while (0)
#define UNUSED_4(x, y, z, q) \
    do {                     \
        UNUSED_1(x);         \
        UNUSED_1(y);         \
        UNUSED_1(z);         \
        UNUSED_1(q);         \
    } while (0)
#define UNUSED_5(x, y, z, q, w) \
    do {                        \
        UNUSED_1(x);            \
        UNUSED_1(y);            \
        UNUSED_1(z);            \
        UNUSED_1(q);            \
        UNUSED_1(w);            \
    } while (0)
#define _GET_UNUSED_MACRO_OF_ARITY(_1, _2, _3, _4, _5, ARITY, ...) UNUSED_##ARITY
#define UNUSED(...) _GET_UNUSED_MACRO_OF_ARITY(__VA_ARGS__, 5, 4, 3, 2, 1)(__VA_ARGS__)

#define PHP5_UNUSED(...) /* unused unused */
#define PHP7_UNUSED(...) UNUSED(__VA_ARGS__)

#define COMPAT_RETVAL_STRING(c) RETVAL_STRING(c)
#define ZVAL_VARARG_PARAM(list, arg_num) (&(((zval*)list)[arg_num]))
#define IS_TRUE_P(x) (Z_TYPE_P(x) == IS_TRUE)

typedef zend_object ddtrace_exception_t;

#endif  // DD_COMPATIBILITY_H
