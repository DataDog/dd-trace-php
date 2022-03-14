#ifndef HAVE_HOOK_TABLE_H
#define HAVE_HOOK_TABLE_H

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

#endif
