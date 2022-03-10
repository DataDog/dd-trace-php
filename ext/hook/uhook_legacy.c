#include <php.h>
#include <zend_closures.h>
#include <hook/hook.h>
#include "uhook.h"

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
} dd_uhook_def;

typedef struct {
    zend_array *args;
    ddtrace_span_fci *span;
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

static void dd_uhook_call(zend_object *closure, bool tracing, dd_uhook_dynamic *dyn, zval *This, zval *retval) {
    bool has_this = Z_TYPE_P(This) == IS_OBJECT;
    zval rv = {0}, *rvp = &rv;
    zval closure_zv, args_zv, exception_zv;
    ZVAL_OBJ(&closure_zv, closure);
    ZVAL_ARR(&args_zv, dyn->args);
    if (EG(exception)) {
        ZVAL_OBJ(&exception_zv, EG(exception));
    } else {
        ZVAL_NULL(&exception_zv);
    }
    if (tracing) {
        zval span_zv;
        ZVAL_OBJ(&span_zv, &dyn->span->span.std);
        zval *span_zvp = &span_zv, *args_zvp = &args_zv, *retvalp = retval, *exception_zvp = &exception_zv;
        zai_symbol_call(has_this ? ZAI_SYMBOL_SCOPE_OBJECT : ZAI_SYMBOL_SCOPE_GLOBAL, has_this ? This : NULL,
                        ZAI_SYMBOL_FUNCTION_CLOSURE, &closure_zv, &rvp, 4, &span_zvp, &args_zvp, &retvalp, &exception_zvp);
    } else {
        zval *args_zvp = &args_zv, *retvalp = retval, *exception_zvp = &exception_zv;
        zai_symbol_call(has_this ? ZAI_SYMBOL_SCOPE_OBJECT : ZAI_SYMBOL_SCOPE_GLOBAL, has_this ? This : NULL,
                        ZAI_SYMBOL_FUNCTION_CLOSURE, &closure_zv, &rvp, 3, &args_zvp, &retvalp, &exception_zvp);
    }
    zval_ptr_dtor(rvp);

}

static bool dd_uhook_begin(zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (!def->run_if_limited && ddtrace_tracer_is_limited()) {
        return true;
    }

    dyn->args = dd_uhook_collect_args(execute_data);

    if (def->tracing) {
        ddtrace_span_fci *span_fci = ddtrace_init_span();
        ddtrace_open_span(span_fci);

        // SpanData::$name defaults to fully qualified called name
        zval *prop_name = ddtrace_spandata_property_name(&span_fci->span);
        dd_set_fqn(prop_name, execute_data);

        dyn->span = span_fci;
    }

    if (def->begin) {
        dd_uhook_call(def->begin, def->tracing, dyn, &EX(This), &EG(uninitialized_zval));
    }

    return true;
}

static void dd_uhook_end(zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (!def->run_if_limited && ddtrace_tracer_is_limited()) {
        return;
    }

    if (def->end) {
        /* If the profiler doesn't handle a potential pending interrupt before
         * the observer's end function, then the callback will be at the top of
         * the stack even though it's not responsible.
         * This is why the profiler's interrupt function is called here, to
         * give the profiler an opportunity to take a sample before calling the
         * tracing funcation.
         */
        if (profiling_interrupt_function) {
            profiling_interrupt_function(execute_data);
        }

        dd_uhook_call(def->end, def->tracing, dyn, &EX(This), retval);
    }

    if (!GC_DELREF(dyn->args)) {
        zend_array_destroy(dyn->args);
    }

    if (def->tracing) {
        ddtrace_close_span(dyn->span);
    }
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

static void dd_uhook_function(INTERNAL_FUNCTION_PARAMETERS, bool tracing) {
    zend_string *function_name = NULL;
    zval *prehook = NULL, *posthook = NULL, *config_array = NULL;
    bool run_when_limited = false;

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 1, tracing ? 2 : 3)
    // clang-format off
        Z_PARAM_STR(function_name)
        Z_PARAM_OPTIONAL
        if (!tracing) {
            Z_PARAM_OBJECT_OF_CLASS_EX(prehook, zend_ce_closure, 1, 0)
        }
        Z_PARAM_OBJECT_OF_CLASS_EX(posthook, zend_ce_closure, 1, 0)
    // clang-format on
    ZEND_PARSE_PARAMETERS_END_EX({
        ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 2, 2)
            // clang-format off
            Z_PARAM_STR(function_name)
            Z_PARAM_ARRAY(config_array)
        ZEND_PARSE_PARAMETERS_END_EX({
            ddtrace_log_debugf(
                    "Unable to parse parameters for DDTrace\\%d; expected "
                    "(string $function_name, ?Closure $prehook = NULL, ?Closure $posthook = NULL)",
                    tracing ? "trace_method" : "hook_method");
        });
    });

    if (config_array) {
        if (_parse_config_array(config_array, &prehook, &posthook, &run_when_limited) == false) {
            RETURN_FALSE;
        }
    }

    if (!prehook && !posthook) {
        ddtrace_log_debugf("DDTrace\\%s was given neither prehook nor posthook.", tracing ? "trace_method" : "hook_method");
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
    def->run_if_limited = run_when_limited;

    RETURN_BOOL(zai_hook_install(
            ZAI_HOOK_INTERNAL,
            ZAI_STRING_EMPTY, (zai_string_view){ .ptr = ZSTR_VAL(function_name), .len = ZSTR_LEN(function_name) },
            ZAI_HOOK_BEGIN_INTERNAL(dd_uhook_begin),
            ZAI_HOOK_END_INTERNAL(dd_uhook_end),
            ZAI_HOOK_AUX_INTERNAL(def, dd_uhook_dtor),
            sizeof(dd_uhook_dynamic)));

}

PHP_FUNCTION(hook_function) { dd_uhook_function(INTERNAL_FUNCTION_PARAM_PASSTHRU, false); }
PHP_FUNCTION(trace_function) { dd_uhook_function(INTERNAL_FUNCTION_PARAM_PASSTHRU, true); }

static void dd_uhook_method(INTERNAL_FUNCTION_PARAMETERS, bool tracing) {
    zend_string *class_name = NULL, *method_name = NULL;
    zval *prehook = NULL, *posthook = NULL, *config_array = NULL;
    bool run_when_limited = false;

    ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 2, tracing ? 3 : 4)
        // clang-format off
        Z_PARAM_STR(class_name)
        Z_PARAM_STR(method_name)
        Z_PARAM_OPTIONAL
        if (!tracing) {
            Z_PARAM_OBJECT_OF_CLASS_EX(prehook, zend_ce_closure, 1, 0)
        }
        Z_PARAM_OBJECT_OF_CLASS_EX(posthook, zend_ce_closure, 1, 0)
        // clang-format on
    ZEND_PARSE_PARAMETERS_END_EX({
        ZEND_PARSE_PARAMETERS_START_EX(ZEND_PARSE_PARAMS_QUIET, 3, 3)
            // clang-format off
            Z_PARAM_STR(class_name)
            Z_PARAM_STR(method_name)
            Z_PARAM_ARRAY(config_array)
        ZEND_PARSE_PARAMETERS_END_EX({
            ddtrace_log_debugf(
                "Unable to parse parameters for DDTrace\\%s; expected "
                "(string $class_name, string $method_name, ?Closure $prehook = NULL, ?Closure $posthook = NULL)",
                tracing ? "trace_method" : "hook_method");
        });
    });

    if (config_array) {
        if (_parse_config_array(config_array, &prehook, &posthook, &run_when_limited) == false) {
            RETURN_FALSE;
        }
    }

    if (!prehook && !posthook) {
        ddtrace_log_debugf("DDTrace\\%s was given neither prehook nor posthook.", tracing ? "trace_method" : "hook_method");
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
    def->run_if_limited = run_when_limited;

    RETURN_BOOL(zai_hook_install(
            ZAI_HOOK_INTERNAL,
            (zai_string_view){ .ptr = ZSTR_VAL(class_name), .len = ZSTR_LEN(class_name) },
            (zai_string_view){ .ptr = ZSTR_VAL(method_name), .len = ZSTR_LEN(method_name) },
            ZAI_HOOK_BEGIN_INTERNAL(dd_uhook_begin),
            ZAI_HOOK_END_INTERNAL(dd_uhook_end),
            ZAI_HOOK_AUX_INTERNAL(def, dd_uhook_dtor),
            sizeof(dd_uhook_dynamic)));

}

PHP_FUNCTION(hook_method) { dd_uhook_method(INTERNAL_FUNCTION_PARAM_PASSTHRU, false); }
PHP_FUNCTION(trace_method) { dd_uhook_method(INTERNAL_FUNCTION_PARAM_PASSTHRU, true); }
