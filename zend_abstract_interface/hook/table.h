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

static inline bool zai_hook_table_find(HashTable *table, zend_ulong index, void **found) {
#if PHP_VERSION_ID < 70000
    return zend_hash_index_find(table, index, (void **)found) == SUCCESS;
#elif PHP_VERSION_ID < 70100
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


extern __thread HashTable zai_hook_resolved;

/* aligned to smallest common size for hash conflict avoidance on all versions: 32 bytes */
static inline bool zai_hook_resolved_table_insert(zend_ulong index, HashTable *inserting, size_t size,
                                            HashTable **inserted) {
    return zai_hook_table_insert_at(&zai_hook_resolved, ((zend_ulong)index) >> 5, inserting, size, (void **)inserted);
}

static inline bool zai_hook_resolved_table_find(zend_ulong index, HashTable **found) {
    return zai_hook_table_find(&zai_hook_resolved, ((zend_ulong)index) >> 5, (void **)found);
}

static inline bool zai_hook_resolved_table_del(zend_ulong index) {
    return zend_hash_index_del(&zai_hook_resolved, ((zend_ulong)index) >> 5);
}

/* {{{ */
static inline zend_ulong zai_hook_install_address(zend_function *function) {
    if (function->type == ZEND_INTERNAL_FUNCTION) {
        return (zend_ulong)function;
    }
    return (zend_ulong)function->op_array.opcodes;
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
