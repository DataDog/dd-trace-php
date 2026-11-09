#ifndef HAVE_HOOK_TABLE_H
#define HAVE_HOOK_TABLE_H

/* Zend uses the low bits of numeric keys directly. Mix aligned pointers bijectively
 * to avoid long collision chains; the resulting key is not an address. */
static zend_always_inline zend_ulong zai_hook_table_pointer_key(const void *pointer) {
    zend_ulong key = (zend_ulong)(uintptr_t)pointer;
    key ^= key >> 33;
    key *= 0xff51afd7ed558ccdULL;
    key ^= key >> 29;
    key *= 0xc4ceb9fe1a85ec53ULL;
    key ^= key >> 32;
    return key;
}

/* {{{ these just allow us to keep most of the code ifdef free */
static inline bool zai_hook_table_insert_at(HashTable *table, zend_ulong index, void *inserting, size_t size,
                                            void **inserted) {
    return (*inserted = zend_hash_index_update_mem(table, index, inserting, size));
}

static inline bool zai_hook_table_find(HashTable *table, zend_ulong index, void **found) {
#if PHP_VERSION_ID < 70100
    return (*found = zend_hash_index_find_ptr(table, index));
#else
    zval *zv = _zend_hash_index_find(table, index);
    if (EXPECTED(zv == NULL)) {
        return false;
    } else {
        *found = Z_PTR_P(zv);
        return true;
    }
#endif
} /* }}} */

#if PHP_VERSION_ID < 80000
static inline void *zend_hash_str_find_ptr_lc(const HashTable *ht, const char *str, size_t len) {
    void *result;
    char *lc_str;

    /* Stack allocate small strings to improve performance */
    ALLOCA_FLAG(use_heap)

    lc_str = zend_str_tolower_copy((char *)do_alloca(len + 1, use_heap), str, len);
    result = zend_hash_str_find_ptr(ht, lc_str, len);
    free_alloca(lc_str, use_heap);

    return result;
}
#endif

#endif
