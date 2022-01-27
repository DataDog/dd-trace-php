#ifndef HAVE_HOOK_TABLE_H
#define HAVE_HOOK_TABLE_H

/* {{{ these just allow us to keep most of the code ifdef free */
static inline bool zai_hook_table_insert(HashTable *table, void *inserting, size_t size, void **inserted) {
#if PHP_VERSION_ID < 70000
    return zend_hash_next_index_insert(table, inserting, size, (void **)inserted) == SUCCESS;
#else
    return (*inserted = zend_hash_next_index_insert_mem(table, inserting, size));
#endif
}

static inline bool zai_hook_table_insert_at(HashTable *table, zend_ulong index, void *inserting, size_t size,
                                            void **inserted) {
#if PHP_VERSION_ID < 70000
    return zend_hash_index_update(table, index, inserting, size, (void **)inserted) == SUCCESS;
#else
    return (*inserted = zend_hash_index_update_mem(table, index, inserting, size));
#endif
}

static inline bool zai_hook_table_find(HashTable *table, zend_ulong index, HashTable **found) {
#if PHP_VERSION_ID < 70000
    return zend_hash_index_find(table, index, (void **)found) == SUCCESS;
#else
    return (*found = zend_hash_index_find_ptr(table, index));
#endif
} /* }}} */

/* {{{ uniform iterator for hooks */
#if PHP_VERSION_ID < 70000
#define ZAI_HOOK_FOREACH(table, hook, ...)                                                     \
    {                                                                                          \
        HashPosition __position;                                                               \
        zend_hash_internal_pointer_reset_ex(table, &__position);                               \
        while (zend_hash_get_current_data_ex(table, (void **)&hook, &__position) == SUCCESS) { \
            {__VA_ARGS__} zend_hash_move_forward_ex(table, &__position);                       \
        }                                                                                      \
    }
#else
#define ZAI_HOOK_FOREACH(table, hook, ...) ZEND_HASH_FOREACH_PTR(table, hook){__VA_ARGS__} ZEND_HASH_FOREACH_END()
#endif /* }}} */

#endif
