// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "php_compat.h"

#if PHP_VERSION_ID < 70100
#    define PRINT_ZVAL_INDENT 4
static void _print_hash_compat(
    smart_str *buf, HashTable *ht, int indent, zend_bool is_object)
{
    for (int i = 0; i < indent; i++) { smart_str_appendc(buf, ' '); }
    smart_str_appends(buf, "(\n");
    indent += PRINT_ZVAL_INDENT;

    zend_ulong num_key;
    zend_string *string_key;
    zval *tmp;
    ZEND_HASH_FOREACH_KEY_VAL_IND(ht, num_key, string_key, tmp)
    {
        for (int i = 0; i < indent; i++) { smart_str_appendc(buf, ' '); }
        smart_str_appendc(buf, '[');
        if (string_key) {
            if (is_object) {
                const char *prop_name, *class_name;
                size_t prop_len;
                int mangled = zend_unmangle_property_name_ex(
                    string_key, &class_name, &prop_name, &prop_len);

                smart_str_appendl(buf, prop_name, prop_len);
                if (class_name && mangled == SUCCESS) {
                    if (class_name[0] == '*') {
                        smart_str_appends(buf, ":protected");
                    } else {
                        smart_str_appends(buf, ":");
                        smart_str_appends(buf, class_name);
                        smart_str_appends(buf, ":private");
                    }
                }
            } else {
                smart_str_append(buf, string_key);
            }
        } else {
            smart_str_append_long(buf, num_key);
        }
        smart_str_appends(buf, "] => ");
        zend_print_zval_r_to_buf_compat(buf, tmp, indent + PRINT_ZVAL_INDENT);
        smart_str_appends(buf, "\n");
    }
    ZEND_HASH_FOREACH_END();
    indent -= PRINT_ZVAL_INDENT;
    for (int i = 0; i < indent; i++) { smart_str_appendc(buf, ' '); }
    smart_str_appends(buf, ")\n");
}
void zend_print_zval_r_to_buf_compat(
    smart_str *buf, zval *expr, int indent) /* {{{ */
{
    ZVAL_DEREF(expr);
    switch (Z_TYPE_P(expr)) {
    case IS_ARRAY:
        smart_str_appends(buf, "Array\n");
        if (ZEND_HASH_APPLY_PROTECTION(Z_ARRVAL_P(expr)) &&
            ++Z_ARRVAL_P(expr)->u.v.nApplyCount > 1) {
            smart_str_appends(buf, " *RECURSION*");
            Z_ARRVAL_P(expr)->u.v.nApplyCount--;
            return;
        }
        _print_hash_compat(buf, Z_ARRVAL_P(expr), indent, 0);
        if (ZEND_HASH_APPLY_PROTECTION(Z_ARRVAL_P(expr))) {
            Z_ARRVAL_P(expr)->u.v.nApplyCount--;
        }
        break;
    case IS_OBJECT: {
        HashTable *properties;
        int is_temp;

        zend_string *class_name =
            Z_OBJ_HANDLER_P(expr, get_class_name)(Z_OBJ_P(expr));
        smart_str_appends(buf, ZSTR_VAL(class_name));
        zend_string_release(class_name);

        smart_str_appends(buf, " Object\n");
        if (Z_OBJ_APPLY_COUNT_P(expr) > 0) {
            smart_str_appends(buf, " *RECURSION*");
            return;
        }
        if ((properties = Z_OBJDEBUG_P(expr, is_temp)) == NULL) {
            break;
        }

        Z_OBJ_INC_APPLY_COUNT_P(expr);
        _print_hash_compat(buf, properties, indent, 1);
        Z_OBJ_DEC_APPLY_COUNT_P(expr);

        if (is_temp) {
            zend_hash_destroy(properties);
            FREE_HASHTABLE(properties);
        }
        break;
    }
    case IS_LONG:
        smart_str_append_long(buf, Z_LVAL_P(expr));
        break;
    case IS_STRING:
        smart_str_append(buf, Z_STR_P(expr));
        break;
    default: {
        zend_string *str = zval_get_string(expr);
        smart_str_append(buf, str);
        zend_string_release(str);
    } break;
    }
}
#endif // PHP_VERSION_ID < 70100

#if PHP_VERSION_ID < 70300
static zend_string _zend_empty_string_st = {
    .gc.refcount = 1,
    .gc.u.v.type = IS_STRING,
    .gc.u.v.flags = IS_STR_PERSISTENT | IS_STR_INTERNED,
};
zend_string *zend_empty_string = &_zend_empty_string_st;

zend_bool zend_ini_parse_bool(zend_string *str)
{
    if ((ZSTR_LEN(str) == 4 && strcasecmp(ZSTR_VAL(str), "true") == 0) ||
        (ZSTR_LEN(str) == 3 && strcasecmp(ZSTR_VAL(str), "yes") == 0) ||
        (ZSTR_LEN(str) == 2 && strcasecmp(ZSTR_VAL(str), "on") == 0)) {
        return 1;
    }
    return atoi(ZSTR_VAL(str)) != 0; // NOLINT
}

static const uint32_t uninitialized_bucket[-HT_MIN_MASK] = {
    HT_INVALID_IDX, HT_INVALID_IDX};

const HashTable zend_empty_array = {.gc.refcount = 2,
    .gc.u.v.type = IS_ARRAY,
    .gc.u.v.flags = IS_ARRAY_IMMUTABLE,
    .u.flags =
        HASH_FLAG_STATIC_KEYS | HASH_FLAG_INITIALIZED | HASH_FLAG_PERSISTENT,
    .nTableMask = HT_MIN_MASK,
    .arData = (Bucket *)(((char *)(&uninitialized_bucket)) +
                         HT_HASH_SIZE(HT_MIN_MASK)),
    .nNumUsed = 0,
    .nNumOfElements = 0,
    .nTableSize = HT_MIN_SIZE,
    .nInternalPointer = HT_INVALID_IDX,
    .nNextFreeElement = 0,
    .pDestructor = ZVAL_PTR_DTOR};
#endif

#if PHP_VERSION_ID < 70400
zend_bool try_convert_to_string(zval *op)
{

    if (Z_TYPE_P(op) == IS_STRING) {
        return 1;
    }

    zend_object *old_exception = EG(exception);
    EG(exception) = NULL;

    zend_string *str = NULL;
    bool bailout = false;
    zend_try { str = _zval_get_string_func(op); }
    zend_catch
    {
        bailout = true;
        str = NULL;
    }
    zend_end_try();

    if (UNEXPECTED(bailout || EG(exception))) {
        if (str) {
            zend_string_release(str);
        }
        if (!EG(exception) && old_exception) {
            EG(exception) = old_exception;
        }
        return false;
    }

    if (old_exception) {
        EG(exception) = old_exception;
    }

    zval_ptr_dtor(op);
    ZVAL_STR(op, str);
    return true;
}
#endif
