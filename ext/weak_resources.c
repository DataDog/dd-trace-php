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

void ddtrace_weak_resource_update(zend_resource *rsrc, zend_string *key, zval *data) {
    zval *array = zend_hash_index_find(&DDTRACE_G(resource_weak_storage), rsrc->handle);
    if (!array) {
        zval zv;
        array_init(&zv);
        array = zend_hash_index_add(&DDTRACE_G(resource_weak_storage), rsrc->handle, &zv);
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
