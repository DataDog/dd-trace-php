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
        UNUSED_2(x, y);      \
        UNUSED_1(z);      \
    } while (0)
#define UNUSED_4(x, y, z, q) \
    do {                     \
        UNUSED_3(x, y, z);         \
        UNUSED_1(q);         \
    } while (0)
#define UNUSED_5(x, y, z, q, w) \
    do {                        \
        UNUSED_4(x, y, z, q);            \
        UNUSED_1(w);            \
    } while (0)
#define UNUSED_6(x, y, z, q, w, v) \
    do {                        \
        UNUSED_5(x, y, z, q, w);            \
        UNUSED_1(v);            \
    } while (0)
#define UNUSED_7(x, y, z, q, w, v, u) \
    do {                        \
        UNUSED_6(x, y, z, q, w, v);            \
        UNUSED_1(u);            \
    } while (0)
#define UNUSED_8(x, y, z, q, w, v, u, t) \
    do {                        \
        UNUSED_7(x, y, z, q, w, v, u);            \
        UNUSED_1(t);            \
    } while (0)
#define _GET_UNUSED_MACRO_OF_ARITY(_1, _2, _3, _4, _5, _6, _7, _8, ARITY, ...) UNUSED_##ARITY
#define UNUSED(...) _GET_UNUSED_MACRO_OF_ARITY(__VA_ARGS__, 8, 7, 6, 5, 4, 3, 2, 1)(__VA_ARGS__)

#define COMPAT_RETVAL_STRING(c) RETVAL_STRING(c)
#define ZVAL_VARARG_PARAM(list, arg_num) (&(((zval *)list)[arg_num]))
#define IS_TRUE_P(x) (Z_TYPE_P(x) == IS_TRUE)

#define zend_weakrefs_hash_add zend_weakrefs_hash_add_fallback
#define zend_weakrefs_hash_del zend_weakrefs_hash_del_fallback
#define zend_weakrefs_hash_add_ptr zend_weakrefs_hash_add_ptr_fallback

zval *zend_weakrefs_hash_add(HashTable *ht, zend_object *key, zval *pData);
zend_result zend_weakrefs_hash_del(HashTable *ht, zend_object *key);

static zend_always_inline void *zend_weakrefs_hash_add_ptr(HashTable *ht, zend_object *key, void *ptr) {
    zval tmp, *zv;
    ZVAL_PTR(&tmp, ptr);
    if ((zv = zend_weakrefs_hash_add(ht, key, &tmp))) {
        return Z_PTR_P(zv);
    } else {
        return NULL;
    }
}

#endif  // DD_COMPATIBILITY_H
