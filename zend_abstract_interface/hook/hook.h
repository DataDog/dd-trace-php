#ifndef ZAI_HOOK_H
#define ZAI_HOOK_H
// clang-format off
#include <symbols/symbols.h>

/* The Hook interface intends to abstract away the storage and resolution of hook targets */

/* {{{ staging functions
        Note: installation of hooks may occur after minit */
bool zai_hook_minit(void);
bool zai_hook_rinit(void);
void zai_hook_activate(void);
void zai_hook_clean(void);
void zai_hook_rshutdown(void);
void zai_hook_mshutdown(void); /* }}} */

typedef bool (*zai_hook_begin)(zend_execute_data *frame, void *auxiliary, void *dynamic);
typedef void (*zai_hook_end)(zend_execute_data *frame, zval *retval, void *auxiliary, void *dynamic);

/* {{{ auxiliary support */
typedef struct {
    void *data;
    void (*dtor)(void *data);
} zai_hook_aux; /* }}} */

/* {{{ zai_hook_aux ZAI_HOOK_AUX(void *pointer, void (*destructor)(void *pointer)) */
#define ZAI_HOOK_AUX(pointer, destructor) (zai_hook_aux){ .data = (pointer), .dtor = (destructor) }
#define ZAI_HOOK_AUX_UNUSED ZAI_HOOK_AUX(NULL, NULL)
/* }}} */

/* {{{ zai_hook_install may be executed after minit and during request */
zend_long zai_hook_install(
        zai_string_view scope,
        zai_string_view function,
        zai_hook_begin  begin,
        zai_hook_end    end,
        zai_hook_aux    aux,
        size_t dynamic); /* }}} */

/* {{{ zai_hook_install_resolved may only be executed during request
        this API requires no symbol names, or resolution, it may be used
        to associate a hook with anonymous symbols
        ie. generators, closures, fibers */
zend_long zai_hook_install_resolved(
        zai_hook_begin  begin,
        zai_hook_end    end,
        zai_hook_aux    aux,
        size_t dynamic,
        zend_function *function); /* }}} */

/* {{{ zai_hook_remove removes a hook from the request local hook tables. It does not touch static hook tables. */
void zai_hook_remove(zai_string_view scope, zai_string_view function, zend_long index);
void zai_hook_remove_resolved(zend_function *function, zend_long index); /* }}} */

/* {{{ zai_hook_memory_t structure is passed between
        continue and finish and managed by the hook interface */
typedef struct {
    zend_ulong invocation;
    void *dynamic;
} zai_hook_memory_t; /* }}} */

/* {{{ zai_hook_continue shall execute begin handlers and return false if
        the caller should bail out (one of the handlers returned false) */
bool zai_hook_continue(zend_execute_data *ex, zai_hook_memory_t *memory); /* }}} */

/* {{{ zai_hook_finish shall execute end handlers and cleanup reserved memory */
void zai_hook_finish(zend_execute_data *ex, zval *rv, zai_hook_memory_t *memory); /* }}} */

/* {{{ zai_hook_resolve_* functions are designed to do individual resolving */
void zai_hook_resolve_function(zend_function *function, zend_string *lcname);
void zai_hook_resolve_class(zend_class_entry *ce, zend_string *lcname);

/* {{{ private but externed for performance reasons */
extern __thread HashTable zai_hook_resolved;
/* }}} */

#if PHP_VERSION_ID >= 80000
extern void (*zai_hook_on_update)(zend_op_array *op_array, bool remove);
#endif

typedef struct {
    bool active;
    zend_ulong index;
    zai_hook_begin *begin;
    zai_hook_end *end;
    zai_hook_aux *aux;
    HashTableIterator iterator;
} zai_hook_iterator;
zai_hook_iterator zai_hook_iterate_installed(zai_string_view scope, zai_string_view function);
zai_hook_iterator zai_hook_iterate_resolved(zend_function *function);
void zai_hook_iterator_advance(zai_hook_iterator *iterator);

/* {{{ */
static inline zend_ulong zai_hook_install_address_user(zend_op_array *op_array) {
    return ((zend_ulong)op_array->opcodes) >> 5;
}
static inline zend_ulong zai_hook_install_address_internal(zend_internal_function *function) {
    return ((zend_ulong)function) >> 5;
}
static inline zend_ulong zai_hook_install_address(zend_function *function) {
    if (function->type == ZEND_INTERNAL_FUNCTION) {
        return zai_hook_install_address_internal(&function->internal_function);
    }
    return zai_hook_install_address_user(&function->op_array);
} /* }}} */

/* {{{ */
static inline zend_ulong zai_hook_frame_address(zend_execute_data *ex) {
    return zai_hook_install_address(ex->func);
} /* }}} */

/* {{{ zai_hook_installed shall return true if there are installs for this frame */
static inline bool zai_hook_installed(zend_execute_data *ex) {
    return zend_hash_index_exists(&zai_hook_resolved, zai_hook_frame_address(ex));
}
static inline bool zai_hook_installed_func(zend_function *func) {
    return zend_hash_index_exists(&zai_hook_resolved, zai_hook_install_address(func));
}
static inline bool zai_hook_installed_user(zend_op_array *op_array) {
#if PHP_VERSION_ID >= 80000
    zval *zv = zend_hash_index_find(&zai_hook_resolved, zai_hook_install_address_user(op_array));
    if (zv) {
        return Z_PTR_P(zv) != NULL;
    } else {
        zend_string *lcname = zend_string_tolower(op_array->function_name);
        zai_hook_resolve_function((zend_function *)op_array, lcname);
        zend_string_release(lcname);
        return zend_hash_index_find_ptr(&zai_hook_resolved, zai_hook_install_address_user(op_array)) != NULL;
    }
#else
    return zend_hash_index_exists(&zai_hook_resolved, zai_hook_install_address_user(op_array));
#endif
}
static inline bool zai_hook_installed_internal(zend_internal_function *function) {
    return zend_hash_index_exists(&zai_hook_resolved, zai_hook_install_address_internal(function));
}
/* }}} */

// clang-format on
#endif  // ZAI_HOOK_H
