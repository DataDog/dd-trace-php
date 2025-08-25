#include "ddtrace.h"
#include "weak_resources.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void dd_resource_destroy(zval *zv) {
    zend_resource *rsrc = Z_RES_P(zv);
    zend_hash_index_del(&DDTRACE_G(resource_weak_storage), rsrc->handle);
    DDTRACE_G(resource_dtor_func)(zv);
}

void ddtrace_weak_resources_rinit() {
    zend_hash_init(&DDTRACE_G(resource_weak_storage), 8, NULL, ZVAL_PTR_DTOR, 0);
    DDTRACE_G(resource_dtor_func) = EG(regular_list).pDestructor;
    EG(regular_list).pDestructor = dd_resource_destroy;
}

void ddtrace_weak_resources_rshutdown() {
    zend_hash_destroy(&DDTRACE_G(resource_weak_storage));
    EG(regular_list).pDestructor = DDTRACE_G(resource_dtor_func);
}

static inline void dd_array_init_fresh(zval *zv, uint32_t size_hint) {
    if (size_hint > 0) {
        array_init_size(zv, size_hint);
    } else {
        array_init(zv);
    }
}

static inline void dd_ensure_unique_array(zval *array_zv) {
    if (Z_TYPE_P(array_zv) != IS_ARRAY) {
        dd_array_init_fresh(array_zv, 4);
        return;
    }

    HashTable *ht = Z_ARRVAL_P(array_zv);

    /* If the table is shared (refcount>1) OR immutable, duplicate it. */
    if (UNEXPECTED(GC_REFCOUNT(ht) > 1 || (ht->u.flags & IS_ARRAY_IMMUTABLE))) {
        HashTable *dup = zend_array_dup(ht);
        ZVAL_ARR(array_zv, dup); /* replace in place */
    }
}

void ddtrace_weak_resource_update(zend_resource *rsrc, zend_string *key, zval *data) {
    zval *array = zend_hash_index_find(&DDTRACE_G(resource_weak_storage), rsrc->handle);
    if (!array) {
        zval zv;
        dd_array_init_fresh(&zv, 4);
        array = zend_hash_index_add(&DDTRACE_G(resource_weak_storage), rsrc->handle, &zv);
    } else {
        dd_ensure_unique_array(array);
    }

    zend_hash_update(Z_ARR_P(array), key, data);
}

zval *ddtrace_weak_resource_get(zend_resource *rsrc, zend_string *key) {
    zval *array = zend_hash_index_find(&DDTRACE_G(resource_weak_storage), rsrc->handle);
    if (!array) {
        return NULL;
    }

    return zend_hash_find(Z_ARR_P(array), key);
}
