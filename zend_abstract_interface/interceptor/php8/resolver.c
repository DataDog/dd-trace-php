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
static void zai_interceptor_class_linked_fix_enums(zend_class_entry *ce, zend_string *name) {
    zai_hook_resolve_class(ce, name);

    if (ce->ce_flags & ZEND_ACC_ENUM) {
        zai_reset_enum_rt_cache(ce, ZSTR_KNOWN(ZEND_STR_CASES));
        if (ce->enum_backing_type != IS_UNDEF) {
            zai_reset_enum_rt_cache(ce, ZSTR_KNOWN(ZEND_STR_FROM));
            zai_reset_enum_rt_cache(ce, ZSTR_KNOWN(ZEND_STR_TRYFROM_LOWERCASE));
        }
    }
}
#endif

static void zai_interceptor_class_linked(zend_class_entry *ce, zend_string *name) {
    zai_hook_resolve_class(ce, name);
}

static zend_op_array *(*prev_compile_file)(zend_file_handle *file_handle, int type);
static zend_op_array *zai_interceptor_compile_file(zend_file_handle *file_handle, int type) {
    zend_op_array *op_array = prev_compile_file(file_handle, type);

    if (op_array) {
        zai_hook_resolve_file(op_array);
    }

    return op_array;
}

void zai_interceptor_minit(void) {
}

void zai_interceptor_setup_resolving_post_startup(void) {
#if PHP_VERSION_ID < 80300
    zend_long patch_version = Z_LVAL_P(zend_get_constant_str(ZEND_STRL("PHP_RELEASE_VERSION")));
    if (patch_version < 2) { // affects only 8.2.0 and 8.2.1
        zend_observer_class_linked_register(zai_interceptor_class_linked_fix_enums);
    } else
#endif
    {
        zend_observer_class_linked_register(zai_interceptor_class_linked);
    }

    zend_observer_function_declared_register(zai_interceptor_function_declared);

    prev_compile_file = zend_compile_file;
    zend_compile_file = zai_interceptor_compile_file;
}

void zai_interceptor_shutdown(void) {
}
