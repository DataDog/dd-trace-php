#include <php.h>
#include <zend_closures.h>
#include <hook/hook.h>
#include "uhook.h"
#include <exceptions/exceptions.h>
#include <sandbox/sandbox.h>

#if PHP_VERSION_ID < 80000
#include "../php7/logging.h"
#include "../php7/span.h"
#else
#include "../php8/logging.h"
#include "../php8/span.h"
#endif

extern void (*profiling_interrupt_function)(zend_execute_data *);

typedef struct {
    zend_object *begin;
    zend_object *end;
    bool tracing;
    bool run_if_limited;
    bool active;
} dd_uhook_def;

typedef struct {
    zend_array *args;
    ddtrace_span_fci *span;
    bool skipped;
    bool dropped_span;
    bool was_primed;
} dd_uhook_dynamic;

void dd_set_fqn(zval *zv, zend_execute_data *execute_data) {
    if (!EX(func) || !EX(func)->common.function_name) {
        return;
    }

    zval_ptr_dtor(zv);

    zend_class_entry *called_scope = EX(func)->common.scope ? zend_get_called_scope(execute_data) : NULL;
    if (called_scope) {
        // This cannot be cached on the dispatch since sub classes can share the same parent dispatch
        ZVAL_STR(zv, strpprintf(0, "%s.%s", ZSTR_VAL(called_scope->name), ZSTR_VAL(EX(func)->common.function_name)));
    } else {
        ZVAL_STR_COPY(zv, EX(func)->common.function_name);
    }
}

static bool dd_uhook_call(zend_object *closure, bool tracing, dd_uhook_dynamic *dyn, zend_execute_data *execute_data, zval *retval) {
    zval rv = {0}, *rvp = &rv;
    zval closure_zv, args_zv, exception_zv;
    ZVAL_OBJ(&closure_zv, closure);
    ZVAL_ARR(&args_zv, dyn->args);
    if (EG(exception)) {
        ZVAL_OBJ(&exception_zv, EG(exception));
    } else {
        ZVAL_NULL(&exception_zv);
    }
    zai_sandbox sandbox;
    bool success;
    if (tracing) {
        zval span_zv;
        ZVAL_OBJ(&span_zv, &dyn->span->span.std);
        zval *span_zvp = &span_zv, *args_zvp = &args_zv, *retvalp = retval, *exception_zvp = &exception_zv;
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
                        ZAI_SYMBOL_FUNCTION_CLOSURE, &closure_zv, &rvp, 4 | ZAI_SYMBOL_SANDBOX, &sandbox, &span_zvp, &args_zvp, &retvalp, &exception_zvp);
    } else {
        zval *args_zvp = &args_zv, *retvalp = retval, *exception_zvp = &exception_zv;
        zval *Thisp = getThis() ? &EX(This) : &EG(uninitialized_zval);
        if (EX(func)->common.scope) {
            zval scope, *scopep = &scope;
            ZVAL_NULL(&scope);
            zend_class_entry *scope_ce = zend_get_called_scope(execute_data);
            if (scope_ce) {
                ZVAL_STR(&scope, scope_ce->name);
            }
            success = zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_CLOSURE, &closure_zv, &rvp, 5 | ZAI_SYMBOL_SANDBOX, &sandbox, &Thisp, &scopep, &args_zvp, &retvalp, &exception_zvp);
        } else {
            success = zai_symbol_call(ZAI_SYMBOL_SCOPE_GLOBAL, NULL, ZAI_SYMBOL_FUNCTION_CLOSURE, &closure_zv, &rvp, 3 | ZAI_SYMBOL_SANDBOX, &sandbox, &args_zvp, &retvalp, &exception_zvp);
        }
    }

    if (get_DD_TRACE_DEBUG() && (!success || (PG(last_error_message) && sandbox.error_state.message != PG(last_error_message)))) {
        char *scope = "";
        char *colon = "";
        char *name = "(unknown function)";
        if (execute_data->func && execute_data->func->common.function_name) {
            zend_function *fbc = execute_data->func;
            name = ZSTR_VAL(fbc->common.function_name);
            if (fbc->common.scope) {
                scope = ZSTR_VAL(fbc->common.scope->name);
                colon = "::";
            }
        }

        char *deffile;
        int defline = 0;
#if PHP_VERSION_ID < 80000
        zval closure_zv;
        ZVAL_OBJ(&closure_zv, closure);
        const zend_function *func = zend_get_closure_method_def(&closure_zv);
#else
        const zend_function *func = zend_get_closure_method_def(closure);
#endif
        if (func->type == ZEND_USER_FUNCTION) {
            deffile = ZSTR_VAL(func->op_array.filename);
            defline = (int) func->op_array.opcodes[0].lineno;
        } else {
            deffile = ZSTR_VAL(func->op_array.function_name);
        }

        zend_object *ex = EG(exception);
        if (ex) {
            const char *type = ZSTR_VAL(ex->ce->name);
            zend_string *msg = zai_exception_message(ex);
            ddtrace_log_errf("%s thrown in ddtrace's closure defined at %s:%d for %s%s%s(): %s",
                             type, deffile, defline, scope, colon, name, ZSTR_VAL(msg));
        } else if (PG(last_error_message) && sandbox.error_state.message != PG(last_error_message)) {
#if PHP_VERSION_ID < 80000
            char *error = PG(last_error_message);
#else
            char *error = ZSTR_VAL(PG(last_error_message));
#endif
#if PHP_VERSION_ID < 80100
            char *filename = PG(last_error_file);
#else
            char *filename = ZSTR_VAL(PG(last_error_file));
#endif
            ddtrace_log_errf("Error raised in ddtrace's closure defined at %s:%d for %s%s%s(): %s in %s on line %d",
                             deffile, defline, scope, colon, name, error, filename, PG(last_error_lineno));
        }
    }
    zai_sandbox_close(&sandbox);

    zval_ptr_dtor(rvp);

    return Z_TYPE(rv) != IS_FALSE;
}

static bool dd_uhook_begin(zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if ((!def->run_if_limited && ddtrace_tracer_is_limited()) || def->active) {
        dyn->skipped = true;
        return true;
    }

    def->active = true; // recursion protection
    dyn->skipped = false;
    dyn->was_primed = false;
    dyn->dropped_span = false;
    dyn->args = dd_uhook_collect_args(execute_data);

    if (def->tracing) {
        ddtrace_span_fci *span_fci = ddtrace_init_span(DDTRACE_INTERNAL_SPAN);
        ddtrace_open_span(span_fci);

        // SpanData::$name defaults to fully qualified called name
        zval *prop_name = ddtrace_spandata_property_name(&span_fci->span);
        dd_set_fqn(prop_name, execute_data);

        dyn->span = span_fci;
        GC_ADDREF(&span_fci->span.std);
    }

    if (def->begin) {
        dyn->dropped_span = !dd_uhook_call(def->begin, def->tracing, dyn, execute_data, &EG(uninitialized_zval));
        if (def->tracing && dyn->dropped_span) {
            ddtrace_drop_top_open_span();
            OBJ_RELEASE(&dyn->span->span.std);
        }
    }

    return true;
}

// create an own span for every generator resumption
static void dd_uhook_generator_resumption(zend_execute_data *execute_data, zval *value, void *auxiliary, void *dynamic) {
    (void)value;

    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (dyn->skipped || !dyn->was_primed) {
        dyn->was_primed = true;
        return;
    }

    if (def->tracing) {
        ddtrace_span_fci *span_fci = ddtrace_init_span(DDTRACE_INTERNAL_SPAN);
        ddtrace_open_span(span_fci);

        // SpanData::$name defaults to fully qualified called name
        zval *prop_name = ddtrace_spandata_property_name(&span_fci->span);
        dd_set_fqn(prop_name, execute_data);

        dyn->span = span_fci;
        GC_ADDREF(&span_fci->span.std);
        dyn->dropped_span = false;
    }

    if (def->begin) {
        dyn->dropped_span = !dd_uhook_call(def->begin, def->tracing, dyn, execute_data, value);
        if (def->tracing && dyn->dropped_span) {
            ddtrace_drop_top_open_span();
            OBJ_RELEASE(&dyn->span->span.std);
        }
    }
}

static void dd_uhook_generator_yield(zend_execute_data *execute_data, zval *key, zval *value, void *auxiliary, void *dynamic) {
    (void)key;

    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (dyn->skipped) {
        return;
    }

    if (def->tracing && !dyn->dropped_span) {
        if (dyn->span->span.duration == -1ull) {
            dyn->dropped_span = true;
            OBJ_RELEASE(&dyn->span->span.std);

            ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync", ZSTR_VAL(EX(func)->common.function_name));
        } else {
            zval *exception_zv = ddtrace_spandata_property_exception(&dyn->span->span);
            if (EG(exception) && Z_TYPE_P(exception_zv) <= IS_FALSE) {
                ZVAL_OBJ_COPY(exception_zv, EG(exception));
            }

            dd_trace_stop_span_time(&dyn->span->span);
        }
    }

    if (def->end && (!def->tracing || !dyn->dropped_span)) {
        bool keep_span = dd_uhook_call(def->end, def->tracing, dyn, execute_data, value);
        if (def->tracing && !dyn->dropped_span) {
            if (keep_span) {
                ddtrace_close_span(dyn->span);
            } else {
                ddtrace_drop_top_open_span();
            }
            OBJ_RELEASE(&dyn->span->span.std);
        }
        dyn->dropped_span = true;
    }
}

static void dd_uhook_end(zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;
    bool keep_span = true;

    if (dyn->skipped) {
        return;
    }

    if (def->tracing && !dyn->dropped_span) {
        if (dyn->span->span.duration == -1ull) {
            dyn->dropped_span = true;
            OBJ_RELEASE(&dyn->span->span.std);

            ddtrace_log_errf("Cannot run tracing closure for %s(); spans out of sync", ZSTR_VAL(EX(func)->common.function_name));
        } else {
            zval *exception_zv = ddtrace_spandata_property_exception(&dyn->span->span);
            if (EG(exception) && Z_TYPE_P(exception_zv) <= IS_FALSE) {
                ZVAL_OBJ_COPY(exception_zv, EG(exception));
            }

            dd_trace_stop_span_time(&dyn->span->span);
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

        keep_span = dd_uhook_call(def->end, def->tracing, dyn, execute_data, retval);
    }

    if (!GC_DELREF(dyn->args)) {
        zend_array_destroy(dyn->args);
    }

    if (def->tracing && !dyn->dropped_span) {
        if (keep_span) {
            ddtrace_close_span(dyn->span);
        } else {
            ddtrace_drop_top_open_span();
        }
        OBJ_RELEASE(&dyn->span->span.std);
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

static bool _parse_config_array(zval *config_array, zval **prehook, zval **posthook, bool *run_when_limited) {
    if (Z_TYPE_P(config_array) != IS_ARRAY) {
        ddtrace_log_debug("Expected config_array to be an associative array");
        return false;
    }

    zval *value;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(Z_ARRVAL_P(config_array), key, value) {
        if (!key) {
            ddtrace_log_debug("Expected config_array to be an associative array");
            return false;
        }
        // TODO Optimize this
        if (strcmp("posthook", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_OBJECT && instanceof_function(Z_OBJCE_P(value), zend_ce_closure)) {
                *posthook = value;
            } else {
                ddtrace_log_debugf("Expected '%s' to be an instance of Closure", ZSTR_VAL(key));
                return false;
            }
        } else if (strcmp("prehook", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_OBJECT && instanceof_function(Z_OBJCE_P(value), zend_ce_closure)) {
                *prehook = value;
            } else {
                ddtrace_log_debugf("Expected '%s' to be an instance of Closure", ZSTR_VAL(key));
                return false;
            }
        } else if (strcmp("instrument_when_limited", ZSTR_VAL(key)) == 0) {
            if (Z_TYPE_P(value) == IS_LONG) {
                if (Z_LVAL_P(value)) {
                    *run_when_limited = true;
                }
            } else {
                ddtrace_log_debugf("Expected '%s' to be an int", ZSTR_VAL(key));
                return false;
            }
        } else {
            ddtrace_log_debugf("Unknown option '%s' in config_array", ZSTR_VAL(key));
            return false;
        }
    }
    ZEND_HASH_FOREACH_END();
    return true;
}

static void dd_uhook(INTERNAL_FUNCTION_PARAMETERS, bool tracing, bool method) {
    zend_string *class_name = NULL, *method_name = NULL;
    zval *prehook = NULL, *posthook = NULL, *config_array = NULL;
    bool run_when_limited = false;

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 1 + method, 2 + method + !tracing)
        // clang-format off
        if (method) {
            Z_PARAM_STR(class_name)
        }
        Z_PARAM_STR(method_name)
        Z_PARAM_OPTIONAL
        if (!tracing) {
            Z_PARAM_OBJECT_OF_CLASS_EX(prehook, zend_ce_closure, 1, 0)
        }
        Z_PARAM_OBJECT_OF_CLASS_EX(posthook, zend_ce_closure, 1, 0)
        // clang-format on
    ZEND_PARSE_PARAMETERS_END_EX({
        ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 2 + method, 2 + method)
            // clang-format off
            if (method) {
                Z_PARAM_STR(class_name)
            }
            Z_PARAM_STR(method_name)
            Z_PARAM_ARRAY(config_array)
        ZEND_PARSE_PARAMETERS_END_EX({
            ddtrace_log_debugf(
                "Unable to parse parameters for DDTrace\\%s_%s; expected "
                "(string $class_name, string $method_name, ?Closure $prehook = NULL, ?Closure $posthook = NULL)",
                tracing ? "trace" : "hook", method ? "method" : "function");
             RETURN_FALSE;
        });
    });

    if (config_array) {
        if (_parse_config_array(config_array, &prehook, &posthook, &run_when_limited) == false) {
            RETURN_FALSE;
        }
    }

    if (!prehook && !posthook) {
        ddtrace_log_debugf("DDTrace\\%s_%s was given neither prehook nor posthook.", tracing ? "trace" : "hook", method ? "method" : "function");
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

    zai_string_view class_str = method ? ZAI_STRING_FROM_ZSTR(class_name) : ZAI_STRING_EMPTY;
    zai_string_view func_str = ZAI_STRING_FROM_ZSTR(method_name);

    if (tracing) {
        zai_hook_iterator it;
        for (it = zai_hook_iterate_installed(class_str, func_str); it.active; zai_hook_iterator_advance(&it)) {
            if (*it.begin == dd_uhook_begin) {
                dd_uhook_def *cur = it.aux->data;
                if (cur->tracing) {
                    dd_uhook_dtor(cur);
                    it.aux->data = def;
                    zai_hook_iterator_free(&it);
                    RETURN_TRUE;
                }
            }
        }
        zai_hook_iterator_free(&it);
    }

    RETURN_BOOL(zai_hook_install_generator(
            class_str,
            func_str,
            dd_uhook_begin,
            dd_uhook_generator_resumption,
            dd_uhook_generator_yield,
            dd_uhook_end,
            ZAI_HOOK_AUX(def, dd_uhook_dtor),
            sizeof(dd_uhook_dynamic)) != -1);
}

PHP_FUNCTION(hook_function) { dd_uhook(INTERNAL_FUNCTION_PARAM_PASSTHRU, false, false); }
PHP_FUNCTION(trace_function) { dd_uhook(INTERNAL_FUNCTION_PARAM_PASSTHRU, true, false); }
PHP_FUNCTION(hook_method) { dd_uhook(INTERNAL_FUNCTION_PARAM_PASSTHRU, false, true); }
PHP_FUNCTION(trace_method) { dd_uhook(INTERNAL_FUNCTION_PARAM_PASSTHRU, true, true); }

PHP_FUNCTION(dd_untrace) {
    zend_string *class_name = NULL, *method_name = NULL;

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 1, 2)
        Z_PARAM_STR(method_name)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR(class_name)
    ZEND_PARSE_PARAMETERS_END_EX({
         ddtrace_log_debug("unexpected parameter for dd_untrace. the function name must be provided");
         RETURN_FALSE;
    });

    zai_string_view class_str = class_name ? ZAI_STRING_FROM_ZSTR(class_name) : ZAI_STRING_EMPTY;
    zai_string_view func_str = ZAI_STRING_FROM_ZSTR(method_name);

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

    RETURN_TRUE;
}
