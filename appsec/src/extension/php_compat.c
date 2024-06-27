// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "php_compat.h"

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
