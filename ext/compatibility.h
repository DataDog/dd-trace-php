#ifndef DD_COMPATIBILITY_H
#define DD_COMPATIBILITY_H

#include <stdbool.h>
#include <php.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_smart_str.h>

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

#if defined(__has_attribute) && __has_attribute(unused)
#define ATTR_UNUSED __attribute((unused))
#define UNUSED_1(x)                             \
    do {                                        \
        ATTR_UNUSED __auto_type _ignored = (x); \
    } while (0)
#else
#define ATTR_UNUSED
#define UNUSED_1(x) \
    do {            \
        (void)(x);  \
    } while (0)
#endif
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

#define ZVAL_VARARG_PARAM(list, arg_num) (&(((zval *)list)[arg_num]))
#define IS_TRUE_P(x) (Z_TYPE_P(x) == IS_TRUE)

static inline zval *ddtrace_assign_variable(zval *variable_ptr, zval *value) {
#if PHP_VERSION_ID < 70400
    return zend_assign_to_variable(variable_ptr, value, IS_TMP_VAR);
#else
    return zend_assign_to_variable(variable_ptr, value, IS_TMP_VAR, false);
#endif
}

#if PHP_VERSION_ID < 70100
#define IS_VOID 0
#define MAY_BE_NULL 0
#define MAY_BE_STRING 0
#define MAY_BE_ARRAY 0

#define Z_EXTRA_P(z) Z_NEXT_P(z)

#undef zval_get_long
#define zval_get_long ddtrace_zval_get_long
static inline zend_long zval_get_long(zval *op) {
    if (Z_ISUNDEF_P(op)) {
        return 0;
    }
    return _zval_get_long(op);
}

#include <float.h>
#if defined(DBL_MANT_DIG) && defined(DBL_MIN_EXP)
#define PHP_DOUBLE_MAX_LENGTH (3 + DBL_MANT_DIG - DBL_MIN_EXP)
#else
#define PHP_DOUBLE_MAX_LENGTH 1080
#endif

enum {
    ZEND_STR_TRACE,
    ZEND_STR_LINE,
    ZEND_STR_FILE,
    ZEND_STR_MESSAGE,
    ZEND_STR_CODE,
    ZEND_STR_TYPE,
    ZEND_STR_FUNCTION,
    ZEND_STR_OBJECT,
    ZEND_STR_CLASS,
    ZEND_STR_OBJECT_OPERATOR,
    ZEND_STR_PAAMAYIM_NEKUDOTAYIM,
    ZEND_STR_ARGS,
    ZEND_STR_UNKNOWN,
    ZEND_STR_EVAL,
    ZEND_STR_INCLUDE,
    ZEND_STR_REQUIRE,
    ZEND_STR_INCLUDE_ONCE,
    ZEND_STR_REQUIRE_ONCE,
    ZEND_STR_PREVIOUS,
    ZEND_STR__LAST
};
extern zend_string *ddtrace_known_strings[ZEND_STR__LAST];
#define ZSTR_KNOWN(idx) ddtrace_known_strings[idx]

#define zend_declare_class_constant_ex(ce, name, value, access_type, doc_comment) zend_declare_class_constant(ce, ZSTR_VAL(name), ZSTR_LEN(name), value)
#endif

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
typedef void (*zif_handler)(INTERNAL_FUNCTION_PARAMETERS);

static inline void smart_str_append_printf(smart_str *dest, const char *format, ...) {
    va_list arg;
    va_start(arg, format);
    zend_string *str = vstrpprintf(0, format, arg);
    va_end(arg);
    smart_str_append(dest, str);
    zend_string_release(str);
}

static inline zend_string *php_base64_encode_str(const zend_string *str) {
    return php_base64_encode((const unsigned char*)(ZSTR_VAL(str)), ZSTR_LEN(str));
}

#define DD_PARAM_PROLOGUE(deref, separate) Z_PARAM_PROLOGUE(deref)

#define ZEND_HASH_REVERSE_FOREACH_STR_KEY_VAL(ht, _key, _val) \
    ZEND_HASH_REVERSE_FOREACH(ht, 0); \
    _key = _p->key; \
    _val = _z;

#if PHP_VERSION_ID >= 70100
#define ZSTR_KNOWN(idx) CG(known_strings)[idx]
#endif
#else
#define DD_PARAM_PROLOGUE Z_PARAM_PROLOGUE
#endif

#if PHP_VERSION_ID < 70300
#define GC_ADDREF(x) (++GC_REFCOUNT(x))
#define GC_DELREF(x) (--GC_REFCOUNT(x))
#define GC_SET_REFCOUNT(x, rc) (GC_REFCOUNT(x) = rc)

#define GC_IMMUTABLE (1 << 5)
#define GC_ADD_FLAGS(c, flag) GC_FLAGS(c) |= flag
#define GC_DEL_FLAGS(c, flag) GC_FLAGS(c) &= ~(flag)

#define rc_dtor_func zval_dtor_func

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

#define GC_IS_RECURSIVE(gc) ((gc)->u.v.nApplyCount > 0)
#define GC_PROTECT_RECURSION(gc) (++(gc)->u.v.nApplyCount)
#define GC_UNPROTECT_RECURSION(gc) (--(gc)->u.v.nApplyCount)

#define Z_IS_RECURSIVE_P(zv) GC_IS_RECURSIVE(Z_OBJPROP_P(zv))
#define Z_PROTECT_RECURSION_P(zv) GC_PROTECT_RECURSION(Z_OBJPROP_P(zv))
#define Z_UNPROTECT_RECURSION_P(zv) GC_UNPROTECT_RECURSION(Z_OBJPROP_P(zv))

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

#define zend_hash_real_init_packed(ht) zend_hash_real_init(ht, 1)
#define zend_hash_real_init_mixed(ht) zend_hash_real_init(ht, 0)
#define _zend_hash_append_ex(ht, key, zv, known) _zend_hash_append(ht, key, zv)
#define zend_hash_find_ex(ht, key, known) zend_hash_find(ht, key)
#define zend_hash_find_ex_ind(ht, key, known) zend_hash_find_ind(ht, key)

#define smart_str_free_ex(str, persistent) smart_str_free(str)

static inline zend_bool zend_ini_parse_bool(zend_string *str) {
    if ((ZSTR_LEN(str) == 4 && strcasecmp(ZSTR_VAL(str), "true") == 0)
      || (ZSTR_LEN(str) == 3 && strcasecmp(ZSTR_VAL(str), "yes") == 0)
      || (ZSTR_LEN(str) == 2 && strcasecmp(ZSTR_VAL(str), "on") == 0)) {
        return 1;
    } else {
        return atoi(ZSTR_VAL(str)) != 0;
    }
}

static inline zend_string *zend_ini_get_value(zend_string *name) {
    zend_ini_entry *ini_entry;

    ini_entry = zend_hash_find_ptr(EG(ini_directives), name);
    if (ini_entry) {
        return ini_entry->value ? ini_entry->value : ZSTR_EMPTY_ALLOC();
    } else {
        return NULL;
    }
}
#endif

#if PHP_VERSION_ID < 70400
#define ZEND_THIS (&EX(This))

#define Z_PROP_FLAG_P(z) Z_EXTRA_P(z)
#define ZVAL_COPY_VALUE_PROP ZVAL_COPY_VALUE

#define ZEND_HASH_FILL_SET(_val) do { \
        ZVAL_COPY_VALUE(&__fill_bkt->val, _val); \
        __fill_bkt->h = (__fill_idx); \
        __fill_bkt->key = NULL; \
    } while (0)

#define ZEND_HASH_FILL_NEXT() do { \
        __fill_bkt++; \
        __fill_idx++; \
    } while (0)

#define DD_PARAM_ERROR_CODE error_code
#else
#define DD_PARAM_ERROR_CODE _error_code
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

#define instanceof_function_slow instanceof_function

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

#define ZEND_ABSTRACT_ME_WITH_FLAGS(classname, name, arg_info, flags) ZEND_ABSTRACT_ME(classname, name, arg_info)

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

#define RETURN_THROWS RETURN_NULL

/* For regular arrays (non-persistent, storing zvals). */
static zend_always_inline void zend_array_release(zend_array *array)
{
    if (!(GC_FLAGS(array) & IS_ARRAY_IMMUTABLE)) {
        if (GC_DELREF(array) == 0) {
            zend_array_destroy(array);
        }
    }
}

#define ZEND_ARG_SEND_MODE(arg_info) (arg_info)->pass_by_reference
#define zend_value_error zend_type_error

#define zend_update_property_ex(scope, object, name, value) do { zval _zv; ZVAL_OBJ(&_zv, object); zend_update_property_ex(scope, &_zv, name, value); } while (0)
#endif

#if PHP_VERSION_ID < 80100
#undef ZEND_ATOL
#ifdef ZEND_ENABLE_ZVAL_LONG64
#define ZEND_ATOL(s) atoll((s))
#else
#define ZEND_ATOL(s) atol((s))
#endif
#define ZEND_ACC_READONLY 0

static zend_always_inline zend_result add_next_index_object(zval *arg, zend_object *obj) {
    zval tmp;

    ZVAL_OBJ(&tmp, obj);
    return zend_hash_next_index_insert(Z_ARRVAL_P(arg), &tmp) ? SUCCESS : FAILURE;
}

#include <main/snprintf.h>
#define zend_gcvt php_gcvt
#define ZEND_DOUBLE_MAX_LENGTH PHP_DOUBLE_MAX_LENGTH
static inline void smart_str_append_double(smart_str *str, double num, int precision, bool zero_fraction) {
    char buf[ZEND_DOUBLE_MAX_LENGTH];
    /* Model snprintf precision behavior. */
    zend_gcvt(num, precision ? precision : 1, '.', 'E', buf);
    smart_str_appends(str, buf);
    if (zero_fraction && zend_finite(num) && !strchr(buf, '.')) {
        smart_str_appendl(str, ".0", 2);
    }
}

#define zend_hash_find_known_hash zend_hash_find

static zend_always_inline bool zend_array_is_list(zend_array *array) {
    zend_long expected_idx = 0;
    zend_long num_idx;
    zend_string* str_idx;
    /* Empty arrays are lists */
    if (zend_hash_num_elements(array) == 0) {
        return 1;
    }

#if PHP_VERSION_ID >= 70100
    if (HT_IS_PACKED(array) && HT_IS_WITHOUT_HOLES(array)) {
        return 1;
    }
#endif

    /* Check if the list could theoretically be repacked */
    ZEND_HASH_FOREACH_KEY(array, num_idx, str_idx) {
        if (str_idx != NULL || num_idx != expected_idx++) {
            return 0;
        }
    } ZEND_HASH_FOREACH_END();

    return 1;
}
#endif

#if PHP_VERSION_ID < 80200
#define ZEND_ACC_READONLY_CLASS 0

static zend_always_inline bool zend_string_equals_cstr(const zend_string *s1, const char *s2, size_t s2_length)
{
    return ZSTR_LEN(s1) == s2_length && !memcmp(ZSTR_VAL(s1), s2, s2_length);
}

static zend_always_inline bool zend_string_starts_with_cstr(const zend_string *str, const char *prefix, size_t prefix_length)
{
    return ZSTR_LEN(str) >= prefix_length && !memcmp(ZSTR_VAL(str), prefix, prefix_length);
}

static zend_always_inline bool zend_string_starts_with(const zend_string *str, const zend_string *prefix)
{
    return zend_string_starts_with_cstr(str, ZSTR_VAL(prefix), ZSTR_LEN(prefix));
}

#define zend_string_starts_with_literal(str, prefix) \
    zend_string_starts_with_cstr(str, prefix, strlen(prefix))

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

#define ZEND_HASH_ELEMENT(ht, idx) (&ht->arData[idx].val)
#define ZEND_HASH_MAP_FOREACH_PTR ZEND_HASH_FOREACH_PTR
#define ZEND_HASH_MAP_FOREACH_STR_KEY_VAL ZEND_HASH_FOREACH_STR_KEY_VAL

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

#define Z_PARAM_ZVAL_OR_NULL(dest) Z_PARAM_ZVAL_EX(dest, 1, 0)

#endif

#if PHP_VERSION_ID < 80400
#define zend_parse_arg_func(arg, dest_fci, dest_fcc, check_null, error, free_trampoline) zend_parse_arg_func(arg, dest_fci, dest_fcc, check_null, error)
#undef ZEND_RAW_FENTRY
#define ZEND_RAW_FENTRY(zend_name, name, arg_info, flags, ...)   { zend_name, name, arg_info, (uint32_t) (sizeof(arg_info)/sizeof(struct _zend_internal_arg_info)-1), flags },
#endif

#endif  // DD_COMPATIBILITY_H
