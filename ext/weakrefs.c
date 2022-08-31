#include <php.h>

#include "compatibility.h"

// copied from zend_weakrefs.c, given that there is no public API on old PHP 8.0 versions

#define ZEND_WEAKREF_TAG_REF 0
#define ZEND_WEAKREF_TAG_MAP 1
#define ZEND_WEAKREF_TAG_HT 2
#define ZEND_WEAKREF_GET_TAG(p) (((uintptr_t)(p)) & 3)
#define ZEND_WEAKREF_GET_PTR(p) ((void *)(((uintptr_t)(p)) & ~3))
#define ZEND_WEAKREF_ENCODE(p, t) ((void *)(((uintptr_t)(p)) | (t)))

static void zend_weakref_register(zend_object *object, void *payload) {
    GC_ADD_FLAGS(object, IS_OBJ_WEAKLY_REFERENCED);

    zend_ulong obj_addr = (zend_ulong)object;
    zval *zv = zend_hash_index_find(&EG(weakrefs), obj_addr);
    if (!zv) {
        zend_hash_index_add_new_ptr(&EG(weakrefs), obj_addr, payload);
        return;
    }

    void *tagged_ptr = Z_PTR_P(zv);
    if (ZEND_WEAKREF_GET_TAG(tagged_ptr) == ZEND_WEAKREF_TAG_HT) {
        HashTable *ht = ZEND_WEAKREF_GET_PTR(tagged_ptr);
        zend_hash_index_add_new_ptr(ht, (zend_ulong)payload, payload);
        return;
    }

    /* Convert simple pointer to hashtable. */
    HashTable *ht = emalloc(sizeof(HashTable));
    zend_hash_init(ht, 0, NULL, NULL, 0);
    zend_hash_index_add_new_ptr(ht, (zend_ulong)tagged_ptr, tagged_ptr);
    zend_hash_index_add_new_ptr(ht, (zend_ulong)payload, payload);
    zend_hash_index_update_ptr(&EG(weakrefs), obj_addr, ZEND_WEAKREF_ENCODE(ht, ZEND_WEAKREF_TAG_HT));
}

static void zend_weakref_unregister(zend_object *object, void *payload) {
    zend_ulong obj_addr = (zend_ulong)object;
    void *tagged_ptr = zend_hash_index_find_ptr(&EG(weakrefs), obj_addr);
    ZEND_ASSERT(tagged_ptr && "Weakref not registered?");

    void *ptr = ZEND_WEAKREF_GET_PTR(tagged_ptr);
    uintptr_t tag = ZEND_WEAKREF_GET_TAG(tagged_ptr);
    if (tag != ZEND_WEAKREF_TAG_HT) {
        ZEND_ASSERT(tagged_ptr == payload);
        zend_hash_index_del(&EG(weakrefs), obj_addr);
        GC_DEL_FLAGS(object, IS_OBJ_WEAKLY_REFERENCED);

        /* Do this last, as it may destroy the object. */
        zend_hash_index_del((HashTable *)ptr, obj_addr);
        return;
    }

    HashTable *ht = ptr;
    tagged_ptr = zend_hash_index_find_ptr(ht, (zend_ulong)payload);
    ZEND_ASSERT(tagged_ptr && "Weakref not registered?");
    ZEND_ASSERT(tagged_ptr == payload);
    zend_hash_index_del(ht, (zend_ulong)payload);
    if (zend_hash_num_elements(ht) == 0) {
        GC_DEL_FLAGS(object, IS_OBJ_WEAKLY_REFERENCED);
        zend_hash_destroy(ht);
        FREE_HASHTABLE(ht);
        zend_hash_index_del(&EG(weakrefs), obj_addr);
    }

    /* Do this last, as it may destroy the object. */
    zend_hash_index_del((HashTable *)ZEND_WEAKREF_GET_PTR(payload), obj_addr);
}

zval *zend_weakrefs_hash_add(HashTable *ht, zend_object *key, zval *pData) {
    zval *zv = zend_hash_index_add(ht, (zend_ulong)key, pData);
    if (zv) {
        zend_weakref_register(key, ZEND_WEAKREF_ENCODE(ht, ZEND_WEAKREF_TAG_MAP));
    }
    return zv;
}

zend_result zend_weakrefs_hash_del(HashTable *ht, zend_object *key) {
    zval *zv = zend_hash_index_find(ht, (zend_ulong)key);
    if (zv) {
        zend_weakref_unregister(key, ZEND_WEAKREF_ENCODE(ht, ZEND_WEAKREF_TAG_MAP));
        return SUCCESS;
    }
    return FAILURE;
}
