#include <Zend/zend_observer.h>
#include "../../hook/hook.h"

static void zai_interceptor_function_declared(zend_op_array *op_array, zend_string *name) {
    zai_hook_resolve_function((zend_function *)op_array, name);
}

#if PHP_VERSION_ID < 80300
#include "zend_extensions.h"
static inline void zai_reset_enum_rt_cache(zend_class_entry *ce, zend_string *name) {
    zend_function *func;
    if ((func = zend_hash_find_ptr(&ce->function_table, name))) {
        memset(RUN_TIME_CACHE(&func->op_array), 0, zend_internal_run_time_cache_reserved_size());
    }
}
#endif

static void zai_interceptor_class_linked(zend_class_entry *ce, zend_string *name) {
    zai_hook_resolve_class(ce, name);

#if PHP_VERSION_ID < 80300
    if (ce->ce_flags & ZEND_ACC_ENUM) {
        zai_reset_enum_rt_cache(ce, ZSTR_KNOWN(ZEND_STR_CASES));
        if (ce->enum_backing_type != IS_UNDEF) {
            zai_reset_enum_rt_cache(ce, ZSTR_KNOWN(ZEND_STR_FROM));
            zai_reset_enum_rt_cache(ce, ZSTR_KNOWN(ZEND_STR_TRYFROM_LOWERCASE));
        }
    }
#endif
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
