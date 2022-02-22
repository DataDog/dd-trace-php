#ifndef ZAI_HOOK_H
#define ZAI_HOOK_H
// clang-format off
#include <symbols/symbols.h>

/* The Hook interface intends to abstract away the storage and resolution of hook targets */

/* {{{ staging functions
        Note: installation of hooks may occur after minit */
bool zai_hook_minit(void);
bool zai_hook_rinit(void);
void zai_hook_rshutdown(void);
void zai_hook_mshutdown(void); /* }}} */

/* {{{ proto bool function(mixed $aux) */
typedef zval zai_hook_begin_u; /* }}} */

/* {{{ proto void function(mixed $aux, $rv = null); */
typedef zval zai_hook_end_u; /* }}} */

typedef bool (*zai_hook_begin_i)(zend_execute_data *frame, void *auxiliary, void *dynamic ZAI_TSRMLS_DC);
typedef void (*zai_hook_end_i)(zend_execute_data *frame, zval *retval, void *auxiliary, void *dynamic ZAI_TSRMLS_DC);

typedef enum {
    ZAI_HOOK_INTERNAL,
    ZAI_HOOK_USER,
    ZAI_HOOK_UNUSED,
} zai_hook_type_t;

typedef struct {
    zai_hook_type_t type;
    union {
        zai_hook_begin_i i;
        zai_hook_begin_u u;
    } u;
} zai_hook_begin;

typedef struct {
    zai_hook_type_t type;
    union {
        zai_hook_end_i i;
        zai_hook_end_u u;
    } u;
} zai_hook_end;

/* {{{ auxiliary support */
typedef void* zai_hook_aux_i;
typedef void (*zai_hook_aux_u)(zval* aux);

typedef struct {
    zai_hook_type_t type;
    union {
        zai_hook_aux_i i;
        zai_hook_aux_u u;
    } u;
} zai_hook_aux; /* }}} */

/* {{{ zai_hook_[begin|end|aux] ZAI_HOOK_UNUSED(begin|end|aux)
        This macro must be used to fill begin, end, or aux
        arguments when they are not being used by the caller
        of install */
#define ZAI_HOOK_UNUSED(name)    \
    (zai_hook_##name) {          \
        .type = ZAI_HOOK_UNUSED  \
    }
/* }}} */

/* {{{ zai_hook_[begin|end|aux] ZAI_HOOK_USED(USER|INTERNAL, begin|end|aux, u|i, value) */
#define ZAI_HOOK_USED(kind, name, member, value)          \
    (zai_hook_##name) {                                   \
        .type = ZAI_HOOK_##kind,                          \
        .u = {                                            \
            .member =                                     \
                (zai_hook_##name##_##member)              \
                    value                                 \
        }                                                 \
    }                                                     \
/* }}} */

/* {{{ zai_hook_begin ZAI_HOOK_BEGIN_USER(zai_hook_begin_u zv) */
#define ZAI_HOOK_BEGIN_USER(zv) \
    ZAI_HOOK_USED(USER, begin, u, zv) /* }}} */

/* {{{ zai_hook_begin ZAI_HOOK_BEGIN_INTERNAL(zai_hook_begin_i handler) */
#define ZAI_HOOK_BEGIN_INTERNAL(handler) \
    ZAI_HOOK_USED(INTERNAL, begin, i, handler) /* }}} */

/* {{{ zai_hook_end ZAI_HOOK_END_USER(zai_hook_end_u zv) */
#define ZAI_HOOK_END_USER(zv) \
    ZAI_HOOK_USED(USER, end, u, zv)
/* }}} */

/* {{{ zai_hook_end ZAI_HOOK_END_INTERNAL(zai_hook_end_i handler)  */
#define ZAI_HOOK_END_INTERNAL(handler) \
    ZAI_HOOK_USED(INTERNAL, end, i, handler)
/* }}} */

/* {{{ zai_hook_aux ZAI_HOOK_AUX_USER(zai_hook_aux_f function) */
#define ZAI_HOOK_AUX_USER(function) \
    ZAI_HOOK_USED(USER, aux, u, function)
/* }}} */

/* {{{ zai_hook_aux ZAI_HOOK_AUX_INTERNAL(zai_hook_aux_p pointer) */
#define ZAI_HOOK_AUX_INTERNAL(pointer) \
    ZAI_HOOK_USED(INTERNAL, aux, i, pointer)
/* }}} */

/* {{{ zai_hook_install may be executed after minit and during request */
bool zai_hook_install(
        zai_hook_type_t type,
        zai_string_view scope,
        zai_string_view function,
        zai_hook_begin  begin,
        zai_hook_end    end,
        zai_hook_aux    aux,
        size_t dynamic ZAI_TSRMLS_DC); /* }}} */

/* {{{ zai_hook_install_resolved may only be executed during request
        this API requires no symbol names, or resolution, it may be used
        to associate a hook with anonymous symbols
        ie. generators, closures, fibers */
bool zai_hook_install_resolved(
        zai_hook_type_t type,
        zai_hook_begin  begin,
        zai_hook_end    end,
        zai_hook_aux    aux,
        size_t dynamic,
        zend_function *function ZAI_TSRMLS_DC); /* }}} */

/* {{{ zai_hook_memory_t structure is passed between
        continue and finish and managed by the hook interface */
typedef struct {
    zval *auxiliary;
    void *dynamic;
} zai_hook_memory_t; /* }}} */

/* {{{ zai_hook_continue shall execute begin handlers and return false if
        the caller should bail out (one of the handlers returned false) */
bool zai_hook_continue(zend_execute_data *ex, zai_hook_memory_t *memory ZAI_TSRMLS_DC); /* }}} */

/* {{{ zai_hook_finish shall execute end handlers and cleanup reserved memory */
void zai_hook_finish(zend_execute_data *ex, zval *rv, zai_hook_memory_t *memory ZAI_TSRMLS_DC); /* }}} */

/* {{{ zai_hook_resolve should be called as little as possible
        NOTE: will be called by hook interface on rinit, to resolve internal installs early */
void zai_hook_resolve(ZAI_TSRMLS_D); /* }}} */

/* {{{ private but externed for performance reasons */
extern __thread HashTable zai_hook_resolved;
/* }}} */

/* {{{ */
static inline zend_ulong zai_hook_install_address(zend_function *function) {
    if (function->type == ZEND_INTERNAL_FUNCTION) {
        return (zend_ulong)function;
    }
    return (zend_ulong)function->op_array.opcodes;
} /* }}} */

/* {{{ */
static inline zend_ulong zai_hook_frame_address(zend_execute_data *ex) {
#if PHP_VERSION_ID < 70000
    return zai_hook_install_address(ex->function_state.function);
#else
    return zai_hook_install_address(ex->func);
#endif
} /* }}} */

/* {{{ zai_hook_installed shall return true if there are installs for this frame */
static inline bool zai_hook_installed(zend_execute_data *ex) {
    return zend_hash_index_exists(&zai_hook_resolved, zai_hook_frame_address(ex));
}
/* }}} */

// clang-format on
#endif  // ZAI_HOOK_H
