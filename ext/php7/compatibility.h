#ifndef DD_COMPATIBILITY_H
#define DD_COMPATIBILITY_H

#include <stdbool.h>
#include <php.h>

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

#ifdef ZEND_API_H
#if PHP_VERSION_ID < 70200
#undef ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX
#define ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(name, return_reference, required_num_args, type, allow_null) \
    static const zend_internal_arg_info name[] = { \
        { (const char*)(zend_uintptr_t)(required_num_args), NULL, type, return_reference, allow_null, 0 },
#define ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(name, return_reference, required_num_args, class_name, allow_null) \
    static const zend_internal_arg_info name[] = { \
        { (const char*)(zend_uintptr_t)(required_num_args), class_name, IS_OBJECT, return_reference, allow_null, 0 },

typedef void zend_type;
#endif

#if PHP_VERSION_ID < 70100
#define IS_VOID 0
#endif
#endif

#define ZEND_ARG_OBJ_TYPE_MASK(pass_by_ref, name, class_name, type_mask, default_value) ZEND_ARG_INFO(pass_by_ref, name)
#define zend_declare_typed_property(ce, name, default, visibility, doc_comment, type) zend_declare_property_ex(ce, name, default, visibility, doc_comment); (void)type
#define ZEND_TYPE_INIT_MASK(type) NULL
#define ZEND_TYPE_INIT_CLASS(class_name, allow_null, extra_flags) NULL; zend_string_release(class_name)

#define ZVAL_OBJ_COPY(z, o) do { zend_object *__o = (o); GC_ADDREF(__o); ZVAL_OBJ(z, __o); } while (0)

static inline zend_string *ddtrace_vstrpprintf(size_t max_len, const char *format, va_list ap)
{
    zend_string *str = zend_vstrpprintf(max_len, format, ap);
    return zend_string_realloc(str, ZSTR_LEN(str), 0);
}

#undef zend_vstrpprintf
#define zend_vstrpprintf ddtrace_vstrpprintf

static inline zend_string *ddtrace_strpprintf(size_t max_len, const char *format, ...)
{
    va_list arg;
    zend_string *str;

    va_start(arg, format);
    str = zend_vstrpprintf(max_len, format, arg);
    va_end(arg);
    return str;
}

#undef zend_strpprintf
#define zend_strpprintf ddtrace_strpprintf

#endif  // DD_COMPATIBILITY_H
