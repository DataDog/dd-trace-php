#include <php.h>

#include <zend_closures.h>
#include <zend_generators.h>

#include "../compatibility.h"
#include "../configuration.h"

#include "uhook_arginfo.h"

#include <hook/hook.h>

#include "uhook.h"

extern void (*profiling_interrupt_function)(zend_execute_data *);

static inline zval *ddtrace_hookdata_property_id(zend_object *hookdata) {
    return OBJ_PROP_NUM(hookdata, 0);
}
static inline zval *ddtrace_hookdata_property_args(zend_object *hookdata) {
    return OBJ_PROP_NUM(hookdata, 1);
}
static inline zval *ddtrace_hookdata_property_returned(zend_object *hookdata) {
    return OBJ_PROP_NUM(hookdata, 2);
}
static inline zval *ddtrace_hookdata_property_exception(zend_object *hookdata) {
    return OBJ_PROP_NUM(hookdata, 3);
}

zend_class_entry *ddtrace_hook_data_ce;

static __thread HashTable dd_active_hooks;

typedef struct {
    zend_object *begin;
    zend_object *end;
    bool running;
    zend_long id;

    zend_function *resolved;
    zend_string *scope;
    zend_string *function;
} dd_uhook_def;

typedef struct {
    zend_object *hook_data;
} dd_uhook_dynamic;

HashTable *dd_uhook_collect_args(zend_execute_data *execute_data) {
    uint32_t num_args = EX_NUM_ARGS();

    HashTable *ht = emalloc(sizeof(*ht));
    zend_hash_init(ht, num_args, NULL, ZVAL_PTR_DTOR, 0);

    if (!num_args) {
        return ht;
    }

    zval *p = EX_VAR_NUM(0);
    zend_function *func = EX(func);
    ht->nNumOfElements = num_args;

    zend_hash_real_init(ht, 1);
    ZEND_HASH_FILL_PACKED(ht) {
        if (EX(func)->type == ZEND_USER_FUNCTION) {
            uint32_t first_extra_arg = MIN(num_args, func->op_array.num_args);

            for (zval *end = p + first_extra_arg; p < end; ++p) {
                if (Z_OPT_REFCOUNTED_P(p)) {
                    Z_ADDREF_P(p);
                }
                ZEND_HASH_FILL_ADD(p);
            }

            p = EX_VAR_NUM(func->op_array.last_var + func->op_array.T);
            num_args -= first_extra_arg;
        }

        // collect trailing variadic args
        for (zval *end = p + num_args; p < end; ++p) {
            if (Z_OPT_REFCOUNTED_P(p)) {
                Z_ADDREF_P(p);
            }
            ZEND_HASH_FILL_ADD(p);
        }
    }
    ZEND_HASH_FILL_END();

    return ht;
}

static void dd_uhook_call_hook(zend_execute_data *execute_data, zend_object *closure, zend_object *hook_data) {
    zval closure_zv, hook_data_zv;
    ZVAL_OBJ(&closure_zv, closure);
    ZVAL_OBJ(&hook_data_zv, hook_data);

    bool has_this = getThis() != NULL;
    zval rv;
    zai_symbol_call(has_this ? ZAI_SYMBOL_SCOPE_OBJECT : ZAI_SYMBOL_SCOPE_GLOBAL, has_this ? &EX(This) : NULL,
                    ZAI_SYMBOL_FUNCTION_CLOSURE, &closure_zv,
                    &rv, 1, &hook_data_zv);
    zval_ptr_dtor(&rv);
}

static bool dd_uhook_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    (void) invocation;

    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    dyn->hook_data = zend_objects_new(ddtrace_hook_data_ce);
    object_properties_init(dyn->hook_data, ddtrace_hook_data_ce);

    ZVAL_LONG(ddtrace_hookdata_property_id(dyn->hook_data), def->id);
    ZVAL_ARR(ddtrace_hookdata_property_args(dyn->hook_data), dd_uhook_collect_args(execute_data));

    if (def->begin && !def->running) {
        def->running = true;
        dd_uhook_call_hook(execute_data, def->begin, dyn->hook_data);
        def->running = false;
    }

    return true;
}

static void dd_uhook_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    (void) invocation;

    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (def->end && !def->running) {
        zval tmp;

        /* If the profiler doesn't handle a potential pending interrupt before
         * the observer's end function, then the callback will be at the top of
         * the stack even though it's not responsible.
         * This is why the profilers interrupt function is called here, to
         * give the profiler an opportunity to take a sample before calling the
         * tracing function.
         */
        if (profiling_interrupt_function) {
            profiling_interrupt_function(execute_data);
        }

        zval *returned = ddtrace_hookdata_property_returned(dyn->hook_data);
        ZVAL_COPY_VALUE(&tmp, returned);
        ZVAL_COPY(returned, retval);
        zval_ptr_dtor(&tmp);

        zval *exception = ddtrace_hookdata_property_exception(dyn->hook_data);
        ZVAL_COPY_VALUE(&tmp, exception);
        if (EG(exception)) {
            ZVAL_OBJ_COPY(exception, EG(exception));
        } else {
            ZVAL_NULL(exception);
        }
        zval_ptr_dtor(&tmp);

        def->running = true;
        dd_uhook_call_hook(execute_data, def->end, dyn->hook_data);
        def->running = false;
    }

    OBJ_RELEASE(dyn->hook_data);
}

static void dd_uhook_dtor(void *data) {
    dd_uhook_def *def = data;
    if (def->begin) {
        OBJ_RELEASE(def->begin);
    }
    if (def->end) {
        OBJ_RELEASE(def->end);
    }
    if (def->function) {
        zend_string_release(def->function);
        if (def->scope) {
            zend_string_release(def->scope);
        }
    }
    zend_hash_index_del(&dd_active_hooks, (zend_ulong)def->id);
    efree(def);
}

#if PHP_VERSION_ID < 70400
#define _error_code error_code
#endif

/* {{{ proto int DDTrace\install_hook(string|Closure|Generator target, ?Closure begin = null, ?Closure end = null) */
PHP_FUNCTION(DDTrace_install_hook) {
    zend_string *name = NULL;
    zend_function *resolved = NULL;
    zval *begin = NULL;
    zval *end = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 3)
#if PHP_VERSION_ID < 70200
        Z_PARAM_PROLOGUE(0);
#else
        Z_PARAM_PROLOGUE(0, 0);
#endif
        if (Z_TYPE_P(_arg) == IS_STRING) {
            name = Z_STR_P(_arg);
// We disable hooking closures for *now*. The zend_function * of the closure may have a smaller lifetime than any hook. (leading to use after free)
// Also disabling generators as these may reference closures...
// A possibility would be that hooking closures only affects the specific closure (override closure dtor / weakref it)? To be evaluated...
#if 0
        } else if (Z_TYPE_P(_arg) == IS_OBJECT && (Z_OBJCE_P(_arg) == zend_ce_closure || Z_OBJCE_P(_arg) == zend_ce_generator)) {
            if (Z_OBJCE_P(_arg) == zend_ce_closure) {
#if PHP_VERSION_ID >= 80000
                resolved = (zend_function *)zend_get_closure_method_def(Z_OBJ_P(_arg));
#else
                resolved = (zend_function *)zend_get_closure_method_def(_arg);
#endif
            } else {
                zend_generator *generator = (zend_generator *)Z_OBJ_P(_arg);
                if (generator->execute_data) {
                    resolved = generator->execute_data->func;
                } else {
                    // we're silent here, right?
                    _error_code = ZPP_ERROR_FAILURE;
                    break;
                }
            }
#endif
        } else {
            // we're silent here, right?
            _error_code = ZPP_ERROR_FAILURE;
            break;
        }
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_EX(begin, zend_ce_closure, 1, 0)
        Z_PARAM_OBJECT_OF_CLASS_EX(end, zend_ce_closure, 1, 0)
    ZEND_PARSE_PARAMETERS_END();

    if (!begin && !end) {
        RETURN_LONG(0);
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_LONG(0);
    }

    dd_uhook_def *def = emalloc(sizeof(*def));
    def->running = false;
    def->begin = begin ? Z_OBJ_P(begin) : NULL;
    if (def->begin) {
        GC_ADDREF(def->begin);
    }
    def->end = end ? Z_OBJ_P(end) : NULL;
    if (def->end) {
        GC_ADDREF(def->end);
    }
    def->id = -1;

    zend_long id;
    if (resolved) {
        def->resolved = resolved;
        def->function = NULL;

        id = zai_hook_install_resolved(resolved,
            dd_uhook_begin, dd_uhook_end,
            ZAI_HOOK_AUX(def, dd_uhook_dtor), sizeof(dd_uhook_dynamic));
    } else {
        const char *colon = strchr(ZSTR_VAL(name), ':');
        zai_string_view scope = ZAI_STRING_EMPTY, function = {.ptr = ZSTR_VAL(name), .len = ZSTR_LEN(name)};
        if (colon) {
            function.len = ZSTR_VAL(name) - colon;
            do ++colon; while (*colon == ':');
            def->scope = zend_string_init(colon, ZSTR_VAL(name) + ZSTR_LEN(name) - colon, 0);
            scope = (zai_string_view) {.ptr = ZSTR_VAL(def->scope), .len = ZSTR_LEN(def->scope)};
        } else {
            def->scope = NULL;
        }
        def->function = zend_string_init(function.ptr, function.len, 0);

        id = zai_hook_install(
                scope, function,
                dd_uhook_begin,
                dd_uhook_end,
                ZAI_HOOK_AUX(def, dd_uhook_dtor),
                sizeof(dd_uhook_dynamic));
    }

    if (id < 0) {
        RETURN_LONG(0);
    }

    def->id = id;
    zend_hash_index_add_ptr(&dd_active_hooks, (zend_ulong)def->id, def);
    RETURN_LONG(id);
} /* }}} */

/* {{{ proto void DDTrace\remove_hook(int $id) */
PHP_FUNCTION(DDTrace_remove_hook) {
    (void)return_value;

    zend_long id;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(id)
    ZEND_PARSE_PARAMETERS_END();

    dd_uhook_def *def;
    if ((def = zend_hash_index_find_ptr(&dd_active_hooks, (zend_ulong)id))) {
        if (def->function) {
            zai_string_view scope = ZAI_STRING_EMPTY, function = { .ptr = ZSTR_VAL(def->function), .len = ZSTR_LEN(def->function) };
            if (def->scope) {
                scope = (zai_string_view){ .ptr = ZSTR_VAL(def->scope), .len = ZSTR_LEN(def->scope) };
            }
            zai_hook_remove(scope, function, id);
        } else {
            zai_hook_remove_resolved(zai_hook_install_address(def->resolved), id);
        }
    }
}

void zai_uhook_rinit() {
    zend_hash_init(&dd_active_hooks, 8, NULL, NULL, 0);
}

void zai_uhook_rshutdown() {
    zend_hash_destroy(&dd_active_hooks);
}

#if PHP_VERSION_ID >= 80000
void zai_uhook_attributes_minit(void);
#endif
void zai_uhook_minit() {
    ddtrace_hook_data_ce = register_class_DDTrace_HookData();
    zend_register_functions(NULL, ext_functions, NULL, MODULE_PERSISTENT);
#if PHP_VERSION_ID >= 80000
    zai_uhook_attributes_minit();
#endif
}

#if PHP_VERSION_ID >= 80000
void zai_uhook_attributes_mshutdown(void);
#endif
void zai_uhook_mshutdown() {
    zend_unregister_functions(ext_functions,sizeof(ext_functions) / sizeof(zend_function_entry) - 1,NULL);
#if PHP_VERSION_ID >= 80000
    zai_uhook_attributes_mshutdown();
#endif
}
