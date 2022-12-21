#include <Zend/zend_observer.h>
#include "../../hook/hook.h"

static void zai_interceptor_function_declared(zend_op_array *op_array, zend_string *name) {
    zai_hook_resolve_function((zend_function *)op_array, name);
}

static void zai_interceptor_class_linked(zend_class_entry *ce, zend_string *name) {
    zai_hook_resolve_class(ce, name);
}

static zend_op_array *(*prev_compile_file)(zend_file_handle *file_handle, int type);
static zend_op_array *zai_interceptor_compile_file(zend_file_handle *file_handle, int type) {
    zend_op_array *op_array = prev_compile_file(file_handle, type);
#if 0
    // TODO: on branch merge with libdatadog
    zai_hook_resolve_file(op_array);
#endif
    return op_array;
}

void zai_interceptor_minit(void) {
    zend_observer_function_declared_register(zai_interceptor_function_declared);
    zend_observer_class_linked_register(zai_interceptor_class_linked);
}

void zai_interceptor_setup_resolving_post_startup(void) {
    prev_compile_file = zend_compile_file;
    zend_compile_file = zai_interceptor_compile_file;
}

void zai_interceptor_shutdown(void) {
}
