#ifndef DD_COMPATIBILITY_H
#define DD_COMPATIBILITY_H

#include <stdbool.h>
#include <php.h>

#include "ext/standard/base64.h"

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

#if PHP_VERSION_ID < 80200
#define ZEND_ACC_READONLY_CLASS 0
#endif

#if PHP_VERSION_ID < 70200
#define DD_PARAM_PROLOGUE(deref, separate) Z_PARAM_PROLOGUE(deref)
#else
#define DD_PARAM_PROLOGUE Z_PARAM_PROLOGUE
#endif

#if PHP_VERSION_ID < 80000
#define ZVAL_OBJ_COPY(z, o) do { \
        zval *__z = (z); \
        zend_object *__o = (o); \
        GC_ADDREF(__o); \
        Z_OBJ_P(__z) = __o; \
        Z_TYPE_INFO_P(__z) = IS_OBJECT_EX; \
    } while (0)

#define RETVAL_COPY(zv) ZVAL_COPY(return_value, zv)
#define RETVAL_OBJ_COPY(r) ZVAL_OBJ_COPY(return_value, r)
#define RETURN_COPY(zv) do { RETVAL_COPY(zv); return; } while (0)
#define RETURN_OBJ_COPY(r) do { RETVAL_OBJ_COPY(r); return; } while (0)

ZEND_API const zend_function *zend_get_closure_method_def(zval *obj);
static inline const zend_function *dd_zend_get_closure_method_def(zend_object *obj) {
    zval zv;
    ZVAL_OBJ(&zv, obj);
    return zend_get_closure_method_def(&zv);
}
#define zend_get_closure_method_def dd_zend_get_closure_method_def

#define ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(pass_by_ref, name, classname, allow_null, default_value) ZEND_ARG_OBJ_INFO(pass_by_ref, name, classname, allow_null)
#define ZEND_ARG_OBJ_TYPE_MASK(pass_by_ref, name, class_name, type_mask, default_value) ZEND_ARG_INFO(pass_by_ref, name)
#define zend_declare_typed_property(ce, name, default, visibility, doc_comment, type) zend_declare_property_ex(ce, name, default, visibility, doc_comment); (void)type
#define ZEND_TYPE_INIT_MASK(type) NULL
#define ZEND_TYPE_INIT_CLASS(class_name, allow_null, extra_flags) NULL; zend_string_release(class_name)

#define ZEND_ARG_INFO_WITH_DEFAULT_VALUE(pass_by_ref, name, default_value) ZEND_ARG_INFO(pass_by_ref, name)
#define ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(name, return_reference, required_num_args, type) ZEND_BEGIN_ARG_INFO_EX(name, 0, return_reference, required_num_args)
#define ZEND_ARG_TYPE_MASK(pass_by_ref, name, type_mask, default_value) ZEND_ARG_INFO_WITH_DEFAULT_VALUE(pass_by_ref, name, default_value)
#define ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(pass_by_ref, name, type_hint, allow_null, default_value) ZEND_ARG_TYPE_INFO(pass_by_ref, name, type_hint, allow_null)
#define ZEND_BEGIN_ARG_WITH_RETURN_OBJ_TYPE_MASK_EX(name, return_reference, required_num_args, class_name, type) ZEND_BEGIN_ARG_INFO_EX(name, 0, return_reference, required_num_args)

#define IS_MIXED 0

static inline zend_string *get_function_or_method_name(const zend_function *func) {
    if (func->common.scope && func->common.function_name) {
        return strpprintf(0, "%s::%s", ZSTR_VAL(func->common.scope->name), ZSTR_VAL(func->common.function_name));
    }

    return func->common.function_name ? zend_string_copy(func->common.function_name) : zend_string_init("main", sizeof("main") - 1, 0);
}
static inline zend_string *get_active_function_or_method_name(void) {
    return get_function_or_method_name(EG(current_execute_data)->func);
}

#define zend_argument_type_error(arg_num, format, ...) do { \
        zend_string *func_name = get_active_function_or_method_name(); \
        zend_internal_type_error(ZEND_ARG_USES_STRICT_TYPES(), "%s(): Argument #%d " format, ZSTR_VAL(func_name), arg_num, ##__VA_ARGS__); \
        zend_string_release(func_name); \
    } while (0)

static zend_always_inline bool zend_parse_arg_obj(zval *arg, zend_object **dest, zend_class_entry *ce, bool check_null) {
    if (EXPECTED(Z_TYPE_P(arg) == IS_OBJECT) &&
        (!ce || EXPECTED(instanceof_function(Z_OBJCE_P(arg), ce) != 0))) {
        *dest = Z_OBJ_P(arg);
    } else if (check_null && EXPECTED(Z_TYPE_P(arg) == IS_NULL)) {
        *dest = NULL;
    } else {
        return 0;
    }
    return 1;
}

#if PHP_VERSION_ID < 70400
#define DD_PARAM_ERROR_CODE error_code
#else
#define DD_PARAM_ERROR_CODE _error_code
#endif

#define Z_PARAM_OBJ_EX2(dest, check_null, deref, separate) \
        DD_PARAM_PROLOGUE(deref, separate); \
        if (UNEXPECTED(!zend_parse_arg_obj(_arg, &dest, NULL, check_null))) { \
            _expected_type = Z_EXPECTED_OBJECT; \
            DD_PARAM_ERROR_CODE = ZPP_ERROR_WRONG_ARG; \
            break; \
        }

#define Z_PARAM_OBJ_EX(dest, check_null, separate) \
    Z_PARAM_OBJ_EX2(dest, check_null, separate, separate)

#define Z_PARAM_OBJ(dest) \
    Z_PARAM_OBJ_EX(dest, 0, 0)

#define Z_PARAM_OBJ_OR_NULL(dest) \
    Z_PARAM_OBJ_EX(dest, 1, 0)

#define Z_PARAM_OBJ_OF_CLASS_EX2(dest, _ce, check_null, deref, separate) \
        DD_PARAM_PROLOGUE(deref, separate); \
        if (UNEXPECTED(!zend_parse_arg_obj(_arg, &dest, _ce, check_null))) { \
            if (_ce) { \
                _error = ZSTR_VAL((_ce)->name); \
                DD_PARAM_ERROR_CODE = ZPP_ERROR_WRONG_CLASS; \
                break; \
            } else { \
                _expected_type = Z_EXPECTED_OBJECT; \
                DD_PARAM_ERROR_CODE = ZPP_ERROR_WRONG_ARG; \
                break; \
            } \
        }

#define Z_PARAM_OBJ_OF_CLASS_EX(dest, _ce, check_null, separate) \
    Z_PARAM_OBJ_OF_CLASS_EX2(dest, _ce, check_null, separate, separate)

#define Z_PARAM_OBJ_OF_CLASS(dest, _ce) \
    Z_PARAM_OBJ_OF_CLASS_EX(dest, _ce, 0, 0)

typedef ZEND_RESULT_CODE zend_result;
#endif

#if PHP_VERSION_ID < 70400
#define ZEND_THIS (&EX(This))

#define Z_PROP_FLAG_P(z) Z_EXTRA_P(z)
#endif

#if PHP_VERSION_ID < 70300
#define GC_ADDREF(x) (++GC_REFCOUNT(x))
#define GC_DELREF(x) (--GC_REFCOUNT(x))

#define GC_IMMUTABLE (1 << 5)
#define GC_ADD_FLAGS(c, flag) GC_FLAGS(c) |= flag
#define GC_DEL_FLAGS(c, flag) GC_FLAGS(c) &= ~(flag)

static inline HashTable *zend_new_array(uint32_t nSize) {
    HashTable *ht = (HashTable *)emalloc(sizeof(HashTable));
    zend_hash_init(ht, nSize, dummy, ZVAL_PTR_DTOR, 0);
    return ht;
}

#define DD_ZVAL_EMPTY_ARRAY(z) do {       \
        zval *__z = (z);                  \
        Z_ARR_P(__z) = zend_new_array(0); \
        Z_TYPE_INFO_P(__z) = IS_ARRAY;    \
    } while (0)
#define ZVAL_EMPTY_ARRAY DD_ZVAL_EMPTY_ARRAY

#define Z_IS_RECURSIVE_P(zv) (Z_OBJPROP_P(zv)->u.v.nApplyCount > 0)
#define Z_PROTECT_RECURSION_P(zv) (++Z_OBJPROP_P(zv)->u.v.nApplyCount)
#define Z_UNPROTECT_RECURSION_P(zv) (--Z_OBJPROP_P(zv)->u.v.nApplyCount)

#define ZEND_CLOSURE_OBJECT(op_array) \
    ((zend_object*)((char*)(op_array) - sizeof(zend_object)))

// make ZEND_STRL work
#undef zend_hash_str_update
#define zend_hash_str_update(...) _zend_hash_str_update(__VA_ARGS__ ZEND_FILE_LINE_CC)
#undef zend_hash_str_update_ind
#define zend_hash_str_update_ind(...) _zend_hash_str_update_ind(__VA_ARGS__ ZEND_FILE_LINE_CC)
#undef zend_hash_str_add
#define zend_hash_str_add(...) _zend_hash_str_add(__VA_ARGS__ ZEND_FILE_LINE_CC)
#undef zend_hash_str_add_new
#define zend_hash_str_add_new(...) _zend_hash_str_add_new(__VA_ARGS__ ZEND_FILE_LINE_CC)
#endif

#define ZVAL_VARARG_PARAM(list, arg_num) (&(((zval *)list)[arg_num]))
#define IS_TRUE_P(x) (Z_TYPE_P(x) == IS_TRUE)

#if PHP_VERSION_ID < 70200
#define ZEND_ACC_FAKE_CLOSURE ZEND_ACC_INTERFACE

#define zend_strpprintf strpprintf
#define zend_vstrpprintf vstrpprintf

static zend_always_inline zend_string *zend_string_init_interned(const char *str, size_t len, int persistent) {
    return zend_new_interned_string(zend_string_init(str, len, persistent));
}

#undef ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX
#define ZEND_ARG_VARIADIC_TYPE_INFO(pass_by_ref, name, type_hint, allow_null) ZEND_ARG_INFO(pass_by_ref, name)

#define ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(name, return_reference, required_num_args, type, allow_null) \
    static const zend_internal_arg_info name[] = { \
        { (const char*)(zend_uintptr_t)(required_num_args), NULL, (type) == IS_FALSE ? _IS_BOOL : (type), return_reference, allow_null, 0 },
#define ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(name, return_reference, required_num_args, class_name, allow_null) \
    static const zend_internal_arg_info name[] = { \
        { (const char*)(zend_uintptr_t)(required_num_args), #class_name, IS_OBJECT, return_reference, allow_null, 0 },

typedef void zend_type;

#include <Zend/zend_smart_str.h>
static inline void smart_str_append_printf(smart_str *dest, const char *format, ...) {
    va_list arg;
    va_start(arg, format);
    zend_string *str = vstrpprintf(0, format, arg);
    va_end(arg);
    smart_str_append(dest, str);
    zend_string_release(str);
}
#endif

#if PHP_VERSION_ID < 70100
#define IS_VOID 0
#define MAY_BE_NULL 0
#define MAY_BE_STRING 0
#define MAY_BE_ARRAY 0

#define Z_EXTRA_P(z) Z_NEXT_P(z)
#endif

#if PHP_VERSION_ID < 80100
#undef ZEND_ATOL
#ifdef ZEND_ENABLE_ZVAL_LONG64
#define ZEND_ATOL(s) atoll((s))
#else
#define ZEND_ATOL(s) atol((s))
#endif
#define ZEND_ACC_READONLY 0
#endif

#if PHP_VERSION_ID < 80200
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

#if PHP_VERSION_ID >= 80000
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

static zend_always_inline zend_ulong zend_object_to_weakref_key(const zend_object *object) { return (uintptr_t)object; }

static zend_always_inline zend_object *zend_weakref_key_to_object(zend_ulong key) {
    return (zend_object *)(uintptr_t)key;
}
#endif
#endif

#if PHP_VERSION_ID < 80300
static zend_always_inline zend_result zend_call_function_with_return_value(zend_fcall_info *fci, zend_fcall_info_cache *fci_cache, zval *retval) {
    fci->retval = retval;
    return zend_call_function(fci, fci_cache);
}

#define zend_zval_value_name zend_zval_type_name
#endif

#if PHP_VERSION_ID < 70200
static inline zend_string *php_base64_encode_str(const zend_string *str) {
    return php_base64_encode((const unsigned char*)(ZSTR_VAL(str)), ZSTR_LEN(str));
}
#endif

#endif  // DD_COMPATIBILITY_H
