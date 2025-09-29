#ifndef ZAI_UHOOK_H
#define ZAI_UHOOK_H

#include <php.h>
#include <sandbox/sandbox.h>

typedef struct {
    zend_object *closure;
    zend_fcall_info_cache fcc;
    bool is_static;
    zend_function func;
} dd_uhook_callback;

HashTable *dd_uhook_collect_args(zend_execute_data *execute_data);
void dd_uhook_report_sandbox_error(zend_execute_data *execute_data, zend_object *closure);
void dd_uhook_log_invocation(void (*log)(const char *, ...), zend_execute_data *execute_data, const char *type, zend_object *closure);
bool ddtrace_uhook_match_filepath(zend_string *file, zend_string *source);

void zai_uhook_rinit();
void zai_uhook_rshutdown();
void zai_uhook_minit(int module_number);
void zai_uhook_mshutdown();

void dd_uhook_callback_apply_scope(dd_uhook_callback *cb, zend_class_entry *scope);
static inline void dd_uhook_callback_ensure_scope(dd_uhook_callback *cb, zend_execute_data *execute_data) {
    zend_class_entry *scope;
    if (!cb->fcc.function_handler) {
        scope = zend_get_called_scope(execute_data);
        goto apply_scope;
    } else if (!cb->is_static) {
        bool has_this;
        scope = zend_get_called_scope(execute_data);
        if (scope != cb->fcc.called_scope) {
apply_scope:
            dd_uhook_callback_apply_scope(cb, scope);
            has_this = !cb->is_static && getThis() != NULL;
        } else {
            has_this = getThis() != NULL;
        }

        cb->fcc.object = has_this ? Z_OBJ(EX(This)) : NULL;
    }
}

#if PHP_VERSION_ID < 70400
#define ZEND_MAP_PTR(x) x
#endif
static inline void dd_uhook_callback_destroy(dd_uhook_callback *cb) {
    if (cb->closure) {
        if (cb->fcc.function_handler && !cb->is_static && cb->func.op_array.cache_size) {
            efree(ZEND_MAP_PTR(cb->func.op_array.run_time_cache));
        }
        OBJ_RELEASE(cb->closure);
    }
}
#if PHP_VERSION_ID < 70400
#undef ZEND_MAP_PTR
#endif

PHP_FUNCTION(DDTrace_trace_function);
PHP_FUNCTION(DDTrace_trace_method);
PHP_FUNCTION(DDTrace_hook_function);
PHP_FUNCTION(DDTrace_hook_method);
PHP_FUNCTION(dd_untrace);
#endif
