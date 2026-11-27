// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "php_helpers.h"
#include "ddappsec.h"
#include <ext/compatibility.h>

dd_php_array_type dd_php_determine_array_type(const zend_array *nonnull myht)
{
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
    ZEND_HASH_FOREACH_KEY((zend_array *)myht, key_i, key_s)
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
    assert(track_var >= 0);

    zval *var;
    var = &PG(http_globals)[track_var];
    if (Z_TYPE_P(var) == IS_ARRAY) {
        return var;
    }

    if (zend_is_auto_global_str((char *)name, len)) {
        var = &PG(http_globals)[track_var];
        if (Z_TYPE_P(var) == IS_ARRAY) {
            return var;
        }
    }
    return NULL;
}

const zend_array *nonnull dd_get_superglob_or_equiv(
    // NOLINTNEXTLINE
    const char *name, size_t name_len, int track, zend_array *nullable equiv)
{
    zval *ret;
    if (equiv) {
        ret = zend_hash_str_find(equiv, name, name_len);
    } else {
        ret = dd_php_get_autoglobal(track, name, name_len);
    }

    if (!ret || Z_TYPE_P(ret) != IS_ARRAY) {
        return &zend_empty_array;
    }

    return Z_ARRVAL_P(ret);
}

zend_string *nullable dd_php_get_string_elem_cstr(
    const zend_array *nullable arr, const char *nonnull name, size_t len)
{
    if (UNEXPECTED(!arr)) {
        return NULL;
    }

    zval *zresult = zend_hash_str_find(arr, name, len);
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
    const zend_array *nullable arr, zend_string *nonnull zstr)
{
    if (UNEXPECTED(!arr)) {
        return NULL;
    }

    zval *zresult = zend_hash_find(arr, zstr);
    if (zresult == NULL) {
        return NULL;
    }

    ZVAL_DEREF(zresult);

    if (Z_TYPE_P(zresult) != IS_STRING) {
        return NULL;
    }

    return Z_STR_P(zresult);
}

bool dd_emalloc_fits(size_t size)
{
    // Account for alignment and debug-build metadata before deciding which
    // Zend allocation path the request takes. Reject arithmetic overflow.
    if (size > SIZE_MAX - (ZEND_MM_ALIGNMENT - 1) - ZEND_MM_OVERHEAD) {
        return false;
    }
    size = ZEND_MM_ALIGNED_SIZE(MAX(size, 1)) + ZEND_MM_OVERHEAD;

    // Small/large runs may need a whole new chunk even for a tiny request.
    size_t growth = ZEND_MM_CHUNK_SIZE;
    if (size > ZEND_MM_MAX_LARGE_SIZE) {
        // Huge runs have a separate mapping and a small tracking allocation.
        // Round to chunks to cover page rounding (and Windows' chunk
        // rounding), then reserve a chunk for the tracking allocation.
        if (size > SIZE_MAX - (ZEND_MM_CHUNK_SIZE - 1) - ZEND_MM_CHUNK_SIZE) {
            return false;
        }
        growth = ZEND_MM_ALIGNED_SIZE_EX(size, ZEND_MM_CHUNK_SIZE) +
                 ZEND_MM_CHUNK_SIZE;
    }

    if (PG(memory_limit) < 0) {
        return true;
    }
    size_t limit = (size_t)PG(memory_limit);
    size_t used = zend_memory_usage(true);
    // No PHP callbacks or allocations may intervene between this check and
    // emalloc. The request heap is local to this thread. Refusing potentially
    // reusable space is deliberate: its fragmentation is not exposed here.
    return used <= limit && growth <= limit - used;
}
