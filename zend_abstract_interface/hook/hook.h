#ifndef ZAI_HOOK_H
#define ZAI_HOOK_H
#include "../zai_string/string.h"
#if PHP_VERSION_ID >= 80000
#include <zend_observer.h>
#endif

/* The Hook interface intends to abstract away the storage and resolution of hook targets */

/* {{{ staging functions
        Note: installation of hooks may occur after minit */
bool zai_hook_minit(void);
bool zai_hook_ginit(void);
bool zai_hook_rinit(void);
void zai_hook_post_startup(void);
void zai_hook_activate(void);
void zai_hook_clean(bool fast_shutdown);
void zai_hook_rshutdown(void);
void zai_hook_gshutdown(void);
void zai_hook_mshutdown(void); /* }}} */

typedef bool (*zai_hook_begin)(zend_ulong invocation, zend_execute_data *frame, void *auxiliary, void *dynamic);
typedef void (*zai_hook_end)(zend_ulong invocation, zend_execute_data *frame, zval *retval, void *auxiliary, void *dynamic);
typedef void (*zai_hook_generator_resume)(zend_ulong invocation, zend_execute_data *frame, zval *sent, void *auxiliary, void *dynamic);
typedef void (*zai_hook_generator_yield)(zend_ulong invocation, zend_execute_data *frame, zval *key, zval *yielded, void *auxiliary, void *dynamic);

/* {{{ auxiliary support */
typedef struct {
    void *data;
    void (*dtor)(void *data);
    void (*resolved)(void *data, bool found);
} zai_hook_aux; /* }}} */

/* {{{ zai_hook_aux ZAI_HOOK_AUX(void *pointer, void (*destructor)(void *pointer)) */
#define ZAI_HOOK_AUX_RESOLVED(pointer, destructor, resolvedfn) (zai_hook_aux){ .data = (pointer), .dtor = (destructor), .resolved = (resolvedfn) }
#define ZAI_HOOK_AUX(pointer, destructor) ZAI_HOOK_AUX_RESOLVED(pointer, destructor, NULL)
#define ZAI_HOOK_AUX_UNUSED ZAI_HOOK_AUX(NULL, NULL)
/* }}} */

/* {{{ zai_hook_install may be executed after minit and during request */
zend_long zai_hook_install(zai_str scope, zai_str function,
        zai_hook_begin begin, zai_hook_end end,
        zai_hook_aux aux, size_t dynamic); /* }}} */

/* {{{ zai_hook_install_generator may be executed after minit and during request */
zend_long zai_hook_install_generator(zai_str scope, zai_str function,
        zai_hook_begin begin, zai_hook_generator_resume resumption, zai_hook_generator_yield yield, zai_hook_end end,
        zai_hook_aux aux, size_t dynamic); /* }}} */

typedef zend_ulong zai_install_address;

/* {{{ zai_hook_install_resolved may only be executed during request
        this API requires no symbol names, or resolution, it may be used
        to associate a hook with anonymous symbols
        i.e. generators, closures, fibers */
zend_long zai_hook_install_resolved(zend_function *function,
        zai_hook_begin begin, zai_hook_end end,
        zai_hook_aux aux, size_t dynamic); /* }}} */

/* {{{ zai_hook_install_resolved_generator may only be executed during request
        this API works similarly to zai_hook_install_resolved */
zend_long zai_hook_install_resolved_generator(zend_function *function,
        zai_hook_begin begin, zai_hook_generator_resume resumption, zai_hook_generator_yield yield, zai_hook_end end,
        zai_hook_aux aux, size_t dynamic); /* }}} */

/* {{{ zai_hook_remove removes a hook from the request local hook tables. It does not touch static hook tables. */
bool zai_hook_remove(zai_str scope, zai_str function, zend_long index);
bool zai_hook_remove_resolved(zai_install_address function_address, zend_long index); /* }}} */

/* {{{ zai_hook_exclude_class prevents a hook from being installed on a specific class through inheritance. */
void zai_hook_exclude_class(zai_str scope, zai_str function, zend_long index, zend_string *lc_classname);
void zai_hook_exclude_class_resolved(zai_install_address function_address, zend_long index, zend_string *lc_classname); /* }}} */

/* {{{ zai_hook_memory_t structure is passed between
        continue and finish and managed by the hook interface */
typedef struct {
    zend_ulong invocation;
    zend_ulong hook_count;
    void *dynamic;
    /* Walkers and callbacks hold pointers into `dynamic`, so joins cannot resize it during a walk.
       Keep this state on the frame: fibers can suspend walks and resume or destroy them out of stack order. */
    bool walking;
} zai_hook_memory_t; /* }}} */

/* {{{ The interceptor supplies the version-specific frame lookup; NULL means no hook record. */
extern zai_hook_memory_t *(*zai_hook_frame_memory)(zend_execute_data *frame);

/* {{{ Return hook_id's payload for this frame invocation, or NULL if the hook has no record there. */
void *zai_hook_frame_dynamic(zend_execute_data *frame, zend_long hook_id); /* }}} */

/* {{{ Record only hook_id on an already-running frame so its end can be delivered, without calling begin.
       `memory` is the existing record or a zeroed record for the interceptor to file; the frame must be on the current stack.
       Returns false if the hook is inapplicable or the record is being walked. */
/* Called once for each newly joined frame to initialize the hook's zeroed payload. */
typedef void (*zai_hook_joined)(zend_execute_data *frame, void *auxiliary, void *dynamic, zend_ulong invocation);

bool zai_hook_join_frame(zend_execute_data *frame, zend_long hook_id, zai_hook_memory_t *memory, zai_hook_joined on_joined); /* }}} */

/* {{{ Join the frame; false means it cannot be joined.
       With observe=false, defer exit observation until a subsequent call with observe=true from the current frame, outside any callback sandbox. */
extern bool (*zai_hook_join_running_frame)(zend_execute_data *frame, zend_long hook_id, bool observe); /* }}} */

/* {{{ Join every active call to func, preserving the engine's frame-exit ordering. Installed by the interceptor. */
extern void (*zai_hook_join_running_frames)(zend_function *func, zend_long hook_id, zai_hook_joined on_joined);

/* {{{ Resolve scope::function and join its active calls. The caller must supply an end-only hook.
       File hooks are ignored because their frames have no function name. */
void zai_hook_join_running_frames_named(zai_str scope, zai_str function, zend_long hook_id, zai_hook_joined on_joined); /* }}} */

typedef enum {
    ZAI_HOOK_CONTINUED,
    ZAI_HOOK_BAILOUT,
    ZAI_HOOK_SKIP,
} zai_hook_continued;

/* {{{ zai_hook_continue shall execute begin handlers and return ZAI_HOOK_BAILOUT if
        the caller should bail out (one of the handlers returned false) */
zai_hook_continued zai_hook_continue(zend_execute_data *ex, zai_hook_memory_t *memory); /* }}} */

void zai_hook_generator_resumption(zend_execute_data *ex, zval *sent, zai_hook_memory_t *memory);
void zai_hook_generator_yielded(zend_execute_data *ex, zval *key, zval *yielded, zai_hook_memory_t *memory);

/* {{{ zai_hook_finish shall execute end handlers and cleanup reserved memory */
void zai_hook_finish(zend_execute_data *ex, zval *rv, zai_hook_memory_t *memory); /* }}} */

/* {{{ zai_hook_resolve_* functions are designed to do individual resolving */
void zai_hook_resolve_function(zend_function *function, zend_string *lcname);
void zai_hook_resolve_class(zend_class_entry *ce, zend_string *lcname);
void zai_hook_resolve_file(zend_op_array *op_array);

/* cleanup function to avoid memory leaking */
void zai_hook_unresolve_op_array(zend_op_array *op_array);

/* True if `source`, a path suffix, names `file`. An empty `source` matches every file. */
bool zai_hook_match_filepath(zend_string *file, zend_string *source);

/* Filename -> declared user functions, including closures. Entries are removed when their op_array is destroyed. */
typedef struct {
    uint32_t ordered;
    uint32_t size;
    zend_function *functions[];
} zai_function_location_entry;
HashTable *zai_hook_function_location_map(void);

/* {{{ private but externed for performance reasons */
extern TSRM_TLS HashTable zai_hook_resolved;
/* }}} */

#if PHP_VERSION_ID >= 80000
extern void (*zai_hook_on_update)(zend_function *func, bool remove, zend_observer_fcall_end_handler *next_end_handler);
extern void (*zai_hook_on_function_resolve)(zend_function *func);
#endif

zend_function *zai_hook_find_containing_function(zend_function *func);

typedef struct {
    bool active;
    zend_ulong index;
    zai_hook_begin *begin;
    zai_hook_end *end;
    zai_hook_aux *aux;
    zai_hook_generator_resume *generator_resume;
    zai_hook_generator_yield *generator_yield;
    struct {
        HashTable *ht;
        uint32_t iter;
    } iterator;
} zai_hook_iterator;
zai_hook_iterator zai_hook_iterate_installed(zai_str scope, zai_str function);
zai_hook_iterator zai_hook_iterate_resolved(zend_function *function);
void zai_hook_iterator_advance(zai_hook_iterator *iterator);
void zai_hook_iterator_free(zai_hook_iterator *iterator);

uint32_t zai_hook_count_installed(zai_str scope, zai_str function);
uint32_t zai_hook_count_resolved(zend_function *function);

/* {{{ */
static inline zai_install_address zai_hook_install_address_user(const zend_op_array *op_array) {
    return ((zend_ulong)op_array->opcodes) >> 5;
}
static inline zai_install_address zai_hook_install_address_internal(const zend_internal_function *function) {
    return ((zend_ulong)function) >> 5;
}
static inline zai_install_address zai_hook_install_address(const zend_function *function) {
    if (function->type == ZEND_INTERNAL_FUNCTION) {
        return zai_hook_install_address_internal(&function->internal_function);
    }
    return zai_hook_install_address_user(&function->op_array);
} /* }}} */

/* {{{ */
static inline zai_install_address zai_hook_frame_address(zend_execute_data *ex) {
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
    return zend_hash_index_exists(&zai_hook_resolved, zai_hook_install_address_user(op_array));
}
static inline bool zai_hook_installed_internal(zend_internal_function *function) {
    return zend_hash_index_exists(&zai_hook_resolved, zai_hook_install_address_internal(function));
}
/* }}} */

#endif  // ZAI_HOOK_H
