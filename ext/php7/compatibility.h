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

#if PHP_VERSION_ID < 70300
#define GC_ADDREF(x) (++GC_REFCOUNT(x))
#define GC_DELREF(x) (--GC_REFCOUNT(x))

static inline HashTable *zend_new_array(uint32_t nSize) {
    HashTable *ht = (HashTable *)emalloc(sizeof(HashTable));
    zend_hash_init(ht, nSize, dummy, ZVAL_PTR_DTOR, 0);
    return ht;
}

#define Z_IS_RECURSIVE_P(zv) (Z_OBJPROP_P(zv)->u.v.nApplyCount > 0)
#define Z_PROTECT_RECURSION_P(zv) (++Z_OBJPROP_P(zv)->u.v.nApplyCount)
#define Z_UNPROTECT_RECURSION_P(zv) (--Z_OBJPROP_P(zv)->u.v.nApplyCount)
#endif

#define ZVAL_VARARG_PARAM(list, arg_num) (&(((zval *)list)[arg_num]))
#define IS_TRUE_P(x) (Z_TYPE_P(x) == IS_TRUE)

#if PHP_VERSION_ID < 70200
#define zend_strpprintf strpprintf
#define zend_vstrpprintf vstrpprintf

static zend_always_inline zend_string *zend_string_init_interned(const char *str, size_t len, int persistent) {
    return zend_new_interned_string(zend_string_init(str, len, persistent));
}
#endif

#endif  // DD_COMPATIBILITY_H
