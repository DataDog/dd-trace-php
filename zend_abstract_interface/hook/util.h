#ifndef HAVE_HOOK_UTIL_H
#define HAVE_HOOK_UTIL_H
/* {{{ utility functions used by interface */

/* {{{ */
static inline zval *zai_hook_this(zend_execute_data *ex) {
#if PHP_VERSION_ID < 70000
    return ex->current_this;
#else
    return Z_OBJ(ex->This) ? &ex->This : NULL;
#endif
} /* }}} */

/* {{{ */
bool zai_hook_returned_false(zval *zv) {
#if PHP_VERSION_ID < 70000
    return Z_TYPE_P(zv) == IS_BOOL && !Z_BVAL_P(zv);
#else
    return Z_TYPE_P(zv) == IS_FALSE;
#endif
} /* }}} */

/* {{{ */
static inline zend_ulong zai_hook_install_address(zend_function *function) {
    if (function->type == ZEND_INTERNAL_FUNCTION) {
        return (zend_ulong)function;
    }
    return (zend_ulong)function->op_array.opcodes;
} /* }}} */

/* {{{ effective install address for this frame */
static inline zend_ulong zai_hook_frame_address(zend_execute_data *ex) {
#if PHP_VERSION_ID < 70000
    return zai_hook_install_address(ex->function_state.function);
#else
    return zai_hook_install_address(ex->func);
#endif
} /* }}} */

/* {{{ */
static inline HashTable *zai_hook_install_table(ZAI_TSRMLS_D) {
    if (PG(modules_activated)) {
        return &zai_hook_request;
    }
    return &zai_hook_static;
} /* }}} */

/* {{{ */
static inline void zai_hook_copy_u(zai_hook_t *hook ZAI_TSRMLS_DC) {
    if (hook->begin.type != ZAI_HOOK_UNUSED) {
#if PHP_VERSION_ID < 70000
        zend_objects_store_add_ref_by_handle(Z_OBJ_HANDLE(hook->begin.u.u) ZAI_TSRMLS_CC);
#else
        Z_ADDREF(hook->begin.u.u);
#endif
    }

    if (hook->end.type != ZAI_HOOK_UNUSED) {
#if PHP_VERSION_ID < 70000
        zend_objects_store_add_ref_by_handle(Z_OBJ_HANDLE(hook->end.u.u) ZAI_TSRMLS_CC);
#else
        Z_ADDREF(hook->end.u.u);
#endif
    }
} /* }}} */

/* {{{ */
static inline void zai_hook_destroy_u(zai_hook_t *hook ZAI_TSRMLS_DC) {
    if (hook->begin.type != ZAI_HOOK_UNUSED) {
#if PHP_VERSION_ID < 70000
        zend_objects_store_del_ref_by_handle(Z_OBJ_HANDLE(hook->begin.u.u) ZAI_TSRMLS_CC);
#else
        zval_dtor(&hook->begin.u.u);
#endif
    }

    if (hook->end.type != ZAI_HOOK_UNUSED) {
#if PHP_VERSION_ID < 70000
        zend_objects_store_del_ref_by_handle(Z_OBJ_HANDLE(hook->end.u.u) ZAI_TSRMLS_CC);
#else
        zval_dtor(&hook->end.u.u);
#endif
    }
} /* }}} */

/* }}} */
#endif
