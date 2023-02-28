#include <php.h>

#include <zend_closures.h>
#include <zend_generators.h>

#include "../compatibility.h"
#include "../configuration.h"
#include "../logging.h"

#include "uhook_arginfo.h"

#include <hook/hook.h>

#include "uhook.h"
#include "../ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

extern void (*profiling_interrupt_function)(zend_execute_data *);

zend_class_entry *ddtrace_hook_data_ce;

static __thread HashTable dd_closure_hooks;
static __thread HashTable dd_active_hooks;

typedef struct {
    size_t size;
    zend_long id[];
} dd_closure_list;

typedef struct {
    zend_object *begin;
    zend_object *end;
    bool running;
    zend_long id;

    zend_function *resolved;
    zend_string *scope;
    zend_string *function;
    zend_object *closure;
} dd_uhook_def;

typedef struct {
    zend_object std;
    // first property is $data
    zval property_id;
    zval property_args;
    zval property_returned;
    zval property_exception;
    zend_ulong invocation;
    zend_execute_data *execute_data;
    ddtrace_span_data *span;
    ddtrace_span_stack *prior_stack;
} dd_hook_data;

typedef struct {
    dd_hook_data *hook_data;
} dd_uhook_dynamic;

static zend_object *dd_hook_data_create(zend_class_entry *class_type) {
    dd_hook_data *hook_data = ecalloc(1, sizeof(*hook_data));
    zend_object_std_init(&hook_data->std, class_type);
    object_properties_init(&hook_data->std, class_type);
    hook_data->std.handlers = zend_get_std_object_handlers();
    return &hook_data->std;
}

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

static void dd_uhook_call_hook(zend_execute_data *execute_data, zend_object *closure, dd_hook_data *hook_data) {
    zval closure_zv, hook_data_zv;
    ZVAL_OBJ(&closure_zv, closure);
    ZVAL_OBJ(&hook_data_zv, &hook_data->std);

    bool has_this = getThis() != NULL;
    zval rv;
    zai_symbol_call(has_this ? ZAI_SYMBOL_SCOPE_OBJECT : ZAI_SYMBOL_SCOPE_GLOBAL, has_this ? &EX(This) : NULL,
                    ZAI_SYMBOL_FUNCTION_CLOSURE, &closure_zv,
                    &rv, 1, &hook_data_zv);
    zval_ptr_dtor(&rv);
}

static bool dd_uhook_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (def->closure && def->closure != ZEND_CLOSURE_OBJECT(EX(func))) {
        dyn->hook_data = NULL;
        return true;
    }

    dyn->hook_data = (dd_hook_data *)dd_hook_data_create(ddtrace_hook_data_ce);

    dyn->hook_data->invocation = invocation;
    ZVAL_LONG(&dyn->hook_data->property_id, def->id);
    ZVAL_ARR(&dyn->hook_data->property_args, dd_uhook_collect_args(execute_data));

    if (def->begin && !def->running) {
        dyn->hook_data->execute_data = execute_data;

        def->running = true;
        dd_uhook_call_hook(execute_data, def->begin, dyn->hook_data);
        def->running = false;
    }
    dyn->hook_data->execute_data = NULL;

    return true;
}

static void dd_uhook_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (!dyn->hook_data) {
        return;
    }

    ddtrace_span_data *span = dyn->hook_data->span;
    if (span && span->duration != DDTRACE_DROPPED_SPAN && span->duration != DDTRACE_SILENTLY_DROPPED_SPAN) {
        zval *exception_zv = ddtrace_spandata_property_exception(span);
        if (EG(exception) && Z_TYPE_P(exception_zv) <= IS_FALSE) {
            ZVAL_OBJ_COPY(exception_zv, EG(exception));
        }

        dd_trace_stop_span_time(span);
    }

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

        zval *returned = &dyn->hook_data->property_returned;
        ZVAL_COPY_VALUE(&tmp, returned);
        ZVAL_COPY(returned, retval);
        zval_ptr_dtor(&tmp);

        zval *exception = &dyn->hook_data->property_exception;
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

    if (span) {
        dyn->hook_data->span = NULL;
        ddtrace_clear_execute_data_span(invocation, true);
        if (dyn->hook_data->prior_stack) {
            ddtrace_switch_span_stack(dyn->hook_data->prior_stack);
            OBJ_RELEASE(&dyn->hook_data->prior_stack->std);
        }
    }

    OBJ_RELEASE(&dyn->hook_data->std);
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
    zend_object *closure = NULL;

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 1, 3)
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
        } else if (Z_TYPE_P(_arg) == IS_OBJECT && (Z_OBJCE_P(_arg) == zend_ce_closure || Z_OBJCE_P(_arg) == zend_ce_generator)) {
            if (Z_OBJCE_P(_arg) == zend_ce_closure) {
                closure = Z_OBJ_P(_arg);
                resolved = (zend_function *)zend_get_closure_method_def(Z_OBJ_P(_arg));
            } else {
                zend_generator *generator = (zend_generator *)Z_OBJ_P(_arg);
                if (generator->execute_data) {
                    resolved = generator->execute_data->func;
                    if (ZEND_CALL_INFO(generator->execute_data) & ZEND_CALL_CLOSURE) {
                        closure = ZEND_CLOSURE_OBJECT(resolved);
                    }
                } else {
                    // we're silent here, right?
                    _error_code = ZPP_ERROR_FAILURE;
                    break;
                }
            }
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
        def->closure = closure;
        id = zai_hook_install_resolved(resolved,
            dd_uhook_begin, dd_uhook_end,
            ZAI_HOOK_AUX(def, dd_uhook_dtor), sizeof(dd_uhook_dynamic));

        if (id >= 0 && closure) {
            zval *hooks_zv;
            dd_closure_list *hooks;
            if ((hooks_zv = zend_hash_index_find(&dd_closure_hooks, (zend_ulong)(uintptr_t)closure))) {
                hooks = Z_PTR_P(hooks_zv);
                Z_PTR_P(hooks_zv) = hooks = erealloc(hooks, sizeof(dd_closure_list) + sizeof(zend_long) * ++hooks->size);
            } else {
                hooks = emalloc(sizeof(dd_closure_list) + sizeof(zend_long));
                hooks->size = 1;
                zend_hash_index_add_new_ptr(&dd_closure_hooks, (zend_ulong)(uintptr_t)closure, hooks);
            }
            hooks->id[hooks->size - 1] = id;
        }
    } else {
        const char *colon = strchr(ZSTR_VAL(name), ':');
        zai_string_view scope = ZAI_STRING_EMPTY, function = {.ptr = ZSTR_VAL(name), .len = ZSTR_LEN(name)};
        if (colon) {
            def->scope = zend_string_init(function.ptr, colon - ZSTR_VAL(name), 0);
            do ++colon; while (*colon == ':');
            def->function = zend_string_init(colon, ZSTR_VAL(name) + ZSTR_LEN(name) - colon, 0);
            scope = (zai_string_view) {.ptr = ZSTR_VAL(def->scope), .len = ZSTR_LEN(def->scope)};
            function = (zai_string_view) {.ptr = ZSTR_VAL(def->function), .len = ZSTR_LEN(def->function)};
        } else {
            def->scope = NULL;
            def->function = zend_string_init(function.ptr, function.len, 0);
        }
        def->closure = NULL;

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

ZEND_METHOD(DDTrace_HookData, span) {
    zend_object *stack = NULL;

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 0, 1)
        Z_PARAM_OPTIONAL
#if PHP_VERSION_ID < 70200
        Z_PARAM_PROLOGUE(0);
#else
        Z_PARAM_PROLOGUE(0, 0);
#endif
        if (Z_TYPE_P(_arg) == IS_OBJECT && (Z_OBJCE_P(_arg) == ddtrace_ce_span_data || Z_OBJCE_P(_arg) == ddtrace_ce_span_stack)) {
            stack = Z_OBJ_P(_arg);
            if (Z_OBJCE_P(_arg) == ddtrace_ce_span_data) {
                stack = &((ddtrace_span_data *)stack)->stack->std;
            }
        } else {
            // we're silent here, right?
            _error_code = ZPP_ERROR_FAILURE;
            break;
        }
    ZEND_PARSE_PARAMETERS_END();

    dd_hook_data *hookData = (dd_hook_data *)Z_OBJ_P(ZEND_THIS);

    if (hookData->span) {
        RETURN_OBJ_COPY(&hookData->span->std);
    }

    // pre-hook check
    if (!hookData->execute_data) {
        RETURN_NULL();
    }

    // By this functionality we provide the ability to also switch the stack automatically back when the span attached to the function is closed
    if (stack) {
        ddtrace_span_data *span = zend_hash_index_find_ptr(&DDTRACE_G(traced_spans), hookData->invocation);
        if (span) {
            if (span->stack != (ddtrace_span_stack *)stack) {
                ddtrace_log_errf("Could not switch stack for hook in %s:%d", zend_get_executed_filename(), zend_get_executed_lineno());
            }
        } else {
            hookData->prior_stack = DDTRACE_G(active_stack);
            GC_ADDREF(&DDTRACE_G(active_stack)->std);
            ddtrace_switch_span_stack((ddtrace_span_stack *)stack);
        }
    } else if ((hookData->execute_data->func->common.fn_flags & ZEND_ACC_GENERATOR)) {
        if (!zend_hash_index_exists(&DDTRACE_G(traced_spans), hookData->invocation)) {
            hookData->prior_stack = DDTRACE_G(active_stack);
            GC_ADDREF(&DDTRACE_G(active_stack)->std);
            ddtrace_switch_span_stack(ddtrace_init_span_stack());
            GC_DELREF(&DDTRACE_G(active_stack)->std);
        }
    }

    hookData->span = ddtrace_alloc_execute_data_span(hookData->invocation, hookData->execute_data);

    RETURN_OBJ_COPY(&hookData->span->std);
}

ZEND_METHOD(DDTrace_HookData, overrideArguments) {
    (void)return_value;

    dd_hook_data *hookData = (dd_hook_data *)Z_OBJ_P(ZEND_THIS);

    zend_array *args;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY_HT(args)
    ZEND_PARSE_PARAMETERS_END();

    // pre-hook check
    if (!hookData->execute_data) {
        RETURN_FALSE;
    }

    int passed_args = ZEND_CALL_NUM_ARGS(hookData->execute_data);
    zend_function *func = hookData->execute_data->func;
    if (MAX(func->common.num_args, passed_args) < zend_hash_num_elements(args)) {
        // Adding args would mean that we would have to possibly transfer execute_data to a new stack, but changing that pointer may break all sorts of extensions
        ddtrace_log_errf("Cannot set more args than provided: got too many arguments for hook in %s:%d", zend_get_executed_filename(), zend_get_executed_lineno());
        RETURN_FALSE;
    }

    if (func->common.required_num_args > zend_hash_num_elements(args)) {
        ddtrace_log_errf("Not enough args provided for hook in %s:%d", zend_get_executed_filename(), zend_get_executed_lineno());
        RETURN_FALSE;
    }

    if (ZEND_USER_CODE(func->type) && hookData->execute_data->opline > func->op_array.opcodes + zend_hash_num_elements(args)) {
        ddtrace_log_errf("Can't pass less args to an untyped function than originally passed (minus extra args) in %s:%d",
                         zend_get_executed_filename(), zend_get_executed_lineno());
        RETURN_FALSE;
    }

    // When observers are executed, moving extra args behind the last temporary already happened
    zval *arg = ZEND_CALL_VAR_NUM(hookData->execute_data, 0), *last_arg = ZEND_USER_CODE(func->type) ? arg + func->common.num_args : ((void *)~0);
    zval *val;
    int i = 0;
    ZEND_HASH_FOREACH_VAL(args, val) {
        if (arg >= last_arg) {
            // extra-arg handling
            arg = ZEND_CALL_VAR_NUM(hookData->execute_data, func->op_array.last_var + func->op_array.T);
            last_arg = (void *)~0;
        }
        if (i++ >= passed_args) {
            ZVAL_COPY(arg, val);
        } else {
            zval garbage;
            ZVAL_COPY_VALUE(&garbage, arg);
            ZVAL_COPY(arg, val);
            zval_ptr_dtor(&garbage);
        }

        ++arg;
    } ZEND_HASH_FOREACH_END();

    ZEND_CALL_NUM_ARGS(hookData->execute_data) = i;

    while (i++ < passed_args) {
        if (arg >= last_arg) {
            // extra-arg handling
            arg = ZEND_CALL_VAR(hookData->execute_data, func->op_array.last_var + func->op_array.T);
            last_arg = (void *)~0;
        }
        zval_ptr_dtor(++arg);
    }

    RETURN_TRUE;
}

void zai_uhook_rinit() {
    zend_hash_init(&dd_active_hooks, 8, NULL, NULL, 0);
    zend_hash_init(&dd_closure_hooks, 8, NULL, NULL, 0);
}

void zai_uhook_rshutdown() {
    zend_hash_destroy(&dd_closure_hooks);
    zend_hash_destroy(&dd_active_hooks);
}

static zend_object_free_obj_t dd_uhook_closure_free_obj;
static void dd_uhook_closure_free_wrapper(zend_object *object) {
    dd_closure_list *hooks;
    zai_install_address address = zai_hook_install_address(zend_get_closure_method_def(object));
    if ((hooks = zend_hash_index_find_ptr(&dd_closure_hooks, (zend_ulong)(uintptr_t)object))) {
        for (size_t i = 0; i < hooks->size; ++i) {
            zai_hook_remove_resolved(address, hooks->id[i]);
        }
        efree(hooks);
        zend_hash_index_del(&dd_closure_hooks, (zend_ulong) (uintptr_t) object);
    }
    dd_uhook_closure_free_obj(object);
}

#if PHP_VERSION_ID >= 80000
void zai_uhook_attributes_minit(void);
#endif
void zai_uhook_minit() {
    ddtrace_hook_data_ce = register_class_DDTrace_HookData();
    ddtrace_hook_data_ce->create_object = dd_hook_data_create;

    zend_register_functions(NULL, ext_functions, NULL, MODULE_PERSISTENT);

#if PHP_VERSION_ID >= 80000
    zai_uhook_attributes_minit();
#endif

    // get hold of a Closure object to access handlers
    zend_objects_store objects_store = EG(objects_store);
    zend_object *closure;
    EG(objects_store) = (zend_objects_store){
        .object_buckets = &closure,
        .free_list_head = 0,
        .size = 1,
        .top = 0
    };
    zend_ce_closure->create_object(zend_ce_closure);

    dd_uhook_closure_free_obj = closure->handlers->free_obj;
    ((zend_object_handlers *)closure->handlers)->free_obj = dd_uhook_closure_free_wrapper;

    efree(closure);
    EG(objects_store) = objects_store;
}

#if PHP_VERSION_ID >= 80000
void zai_uhook_attributes_mshutdown(void);
#endif
void zai_uhook_mshutdown() {
    zend_unregister_functions(ext_functions, sizeof(ext_functions) / sizeof(zend_function_entry) - 1, NULL);
#if PHP_VERSION_ID >= 80000
    zai_uhook_attributes_mshutdown();
#endif
}
