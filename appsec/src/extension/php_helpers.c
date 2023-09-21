// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "php_helpers.h"
#include "ddappsec.h"
#include "php_compat.h"

dd_php_array_type dd_php_determine_array_type(zval *nonnull zv)
{
    HashTable *myht = Z_ARRVAL_P(zv);

    uint32_t size = myht ? zend_hash_num_elements(myht) : 0;
    if (size == 0) {
        return php_array_type_sequential;
    }

    if (HT_IS_PACKED(myht) && HT_IS_WITHOUT_HOLES(myht)) {
        return php_array_type_sequential;
    }

    zend_string *key_s;
    zend_ulong key_i;
    zend_ulong i = 0;
    ZEND_HASH_FOREACH_KEY(myht, key_i, key_s)
    {
        if (key_s) {
            return php_array_type_associative;
        }
        if (key_i != i) {
            return php_array_type_associative;
        }
        i++;
    }
    ZEND_HASH_FOREACH_END();

    return php_array_type_sequential;
}

zval *nullable dd_php_get_autoglobal(
    int track_var, const char *nonnull name, size_t len)
{
    zval *var;
    if (EXPECTED(track_var >= 0)) {
        var = &PG(http_globals)[track_var];
        if (Z_TYPE_P(var) == IS_ARRAY) {
            return var;
        }
    }

    if (zend_is_auto_global_str((char *)name, len)) {
        var = &PG(http_globals)[track_var];
        if (Z_TYPE_P(var) == IS_ARRAY) {
            return var;
        }
    }
    return NULL;
}

zend_string *nullable dd_php_get_string_elem_cstr(
    const zval *nullable arr, const char *nonnull name, size_t len)
{
    if (UNEXPECTED(!arr)) {
        return NULL;
    }

    zval *zresult = zend_hash_str_find(Z_ARRVAL_P(arr), name, len);
    if (zresult == NULL) {
        return NULL;
    }

    ZVAL_DEREF(zresult);

    if (UNEXPECTED(Z_TYPE_P(zresult) != IS_STRING)) {
        return NULL;
    }

    return Z_STR_P(zresult);
}

zend_string *nullable dd_php_get_string_elem(
    const zval *nullable arr, zend_string *nonnull zstr)
{
    if (UNEXPECTED(!arr)) {
        return NULL;
    }

    zval *zresult = zend_hash_find(Z_ARRVAL_P(arr), zstr);
    if (zresult == NULL) {
        return NULL;
    }

    ZVAL_DEREF(zresult);

    if (Z_TYPE_P(zresult) != IS_STRING) {
        return NULL;
    }

    return Z_STR_P(zresult);
}
