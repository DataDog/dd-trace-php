// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include <php.h>

// target the definitions of PHP 7.4

#if PHP_VERSION_ID < 70100
// for arginfo purposes
#    define IS_VOID IS_UNDEF
#    define HT_IS_PACKED(ht) 0
#    define HT_IS_WITHOUT_HOLES(ht) 0

#define MAY_BE_NULL 0
#define MAY_BE_STRING 0
#define MAY_BE_ARRAY 0
#endif

#if PHP_VERSION_ID < 70200
#    undef ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX
#    define ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(                           \
        name, return_reference, required_num_args, type, allow_null)           \
        static const zend_internal_arg_info name[] = {                         \
            {(const char *)(zend_uintptr_t)(required_num_args), NULL, type,    \
                return_reference, allow_null, 0},

// persistent must be true iif we're on the startup phase
static zend_always_inline zend_string *zend_string_init_interned(
    const char *str, size_t len, int persistent)
{
    zend_string *ret = zend_string_init(str, len, persistent);
#    ifdef ZTS
    // believe it or not zend_new_interned_string() is an identity function
    // set the interned flag manually so zend_string_release() is a no-op
    GC_FLAGS(ret) |= IS_STR_INTERNED;
    zend_string_hash_val(ret);
    return ret;
#    else
    return zend_new_interned_string(ret);
#    endif
}
extern zend_string *zend_empty_string;
#endif

#if PHP_VERSION_ID < 70300
extern const HashTable zend_empty_array;

#    define GC_ADDREF(x) (++GC_REFCOUNT(x))
#    define GC_DELREF(x) (--GC_REFCOUNT(x))
static zend_always_inline void _gc_try_addref(zend_refcounted_h *_rc)
{
    struct _zend_refcounted *rc = (struct _zend_refcounted *)_rc;
    if (!(GC_FLAGS(rc) & IS_ARRAY_IMMUTABLE)) {
        GC_ADDREF(rc);
    }
}
#    define GC_TRY_ADDREF(p) _gc_try_addref(&(p)->gc)
static zend_always_inline void _gc_try_delref(zend_refcounted_h *_rc)
{
    struct _zend_refcounted *rc = (struct _zend_refcounted *)_rc;
    if (!(GC_FLAGS(rc) & IS_ARRAY_IMMUTABLE)) {
        GC_DELREF(rc);
    }
}
#    define GC_TRY_DELREF(p) _gc_try_delref(&(p)->gc)

zend_bool zend_ini_parse_bool(zend_string *str);
#   define zend_string_efree zend_string_free

static inline HashTable *zend_new_array(uint32_t nSize) {
    HashTable *ht = (HashTable *)emalloc(sizeof(HashTable));
    zend_hash_init(ht, nSize, dummy, ZVAL_PTR_DTOR, 0);
    return ht;
}
#endif

#if PHP_VERSION_ID < 70400
#    define tsrm_env_lock()
#    define tsrm_env_unlock()
#endif

#if PHP_VERSION_ID < 80000
#define ZEND_ARG_TYPE_MASK(pass_by_ref, name, type_mask, default_value) ZEND_ARG_INFO_WITH_DEFAULT_VALUE(pass_by_ref, name, default_value)
#define ZEND_ARG_INFO_WITH_DEFAULT_VALUE(pass_by_ref, name, default_value) ZEND_ARG_INFO(pass_by_ref, name)
#define IS_MIXED 0
#endif

#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 80000
static zend_always_inline void _gc_try_addref(zend_refcounted_h *rc)
{
    if (!(rc->u.type_info & GC_IMMUTABLE)) {
        rc->refcount++;
    }
}
#define GC_TRY_ADDREF(p) _gc_try_addref(&(p)->gc)
#endif
#if PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 80100
static zend_always_inline void _gc_try_delref(zend_refcounted_h *rc)
{
    if (!(rc->u.type_info & GC_IMMUTABLE)) {
        rc->refcount--;
    }
}
#define GC_TRY_DELREF(p) _gc_try_delref(&(p)->gc)
#endif
