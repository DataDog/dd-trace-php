#include <php.h>
#include <zend_closures.h>
#include <hook/hook.h>
#include "uhook.h"
#include "../configuration.h"
#include "../span.h"
#include <sandbox/sandbox.h>

#include <components/log/log.h>

extern void (*profiling_interrupt_function)(zend_execute_data *);

typedef struct {
    zend_object *begin;
    zend_object *end;
    bool tracing;
    bool run_if_limited;
    bool active;
    bool allow_recursion;
} dd_uhook_def;

typedef struct {
    zend_array *args;
    ddtrace_span_data *span;
    bool skipped;
    bool dropped_span;
    bool was_primed;
} dd_uhook_dynamic;

static bool dd_uhook_call(zend_object *closure, bool tracing, dd_uhook_dynamic *dyn, zend_execute_data *execute_data, zval *retval) {
    zval rv, closure_zv, args_zv, exception_zv;
    ZVAL_OBJ(&closure_zv, closure);
    ZVAL_ARR(&args_zv, dyn->args);
    if (EG(exception)) {
        ZVAL_OBJ(&exception_zv, EG(exception));
    } else {
        ZVAL_NULL(&exception_zv);
    }

    bool success;
    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);

    if (tracing) {
        zval span_zv;
        ZVAL_OBJ(&span_zv, &dyn->span->std);
        zai_symbol_scope_t scope_type = ZAI_SYMBOL_SCOPE_GLOBAL;
        void *scope = NULL;
        if (getThis()) {
            scope_type = ZAI_SYMBOL_SCOPE_OBJECT;
            scope = &EX(This);
        } else if (EX(func)->common.scope) {
            scope = zend_get_called_scope(execute_data);
            if (scope) {
                scope_type = ZAI_SYMBOL_SCOPE_CLASS;
            }
        }
        success = zai_symbol_call(scope_type, scope,
                        ZAI_SYMBOL_FUNCTION_CLOSURE, &closure_zv,
                        &rv, 4 | ZAI_SYMBOL_SANDBOX, &sandbox, &span_zv, &args_zv, retval, &exception_zv);
    } else {
        if (EX(func)->common.scope) {
            zval *This = getThis();
            if (!This) {
                This = &EG(uninitialized_zval);
            }
            zval scope;
            ZVAL_NULL(&scope);
            zend_class_entry *scope_ce = zend_get_called_scope(execute_data);
            if (scope_ce) {
                ZVAL_STR(&scope, scope_ce->name);
            }
            success = zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
                                      ZAI_SYMBOL_FUNCTION_CLOSURE, &closure_zv,
                                      &rv, 5 | ZAI_SYMBOL_SANDBOX, &sandbox, This, &scope, &args_zv, retval, &exception_zv);
        } else {
            success = zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL,
                                      ZAI_SYMBOL_FUNCTION_CLOSURE, &closure_zv,
                                      &rv, 3 | ZAI_SYMBOL_SANDBOX, &sandbox, &args_zv, retval, &exception_zv);
        }
    }

    if (!success || PG(last_error_message)) {
        dd_uhook_report_sandbox_error(execute_data, closure);
    }
    zai_sandbox_close(&sandbox);

    zval_ptr_dtor(&rv);

    return Z_TYPE(rv) != IS_FALSE;
}

static bool dd_uhook_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if ((!def->run_if_limited && ddtrace_tracer_is_limited()) || (def->active && !def->allow_recursion) || !get_DD_TRACE_ENABLED()) {
        dyn->skipped = true;
        return true;
    }

    def->active = true; // recursion protection
    dyn->skipped = false;
    dyn->was_primed = false;
    dyn->dropped_span = false;
    dyn->args = dd_uhook_collect_args(execute_data);

    if (def->tracing) {
        dyn->span = ddtrace_alloc_execute_data_span(invocation, execute_data);
    }

    if (def->begin) {
        LOGEV(HOOK_TRACE, dd_uhook_log_invocation(log, execute_data, "begin", def->begin););

        dyn->dropped_span = !dd_uhook_call(def->begin, def->tracing, dyn, execute_data, &EG(uninitialized_zval));
        if (def->tracing && dyn->dropped_span) {
            ddtrace_clear_execute_data_span(invocation, false);
        }
    }

    return true;
}

// create an own span for every generator resumption
static void dd_uhook_generator_resumption(zend_ulong invocation, zend_execute_data *execute_data, zval *value, void *auxiliary, void *dynamic) {
    (void)value;

    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (dyn->skipped || !dyn->was_primed) {
        dyn->was_primed = true;
        return;
    }

    if (!get_DD_TRACE_ENABLED()) {
        dyn->dropped_span = true;
        return;
    }

    if (def->tracing) {
        dyn->span = ddtrace_alloc_execute_data_span(invocation, execute_data);
        dyn->dropped_span = false;
    }

    if (def->begin) {
        LOGEV(HOOK_TRACE, dd_uhook_log_invocation(log, execute_data, "generator resume", def->begin););
        dyn->dropped_span = !dd_uhook_call(def->begin, def->tracing, dyn, execute_data, value);
        if (def->tracing && dyn->dropped_span) {
            ddtrace_clear_execute_data_span(invocation, false);
        }
    }
}

static void dd_uhook_generator_yield(zend_ulong invocation, zend_execute_data *execute_data, zval *key, zval *value, void *auxiliary, void *dynamic) {
    (void)key;

    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (dyn->skipped) {
        return;
    }

    if (def->tracing && !dyn->dropped_span) {
        if (dyn->span->duration == DDTRACE_DROPPED_SPAN) {
            dyn->dropped_span = true;
            ddtrace_clear_execute_data_span(invocation, false);

            if (get_DD_TRACE_ENABLED()) {
                LOG_ONCE(ERROR, "Cannot run tracing closure for %s(); spans out of sync", ZSTR_VAL(EX(func)->common.function_name));
            }
        } else if (dyn->span->duration != DDTRACE_SILENTLY_DROPPED_SPAN) {
            zval *exception_zv = &dyn->span->property_exception;
            if (EG(exception) && Z_TYPE_P(exception_zv) <= IS_FALSE) {
                ZVAL_OBJ_COPY(exception_zv, EG(exception));
            }

            dd_trace_stop_span_time(dyn->span);
        }
    }

    if (def->end && (!def->tracing || !dyn->dropped_span)) {
        LOGEV(HOOK_TRACE, dd_uhook_log_invocation(log, execute_data, "generator yield", def->end););
        bool keep_span = dd_uhook_call(def->end, def->tracing, dyn, execute_data, value);
        if (def->tracing && !dyn->dropped_span) {
            ddtrace_clear_execute_data_span(invocation, keep_span);
        }
        dyn->dropped_span = true;
    }
}

static void dd_uhook_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;
    bool keep_span = true;

    if (dyn->skipped) {
        return;
    }

    if (def->tracing && !dyn->dropped_span) {
        if (dyn->span->duration == DDTRACE_DROPPED_SPAN) {
            dyn->dropped_span = true;
            ddtrace_clear_execute_data_span(invocation, false);

            if (get_DD_TRACE_ENABLED()) {
                LOG_ONCE(ERROR, "Cannot run tracing closure for %s(); spans out of sync", ZSTR_VAL(EX(func)->common.function_name));
            }
        } else if (dyn->span->duration != DDTRACE_SILENTLY_DROPPED_SPAN) {
            zval *exception_zv = &dyn->span->property_exception;
            if (EG(exception) && Z_TYPE_P(exception_zv) <= IS_FALSE) {
                ZVAL_OBJ_COPY(exception_zv, EG(exception));
            }

            dd_trace_stop_span_time(dyn->span);
        }
    }

    if (def->end && !dyn->dropped_span) {
        /* If the profiler doesn't handle a potential pending interrupt before
         * the observer's end function, then the callback will be at the top of
         * the stack even though it's not responsible.
         * This is why the profiler's interrupt function is called here, to
         * give the profiler an opportunity to take a sample before calling the
         * tracing function.
         */
        if (profiling_interrupt_function) {
            profiling_interrupt_function(execute_data);
        }

        LOGEV(HOOK_TRACE, dd_uhook_log_invocation(log, execute_data, "end", def->end););
        keep_span = dd_uhook_call(def->end, def->tracing, dyn, execute_data, retval);
    }

    if (!GC_DELREF(dyn->args)) {
        zend_array_destroy(dyn->args);
    }

    if (def->tracing && !dyn->dropped_span) {
        ddtrace_clear_execute_data_span(invocation, keep_span);
    }

    def->active = false;
}

static void dd_uhook_dtor(void *data) {
    dd_uhook_def *def = data;
    if (def->begin) {
        OBJ_RELEASE(def->begin);
    }
    if (def->end) {
        OBJ_RELEASE(def->end);
    }
    efree(def);
}

static bool _parse_config_array(zval *config_array, zval **prehook, zval **posthook, bool *run_when_limited, bool *allow_recursion) {
    if (Z_TYPE_P(config_array) != IS_ARRAY) {
        LOG_LINE_ONCE(WARN, "Expected config_array to be an associative array");
        return false;
    }

    zval *value;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(Z_ARRVAL_P(config_array), key, value) {
        if (!key) {
            LOG_LINE_ONCE(WARN, "Expected config_array to be an associative array");
            return false;
        }
        // TODO Optimize this
        if (strcmp("posthook", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_OBJECT && instanceof_function(Z_OBJCE_P(value), zend_ce_closure)) {
                *posthook = value;
            } else {
                LOG_LINE_ONCE(WARN, "Expected '%s' to be an instance of Closure", ZSTR_VAL(key));
                return false;
            }
        } else if (strcmp("prehook", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_OBJECT && instanceof_function(Z_OBJCE_P(value), zend_ce_closure)) {
                *prehook = value;
            } else {
                LOG_LINE_ONCE(WARN, "Expected '%s' to be an instance of Closure", ZSTR_VAL(key));
                return false;
            }
        } else if (strcmp("instrument_when_limited", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_LONG) {
                if (Z_LVAL_P(value)) {
                    *run_when_limited = true;
                }
            } else {
                LOG_LINE_ONCE(WARN, "Expected '%s' to be an int", ZSTR_VAL(key));
                return false;
            }
        } else if (strcmp("recurse", ZSTR_VAL(key)) == 0) {
            *allow_recursion = zval_is_true(value);
        } else {
            LOG_LINE_ONCE(WARN, "Unknown option '%s' in config_array", ZSTR_VAL(key));
            return false;
        }
    }
    ZEND_HASH_FOREACH_END();
    return true;
}

static void dd_uhook(INTERNAL_FUNCTION_PARAMETERS, bool tracing, bool method) {
    zend_string *class_name = NULL, *method_name = NULL;
    zval *prehook = NULL, *posthook = NULL, *config_array = NULL;
    bool run_when_limited = false, allow_recursion = false;

    ZEND_PARSE_PARAMETERS_START(1 + method, 2 + method + !tracing)
        // clang-format off
        if (method) {
            Z_PARAM_STR(class_name)
        }
        Z_PARAM_STR(method_name)
        Z_PARAM_OPTIONAL

        if (ZEND_NUM_ARGS() <= (uint32_t)(1 + method)) {
            break;
        }

        zval *_current_arg = _arg + 1;
        if (Z_TYPE_P(_current_arg) == IS_ARRAY) {
            Z_PARAM_ARRAY(config_array)
        } else if (!tracing) {
            Z_PARAM_OBJECT_OF_CLASS_EX(prehook, zend_ce_closure, 1, 0)
        }
        Z_PARAM_OBJECT_OF_CLASS_EX(posthook, zend_ce_closure, 1, 0) // Will get overwritten if config_array is set
        // clang-format on
    ZEND_PARSE_PARAMETERS_END();

    if (config_array) {
        if (_parse_config_array(config_array, &prehook, &posthook, &run_when_limited, &allow_recursion) == false) {
            RETURN_FALSE;
        }
    }

    if (!prehook && !posthook) {
        LOG_LINE_ONCE(WARN, "DDTrace\\%s_%s was given neither prehook nor posthook", tracing ? "trace" : "hook", method ? "method" : "function");
        RETURN_FALSE;
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_FALSE;
    }

    dd_uhook_def *def = emalloc(sizeof(*def));
    def->begin = prehook ? Z_OBJ_P(prehook) : NULL;
    if (def->begin) {
        GC_ADDREF(def->begin);
    }
    def->end = posthook ? Z_OBJ_P(posthook) : NULL;
    if (def->end) {
        GC_ADDREF(def->end);
    }
    def->tracing = tracing;
    def->run_if_limited = !tracing || run_when_limited;
    def->active = false;
    def->allow_recursion = allow_recursion;

    zai_str class_str = ZAI_STR_EMPTY;
    if (method) {
        class_str = (zai_str)ZAI_STR_FROM_ZSTR(class_name);
    }
    zai_str func_str = ZAI_STR_FROM_ZSTR(method_name);

    uint32_t hook_limit = get_DD_TRACE_HOOK_LIMIT();
    if (hook_limit > 0 && zai_hook_count_installed(class_str, func_str) >= hook_limit) {
        LOG_LINE_ONCE(ERROR,
                "Could not add hook to %s%s%s with more than datadog.trace.hook_limit = %d installed hooks",
                method ? ZSTR_VAL(class_name) : "",
                method ? "::" : "",
                ZSTR_VAL(method_name),
                hook_limit);

        dd_uhook_dtor(def);
        RETURN_FALSE;
    }

    bool success = zai_hook_install_generator(class_str, func_str,
            dd_uhook_begin, dd_uhook_generator_resumption, dd_uhook_generator_yield, dd_uhook_end,
            ZAI_HOOK_AUX(def, dd_uhook_dtor),sizeof(dd_uhook_dynamic)) != -1;

    if (!success) {
        dd_uhook_dtor(def);
    } else {
        LOG(HOOK_TRACE, "Installing a hook function at %s:%d on %s %s%s%s",
            zend_get_executed_filename(), zend_get_executed_lineno(),
            method ? "method" : "function",
            method ? ZSTR_VAL(class_name) : "",
            method ? "::" : "",
            ZSTR_VAL(method_name));
    }
    RETURN_BOOL(success);
}

PHP_FUNCTION(DDTrace_hook_function) { dd_uhook(INTERNAL_FUNCTION_PARAM_PASSTHRU, false, false); }
PHP_FUNCTION(DDTrace_trace_function) { dd_uhook(INTERNAL_FUNCTION_PARAM_PASSTHRU, true, false); }
PHP_FUNCTION(DDTrace_hook_method) { dd_uhook(INTERNAL_FUNCTION_PARAM_PASSTHRU, false, true); }
PHP_FUNCTION(DDTrace_trace_method) { dd_uhook(INTERNAL_FUNCTION_PARAM_PASSTHRU, true, true); }

PHP_FUNCTION(dd_untrace) {
    zend_string *class_name = NULL, *method_name = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(method_name)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR(class_name)
    ZEND_PARSE_PARAMETERS_END();

    zai_str class_str = ZAI_STR_EMPTY;
    if (class_name) {
        class_str = (zai_str)ZAI_STR_FROM_ZSTR(class_name);
    }
    zai_str func_str = ZAI_STR_FROM_ZSTR(method_name);

    zai_hook_iterator it;
    for (it = zai_hook_iterate_installed(class_str, func_str); it.active; zai_hook_iterator_advance(&it)) {
        if (*it.begin == dd_uhook_begin) {
            dd_uhook_def *def = it.aux->data;
            if (def->end) {
                OBJ_RELEASE(def->end);
                def->end = NULL;
            }
            it.aux->data = def;
            zai_hook_remove(class_str, func_str, it.index);
        }
    }
    zai_hook_iterator_free(&it);

    LOG(HOOK_TRACE, "Removing all hook functions installed by hook&trace_%s at %s:%d on %s %s%s%s",
        class_name ? "method" : "function",
        zend_get_executed_filename(), zend_get_executed_lineno(),
        class_name ? "method" : "function",
        class_name ? ZSTR_VAL(class_name) : "",
        class_name ? "::" : "",
        ZSTR_VAL(method_name));

    RETURN_TRUE;
}
