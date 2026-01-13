#include "../ddtrace.h"
#include <php.h>

#include <zend_closures.h>
#include <zend_exceptions.h>
#include <zend_generators.h>
#include <zend_vm.h>

#include "../compatibility.h"
#include "../configuration.h"
#include "../telemetry.h"
#include <components/log/log.h>

#define HOOK_INSTANCE 0x1

#include "uhook_arginfo.h"

#include <hook/hook.h>

#include "uhook.h"
#include "compat_string.h"
#include <jit_utils/jit_blacklist.h>
#include <exceptions/exceptions.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

extern void (*profiling_interrupt_function)(zend_execute_data *);

zend_class_entry *ddtrace_hook_data_ce;
#if PHP_VERSION_ID >= 80000
zend_property_info *ddtrace_hook_data_returned_prop_info;
#endif

typedef struct {
    size_t size;
    zend_long id[];
} dd_closure_list;

typedef struct {
    dd_uhook_callback begin;
    dd_uhook_callback end;
    bool running;
    zend_long id;

    zend_ulong install_address;
    zend_string *scope;
    zend_string *function;
    zend_string *file;
    zend_object *closure;
} dd_uhook_def;

typedef struct {
    zend_object std;
    // first property is $data
    zval property_id;
    zval property_args;
    zval property_returned;
    zval property_exception;
    zval property_object;
    zend_ulong invocation;
    zend_execute_data *execute_data;
    zval *vm_stack_top;
    zval *retval_ptr;
    zend_object *exception_override;
    ddtrace_span_data *span;
    ddtrace_span_stack *prior_stack;
    bool *running_ptr;
    bool returns_reference;
    bool suppress_call;
    bool dis_jit_inlining_called;
} dd_hook_data;

#define EXCEPTION_OVERRIDE_CLEAR ((zend_object *)0x1)

typedef struct {
    dd_hook_data *hook_data;
} dd_uhook_dynamic;

#if PHP_VERSION_ID < 70400
#define ZEND_MAP_PTR(x) x
#endif

// Only called on first call or scope change
void dd_uhook_callback_apply_scope(dd_uhook_callback *cb, zend_class_entry *scope) {
    if (!cb->fcc.function_handler) {
        zend_function *func = (zend_function *) zend_get_closure_method_def(cb->closure);
        cb->is_static = !scope || (func->common.fn_flags & ZEND_ACC_STATIC);
        if (!cb->is_static) {
            memcpy(&cb->func, func, sizeof(zend_function));
            int cache_size = func->op_array.cache_size;
            func = &cb->func;
            func->op_array.fn_flags &= ~ZEND_ACC_CLOSURE; // Otherwise we run into ZEND_CLOSURE_OBJECT() out-of-bounds reads
            if (cache_size) {
                func->op_array.fn_flags |= ZEND_ACC_HEAP_RT_CACHE;
                ZEND_MAP_PTR(cb->func.op_array.run_time_cache) = emalloc(cache_size);
            }
        }
        cb->fcc.function_handler = func;
#if PHP_VERSION_ID < 70300
        cb->fcc.initialized = 1;
#endif
        if (cb->is_static) {
            cb->fcc.called_scope = func->common.scope;
            return;
        }
    }
    int cache_size = cb->func.op_array.cache_size;
    cb->func.common.scope = scope;
    cb->fcc.called_scope = scope;
    if (cache_size) {
        memset(ZEND_MAP_PTR(cb->func.op_array.run_time_cache), 0, cache_size);
    }
}

#if PHP_VERSION_ID < 70400
#undef ZEND_MAP_PTR
#endif


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
#if PHP_VERSION_ID < 80300
    ht->nNumOfElements = num_args;
#else
    ht->nTableSize = num_args;
#endif

    zend_hash_real_init_packed(ht);
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

#if PHP_VERSION_ID < 80000
#define LAST_ERROR_STRING PG(last_error_message)
#else
#define LAST_ERROR_STRING ZSTR_VAL(PG(last_error_message))
#endif
#if PHP_VERSION_ID < 80100
#define LAST_ERROR_FILE PG(last_error_file)
#else
#define LAST_ERROR_FILE ZSTR_VAL(PG(last_error_file))
#endif

void dd_uhook_report_sandbox_error(zend_execute_data *execute_data, zend_object *closure) {
    LOGEV(WARN, {
        char *scope = "";
        char *colon = "";
        char *name = "(unknown function)";
        if (execute_data && execute_data->func && execute_data->func->common.function_name) {
            zend_function *fbc = execute_data->func;
            name = ZSTR_VAL(fbc->common.function_name);
            if (fbc->common.scope) {
                scope = ZSTR_VAL(fbc->common.scope->name);
                colon = "::";
            }
        }

        char *deffile;
        int defline = 0;
        const zend_function *func = zend_get_closure_method_def(closure);
        if (func->type == ZEND_USER_FUNCTION) {
            deffile = ZSTR_VAL(func->op_array.filename);
            defline = (int) func->op_array.opcodes[0].lineno;
        } else {
            deffile = ZSTR_VAL(func->op_array.function_name);
        }

        zend_object *ex = EG(exception);
        if (ex) {
            bool regular_exception = instanceof_function(ex->ce, zend_ce_throwable);
            const char *type = ZSTR_VAL(ex->ce->name);
            const char *msg = regular_exception ? ZSTR_VAL(zai_exception_message(ex)): "<exit>";
            zend_long exline = regular_exception ? zval_get_long(zai_exception_read_property(ex, ZSTR_KNOWN(ZEND_STR_LINE))) : 0;
            zend_string *exfile = regular_exception ? ddtrace_convert_to_str(zai_exception_read_property(ex, ZSTR_KNOWN(ZEND_STR_FILE))) : NULL;
            log("%s thrown in ddtrace's closure defined at %s:%d for %s%s%s(): %s in %s on line %d",
                             type, deffile, defline, scope, colon, name, msg, regular_exception ? ZSTR_VAL(exfile) : "Unknown", exline);
            if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED() && get_DD_TELEMETRY_LOG_COLLECTION_ENABLED()) {
                INTEGRATION_ERROR_TELEMETRY(ERROR, "%s thrown in ddtrace's closure defined at <redacted>%s:%d for %s%s%s(): $ERROR_MSG in <redacted>%s on line %d",
                             type, ddtrace_telemetry_redact_file(deffile), defline, scope, colon, name, regular_exception ? ddtrace_telemetry_redact_file(ZSTR_VAL(exfile)) : "Unknown", exline);
            }
            if (exfile) {
                zend_string_release(exfile);
            }
        } else if (PG(last_error_message)) {
            log("Error raised in ddtrace's closure defined at %s:%d for %s%s%s(): %s in %s on line %d",
                             deffile, defline, scope, colon, name, LAST_ERROR_STRING, LAST_ERROR_FILE, PG(last_error_lineno));
            if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED() && get_DD_TELEMETRY_LOG_COLLECTION_ENABLED()) {
                INTEGRATION_ERROR_TELEMETRY(ERROR, "Error raised in ddtrace's closure defined at <redacted>%s:%d for %s%s%s(): $ERROR_MSG in <redacted>%s on line %d",
                             ddtrace_telemetry_redact_file(deffile), defline, scope, colon, name, ddtrace_telemetry_redact_file(LAST_ERROR_FILE), PG(last_error_lineno));
            }
        }
    })
}

static bool dd_uhook_call_hook(zend_execute_data *execute_data, dd_uhook_callback *callback, dd_hook_data *hook_data) {
    zval hook_data_zv;
    ZVAL_OBJ(&hook_data_zv, &hook_data->std);

    zval rv;
    zai_sandbox sandbox;
    zai_sandbox_open(&sandbox);
    dd_uhook_callback_ensure_scope(callback, execute_data);
    zend_fcall_info fci = dd_fcall_info(1, &hook_data_zv, &rv);
    bool success = zai_sandbox_call(&sandbox, &fci, &callback->fcc);
    if (!success || PG(last_error_message)) {
        dd_uhook_report_sandbox_error(execute_data, callback->closure);
    }
    zai_sandbox_close(&sandbox);
    zval_ptr_dtor(&rv);
    return Z_TYPE(rv) != IS_FALSE;
}

bool ddtrace_uhook_match_filepath(zend_string *file, zend_string *source) {
    if (ZSTR_LEN(source) == 0) {
        return true; // empty path is wildcard
    }

    if (ZSTR_LEN(source) > ZSTR_LEN(file)) {
        return false;
    }

    if (memcmp(ZSTR_VAL(source), ZSTR_VAL(file) + ZSTR_LEN(file) - ZSTR_LEN(source), ZSTR_LEN(source)) != 0) {
        return false; // suffix doesn't match
    }

    if (ZSTR_LEN(source) == ZSTR_LEN(file)) {
        return true; // it's exact match
    }

    char before_match = ZSTR_VAL(file)[ZSTR_LEN(file) - ZSTR_LEN(source) - 1];
    if (before_match == '\\' || before_match == '/') {
        return true;
    }

    return false;
}

#if PHP_VERSION_ID >= 80000
static void (*orig_zend_interrupt_function)(zend_execute_data *);
ZEND_TLS zend_execute_data *expected_ex;
static void dd_zend_interrupt_function(zend_execute_data *ex)
{
    if (expected_ex) {
        if (ex == expected_ex) {
            ex->opline = ex->func->op_array.opcodes;
        }

        expected_ex = NULL;
    }

    if (orig_zend_interrupt_function) {
        orig_zend_interrupt_function(ex);
    }
}
#endif

void dd_uhook_log_invocation(void (*log)(const char *, ...), zend_execute_data *execute_data, const char *type, zend_object *closure) {
    const zend_function *func = zend_get_closure_method_def(closure);
    log("Running a %s hook function from %s:%d on %s %s%s%s",
        type,
        ZSTR_VAL(func->op_array.filename), func->op_array.line_start,
        ZEND_USER_CODE(EX(func)->type) && EX(func)->op_array.filename ? "file" : (EX(func)->common.scope ? "method" : "function"),
        EX(func)->common.scope ? ZSTR_VAL(EX(func)->common.scope->name) : "",
        EX(func)->common.scope ? "::" : "",
        EX(func)->common.function_name ? ZSTR_VAL(EX(func)->op_array.function_name) : (EX(func)->op_array.filename ? "<unnamed>" : ZSTR_VAL(EX(func)->op_array.filename)));
}

static bool dd_uhook_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (def->file && (!execute_data->func->op_array.filename || !ddtrace_uhook_match_filepath(execute_data->func->op_array.filename, def->file))) {
        dyn->hook_data = NULL;
        return true;
    }

    if ((def->closure && def->closure != ZEND_CLOSURE_OBJECT(EX(func))) || !get_DD_TRACE_ENABLED()) {
        dyn->hook_data = NULL;
        return true;
    }

    dyn->hook_data = (dd_hook_data *)dd_hook_data_create(ddtrace_hook_data_ce);
    dyn->hook_data->returns_reference = execute_data->func->common.fn_flags & ZEND_ACC_RETURN_REFERENCE;
    dyn->hook_data->vm_stack_top = EG(vm_stack_top);
    dyn->hook_data->running_ptr = &def->running;

    dyn->hook_data->invocation = invocation;
    ZVAL_LONG(&dyn->hook_data->property_id, def->id);
    if (def->file) {
        zend_array *filearg = zend_new_array(1);
        zval filezv;
        ZVAL_STR_COPY(&filezv, execute_data->func->op_array.filename);
        zend_hash_index_add_new(filearg, 0, &filezv);
        ZVAL_ARR(&dyn->hook_data->property_args, filearg);
    } else {
        ZVAL_ARR(&dyn->hook_data->property_args, dd_uhook_collect_args(execute_data));
    }
    if (hasThis()) {
        ZVAL_OBJ_COPY(&dyn->hook_data->property_object, Z_OBJ(EX(This)));
    }

    if (def->begin.closure && !def->running) {
        dyn->hook_data->execute_data = execute_data;
        // We support it for PHP 8 for now, given we need this for PHP 8.1+ right now.
        // Bringing it to PHP 7.1-7.4 is possible, but not done yet.
        // Supporting PHP 7.0 seems impossible.
#if PHP_VERSION_ID >= 80000
        if (EX(func)->common.fn_flags & ZEND_ACC_GENERATOR) {
            dyn->hook_data->retval_ptr = EX(return_value);
            ZVAL_COPY(&dyn->hook_data->property_returned, EX(return_value));
        }
#endif

        LOGEV(HOOK_TRACE, dd_uhook_log_invocation(log, execute_data, "begin", def->begin.closure););

        def->running = true;
        dd_uhook_call_hook(execute_data, &def->begin, dyn->hook_data);
        def->running = false;
        dyn->hook_data->retval_ptr = NULL;
    }
    dyn->hook_data->execute_data = NULL;

    if (dyn->hook_data->suppress_call) {
        if (ZEND_USER_CODE(execute_data->func->type)) {
            static struct {
                zend_op zop;
                zval zv;
            } retop = {.zop =
                           {
                               .opcode = ZEND_RETURN,
                               .op1_type = IS_CONST,
#if PHP_VERSION_ID >= 70300
                               .op1 = {.constant = ZEND_MM_ALIGNED_SIZE_EX(sizeof(zend_op), sizeof(zval)) },
#else
                               .op1 = {.constant = 0},
#endif
                               .op2_type = IS_UNUSED,
                           },
                       .zv = {.u1.type_info = IS_NULL}};
            // the race condition doesn't matter
            if (!retop.zop.handler) {
                zend_vm_set_opcode_handler(&retop.zop);
            }
            struct {
                zend_function new_func;
                zend_function *orig_func; } *fs = emalloc(sizeof *fs);
            memcpy(&fs->new_func, execute_data->func, sizeof fs->new_func);
            fs->orig_func = execute_data->func;
            fs->new_func.op_array.last = 1;
            fs->new_func.op_array.opcodes = &retop.zop;
#if ZAI_JIT_BLACKLIST_ACTIVE
            int zf_rid = zai_get_zend_func_rid(&execute_data->func->op_array);
            if (zf_rid >= 0) {
                fs->new_func.op_array.reserved[zf_rid] = 0;
            }
#endif
            execute_data->func = &fs->new_func;
#if PHP_VERSION_ID < 70300
            execute_data->literals = &retop.zv;
            fs->new_func.op_array.literals = &retop.zv;
#endif
#if PHP_VERSION_ID >= 80200
            expected_ex = execute_data;
            zend_atomic_bool_store_ex(&EG(vm_interrupt), true);
#elif PHP_VERSION_ID >= 80000
            expected_ex = execute_data;
            EG(vm_interrupt) = 1;
#else
            execute_data->opline = &retop.zop;
#endif
        } else {
            // TODO: not supported yet (JIT support appearing problematic)
        }
    }

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
        zval *exception_zv = &span->property_exception;
        if (EG(exception) && Z_TYPE_P(exception_zv) <= IS_FALSE) {
            ZVAL_OBJ_COPY(exception_zv, EG(exception));
        }

        dd_trace_stop_span_time(span);
    }

    bool keep_span = true;

    if (def->end.closure && !def->running && get_DD_TRACE_ENABLED()) {
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
#if PHP_VERSION_ID >= 80000
        zend_property_info *prop_info = ddtrace_hook_data_returned_prop_info;
        if (Z_ISREF(tmp)) {
            ZEND_REF_DEL_TYPE_SOURCE(Z_REF(tmp), prop_info);
        }
        if (Z_ISREF_P(returned)) {
            ZEND_REF_ADD_TYPE_SOURCE(Z_REF_P(returned), prop_info);
        }
#endif
        zval_ptr_dtor(&tmp);

        zval *exception = &dyn->hook_data->property_exception;
        ZVAL_COPY_VALUE(&tmp, exception);
        if (EG(exception)) {
            ZVAL_OBJ_COPY(exception, EG(exception));
        } else {
            ZVAL_NULL(exception);
        }
        zval_ptr_dtor(&tmp);

        LOGEV(HOOK_TRACE, dd_uhook_log_invocation(log, execute_data, "end", def->end.closure););

        def->running = true;
        dyn->hook_data->retval_ptr = retval;
        dyn->hook_data->execute_data = execute_data;
        keep_span = dd_uhook_call_hook(execute_data, &def->end, dyn->hook_data);
        dyn->hook_data->execute_data = NULL;
        dyn->hook_data->retval_ptr = NULL;
        def->running = false;

        span = dyn->hook_data->span; // end hook might allocate a new span; we need to free it
    }

    if (span) {
        dyn->hook_data->span = NULL;
        // e.g. spans started in limited mode are never properly started
        if (span->start) {
            ddtrace_clear_execute_data_span(invocation, keep_span);
            if (dyn->hook_data->prior_stack) {
                ddtrace_switch_span_stack(dyn->hook_data->prior_stack);
                OBJ_RELEASE(&dyn->hook_data->prior_stack->std);
            }
        } else {
            OBJ_RELEASE(&span->std);
        }
    }

    zend_object *exception_override = dyn->hook_data->exception_override;
    if (exception_override) {
        zend_clear_exception();
        if (exception_override != EXCEPTION_OVERRIDE_CLEAR) {
#if PHP_VERSION_ID >= 80000
            zend_throw_exception_internal(exception_override);
#else
            zval e;
            ZVAL_OBJ(&e, exception_override);
            zend_throw_exception_internal(&e);
#endif
        }
    }

    if (dyn->hook_data->suppress_call) {
        if (ZEND_USER_CODE(execute_data->func->type)) {
            zend_function *orig_func = *(zend_function**)(execute_data->func + 1);
            efree(execute_data->func);
            execute_data->func = orig_func;
            execute_data->opline = orig_func->op_array.opcodes + orig_func->op_array.last - 1;
        } else {
            // TODO: not supported yet (JIT support appearing problematic)
        }
    }

    OBJ_RELEASE(&dyn->hook_data->std);
}

static void dd_uhook_dtor(void *data) {
    dd_uhook_def *def = data;
    dd_uhook_callback_destroy(&def->begin);
    dd_uhook_callback_destroy(&def->end);
    if (def->function) {
        zend_string_release(def->function);
        if (def->scope) {
            zend_string_release(def->scope);
        }
    } else if (def->file) {
        zend_string_release(def->file);
    }
    zend_hash_index_del(&DDTRACE_G(uhook_active_hooks), (zend_ulong)def->id);
    efree(def);
}

#if PHP_VERSION_ID < 70400
#define _error_code error_code
#endif

/* {{{ proto int DDTrace\install_hook(string|Closure|Generator target, ?Closure begin = null, ?Closure end = null, int flags = 0) */
PHP_FUNCTION(DDTrace_install_hook) {
    zend_string *name = NULL;
    zend_function *resolved = NULL;
    zval *begin = NULL;
    zval *end = NULL;
    zend_object *closure = NULL;
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
    zend_long flags = 0;

    ZEND_PARSE_PARAMETERS_START(1, 4)
        DD_PARAM_PROLOGUE(0, 0);
        if (Z_TYPE_P(_arg) == IS_STRING) {
            name = Z_STR_P(_arg);
        } else if (Z_TYPE_P(_arg) == IS_OBJECT && (Z_OBJCE_P(_arg) == zend_ce_closure || Z_OBJCE_P(_arg) == zend_ce_generator)) {
            if (Z_OBJCE_P(_arg) == zend_ce_closure) {
                closure = Z_OBJ_P(_arg);
                resolved = (zend_function *) zend_get_closure_method_def(Z_OBJ_P(_arg));
            } else {
                zend_generator *generator = (zend_generator *) Z_OBJ_P(_arg);
                if (generator->execute_data) {
                    resolved = generator->execute_data->func;
                    if (ZEND_CALL_INFO(generator->execute_data) & ZEND_CALL_CLOSURE) {
                        closure = ZEND_CLOSURE_OBJECT(resolved);
                    }
                } else {
                    // let's be silent about a consumed generator?
                    _error_code = ZPP_ERROR_FAILURE;
                    break;
                }
            }
        } else if (Z_TYPE_P(_arg) == IS_ARRAY || Z_TYPE_P(_arg) == IS_OBJECT) {
#define INSTALL_HOOK_TYPES "string|callable|Generator|Closure"
            char *func_error = NULL;
            if (zend_parse_arg_func(_arg, &fci, &fcc, false, &func_error, true)) {
                if (!fcc.function_handler) {
                    // This is a trampoline function, we cannot hook this, not without hooking other things too
                    // Technically for e.g. __call one *could* hook the __call handler, then distinguish on the passed method name.
                    // Currently we do not have support for this, but we probably eventually want to.
                    RETURN_LONG(0);
                }
                resolved = fcc.function_handler;
            } else if (func_error) {
                zend_argument_type_error(1, "must be of type " INSTALL_HOOK_TYPES ", got %s, but %s", zend_zval_value_name(_arg), func_error);
                efree(func_error);
                _error_code = ZPP_ERROR_FAILURE;
                break;
            } else {
                goto type_error;
            }
        } else {
type_error:
            zend_argument_type_error(1, "must be of type " INSTALL_HOOK_TYPES ", %s given", zend_zval_value_name(_arg));
            _error_code = ZPP_ERROR_FAILURE;
            break;
        }
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_EX(begin, zend_ce_closure, 1, 0)
        Z_PARAM_OBJECT_OF_CLASS_EX(end, zend_ce_closure, 1, 0)
        Z_PARAM_LONG(flags)
    ZEND_PARSE_PARAMETERS_END();

    if (!begin && !end) {
        RETURN_LONG(0);
    }

    if (!get_DD_TRACE_ENABLED()) {
        RETURN_LONG(0);
    }

    dd_uhook_def *def = emalloc(sizeof(*def));
    def->closure = NULL;
    def->running = false;
    def->begin.fcc.function_handler = NULL;
    def->begin.closure = begin ? Z_OBJ_P(begin) : NULL;
    if (def->begin.closure) {
        GC_ADDREF(def->begin.closure);
    }
    def->end.fcc.function_handler = NULL;
    def->end.closure = end ? Z_OBJ_P(end) : NULL;
    if (def->end.closure) {
        GC_ADDREF(def->end.closure);
    }
    def->id = -1;

    uint32_t hook_limit = get_DD_TRACE_HOOK_LIMIT();

    zend_long id;
    def->file = NULL;
    if (resolved) {
        def->function = NULL;

        // Fetch the base function for fake closures: we need to do fake closure operations on the proper original function:
        // - inheritance handling requires having an op_array which stays alive for the whole remainder of the request
        // - internal functions are referenced by their zend_internal_function, not the closure copy
        if ((resolved->common.fn_flags & ZEND_ACC_FAKE_CLOSURE) && !(flags & HOOK_INSTANCE)) {
            zend_class_entry *resolved_ce = resolved->common.scope;
            HashTable *baseTable = resolved_ce ? &resolved_ce->function_table : EG(function_table);
            zend_function *original = zend_hash_find_ptr(baseTable, resolved->common.function_name);
            if (!ZEND_USER_CODE(resolved->type)) {
                if (original && original->type != resolved->type /* check for trampoline */) {
                    original = NULL; // this may happen with e.g. __call() where the function is defined as private
                }
                if (!original && resolved_ce) {
                    // Assume it's a __call or __callStatic trampoline
                    // Execute logic from zend_closure_call_magic here.
                    original = (resolved->common.fn_flags & ZEND_ACC_STATIC) ? resolved_ce->__callstatic : resolved_ce->__call;
                }
            }
            if (!original) {
                LOG(WARN,
                        "Could not find original function for fake closure %s%s%s at %s:%d",
                        resolved->common.scope ? ZSTR_VAL(resolved->common.scope->name) : "",
                        resolved->common.scope ? "::" : "",
                        ZSTR_VAL(resolved->common.function_name),
                        zend_get_executed_filename(), zend_get_executed_lineno());
                goto error;
            }
            resolved = original;
        }
        def->install_address = zai_hook_install_address(resolved);

        if (hook_limit > 0 && zai_hook_count_resolved(resolved) >= hook_limit) {
            LOG_LINE_ONCE(ERROR,
                    "Could not add hook to callable with more than datadog.trace.hook_limit = %d installed hooks",
                    hook_limit);
            goto error;
        }
        id = zai_hook_install_resolved(resolved,
            dd_uhook_begin, dd_uhook_end,
            ZAI_HOOK_AUX(def, dd_uhook_dtor), sizeof(dd_uhook_dynamic));

        LOG(HOOK_TRACE, "Installing a hook function %d at %s:%d on runtime %s %s%s%s",
            id,
            zend_get_executed_filename(), zend_get_executed_lineno(),
            resolved->common.scope ? "method" : "function",
            resolved->common.scope ? ZSTR_VAL(resolved->common.scope->name) : "",
            resolved->common.scope ? "::" : "",
            resolved->common.function_name ? ZSTR_VAL(resolved->common.function_name) : "<unnamed>");

        if (id >= 0 && closure && (flags & HOOK_INSTANCE)) {
            def->closure = closure;

            zval *hooks_zv;
            dd_closure_list *hooks;
            if ((hooks_zv = zend_hash_index_find(&DDTRACE_G(uhook_closure_hooks), (zend_ulong)(uintptr_t)closure))) {
                hooks = Z_PTR_P(hooks_zv);
                Z_PTR_P(hooks_zv) = hooks = erealloc(hooks, sizeof(dd_closure_list) + sizeof(zend_long) * ++hooks->size);
            } else {
                hooks = emalloc(sizeof(dd_closure_list) + sizeof(zend_long));
                hooks->size = 1;
                zend_hash_index_add_new_ptr(&DDTRACE_G(uhook_closure_hooks), (zend_ulong)(uintptr_t)closure, hooks);
            }
            hooks->id[hooks->size - 1] = id;
        }
    } else {
        const char *colon = strchr(ZSTR_VAL(name), ':');
        zai_str scope = ZAI_STR_EMPTY, function = ZAI_STR_FROM_ZSTR(name);
        if (colon && colon[1] == ':') {
            def->scope = zend_string_init(function.ptr, colon - ZSTR_VAL(name), 0);
            do ++colon; while (*colon == ':');
            def->function = zend_string_init(colon, ZSTR_VAL(name) + ZSTR_LEN(name) - colon, 0);
            scope = (zai_str)ZAI_STR_FROM_ZSTR(def->scope);
            function = (zai_str)ZAI_STR_FROM_ZSTR(def->function);
        } else {
            def->scope = NULL;
            if (ZSTR_LEN(name) == 0 || strchr(ZSTR_VAL(name), '.')) {
                def->function = NULL;
                char resolved_path_buf[MAXPATHLEN];
                if (ZSTR_LEN(name) > 0 && VCWD_REALPATH(ZSTR_VAL(name), resolved_path_buf)) {
                    def->file = zend_string_init(resolved_path_buf, strlen(resolved_path_buf), 0);
                } else if (ZSTR_LEN(name) > 2 && ZSTR_VAL(name)[0] == '.' && (ZSTR_VAL(name)[1] == '/' || ZSTR_VAL(name)[1] == '\\'
                     || (ZSTR_VAL(name)[1] == '.' && (ZSTR_VAL(name)[2] == '/' || ZSTR_VAL(name)[2] == '\\')))) { // relative path handling
                    LOG_LINE_ONCE(ERROR, "Could not add hook to file path %s, could not resolve path", ZSTR_VAL(name));
                    goto error;
                } else {
                    def->file = zend_string_copy(name);
                }
                function = (zai_str)ZAI_STR_EMPTY;
            } else {
                def->function = zend_string_init(function.ptr, function.len, 0);
            }
        }

        if (hook_limit > 0 && zai_hook_count_installed(scope, function) >= hook_limit) {
            LOG_LINE_ONCE(ERROR,
                "Could not add hook to %s%s%s with more than datadog.trace.hook_limit = %d installed hooks",
                def->scope ? ZSTR_VAL(def->scope) : "",
                def->scope ? "::" : "",
                def->scope ? ZSTR_VAL(def->function) : ZSTR_VAL(name),
                hook_limit);
            goto error;
        }

        id = zai_hook_install(
                scope, function,
                dd_uhook_begin,
                dd_uhook_end,
                ZAI_HOOK_AUX(def, dd_uhook_dtor),
                sizeof(dd_uhook_dynamic));

        if (id >= 0) {
            LOG(HOOK_TRACE, "Installing a hook function %d at %s:%d on %s %s%s%s",
                id,
                zend_get_executed_filename(), zend_get_executed_lineno(),
                def->file ? "file" : (def->scope ? "method" : "function"),
                def->scope ? ZSTR_VAL(def->scope) : "",
                def->scope ? "::" : "",
                def->file ? ZSTR_VAL(def->file) : ZSTR_VAL(def->function));
        }
    }

    if (id < 0) {
error:
        def->id = 0;
        dd_uhook_dtor(def);

        RETURN_LONG(0);
    }

    def->id = id;
    zend_hash_index_add_ptr(&DDTRACE_G(uhook_active_hooks), (zend_ulong)def->id, def);
    RETURN_LONG(id);
} /* }}} */

/* {{{ proto void DDTrace\remove_hook(int $id, string $location = "") */
PHP_FUNCTION(DDTrace_remove_hook) {
    (void)return_value;

    zend_long id;
    zend_string *location = NULL;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_LONG(id)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR(location)
    ZEND_PARSE_PARAMETERS_END();

    dd_uhook_def *def;
    if ((def = zend_hash_index_find_ptr(&DDTRACE_G(uhook_active_hooks), (zend_ulong)id))) {
        if (def->function || def->file) {
            zai_str scope = zai_str_from_zstr(def->scope);
            zai_str function = zai_str_from_zstr(def->function);
            if (location && ZSTR_LEN(location)) {
                LOG(HOOK_TRACE, "Excluding class %s from hook %d at %s:%d on %s %s%s%s",
                    ZSTR_VAL(location),
                    id,
                    zend_get_executed_filename(), zend_get_executed_lineno(),
                    def->file ? "file" : (def->scope ? "method" : "function"),
                    def->scope ? ZSTR_VAL(def->scope) : "",
                    def->scope ? "::" : "",
                    def->file ? ZSTR_VAL(def->file) : ZSTR_VAL(def->function));

                zend_string *lower = zend_string_tolower(location);
                zai_hook_exclude_class(scope, function, id, lower);
                zend_string_release(lower);
            } else {
                LOG(HOOK_TRACE, "Removing hook %d at %s:%d on %s %s%s%s",
                    id,
                    zend_get_executed_filename(), zend_get_executed_lineno(),
                    def->file ? "file" : (def->scope ? "method" : "function"),
                    def->scope ? ZSTR_VAL(def->scope) : "",
                    def->scope ? "::" : "",
                    def->file ? ZSTR_VAL(def->file) : ZSTR_VAL(def->function));

                zai_hook_remove(scope, function, id);
            }
        } else {
            if (location && ZSTR_LEN(location)) {
                zend_string *lower = zend_string_tolower(location);
                zai_hook_exclude_class_resolved(def->install_address, id, lower);
                zend_string_release(lower);
            } else {
                if (def->closure) {
                    const zend_function *closure = zend_get_closure_method_def(def->closure);
                    LOG(HOOK_TRACE, "Removing hook %d at %s:%d on %s %s%s%s",
                        id,
                        zend_get_executed_filename(), zend_get_executed_lineno(),
                        closure->common.scope ? "method" : "function",
                        closure->common.scope ? ZSTR_VAL(closure->common.scope->name) : "",
                        closure->common.scope ? "::" : "",
                        ZSTR_VAL(closure->common.function_name));
                } else {
                    LOG(HOOK_TRACE, "Removing hook %d at %s:%d",
                        id,
                        zend_get_executed_filename(), zend_get_executed_lineno());
                }

                zai_hook_remove_resolved(def->install_address, id);
            }
        }
    }
}

void dd_uhook_span(INTERNAL_FUNCTION_PARAMETERS, bool unlimited) {
    ddtrace_span_stack *stack = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        DD_PARAM_PROLOGUE(0, 0);
        if (Z_TYPE_P(_arg) == IS_OBJECT && (instanceof_function(Z_OBJCE_P(_arg), ddtrace_ce_span_data) || Z_OBJCE_P(_arg) == ddtrace_ce_span_stack)) {
            stack = (ddtrace_span_stack *) Z_OBJ_P(_arg);
            if (instanceof_function(Z_OBJCE_P(_arg), ddtrace_ce_span_data)) {
                stack = OBJ_SPANDATA(Z_OBJ_P(_arg))->stack;
            }
        } else {
            zend_argument_type_error(1, "must be of type DDTrace\\SpanData|DDTrace\\SpanStack, %s given", zend_zval_value_name(_arg));
            _error_code = ZPP_ERROR_FAILURE;
            break;
        }
    ZEND_PARSE_PARAMETERS_END();

    dd_hook_data *hookData = (dd_hook_data *)Z_OBJ_P(ZEND_THIS);

    if (hookData->span) {
        RETURN_OBJ_COPY(&hookData->span->std);
    }

    // pre-hook check
    if (!hookData->execute_data || (!unlimited && ddtrace_tracer_is_limited()) || !get_DD_TRACE_ENABLED()) {
        // dummy span, which never gets pushed
        hookData->span = ddtrace_init_dummy_span();
        RETURN_OBJ_COPY(&hookData->span->std);
    }

    // By this functionality we provide the ability to also switch the stack automatically back when the span attached to the function is closed
    if (stack) {
        ddtrace_span_data *span = zend_hash_index_find_ptr(&DDTRACE_G(traced_spans), hookData->invocation);
        if (span) {
            if (span->stack != stack) {
                LOG(ERROR, "Could not switch stack for hook in %s:%d", zend_get_executed_filename(), zend_get_executed_lineno());
            }
        } else {
            hookData->prior_stack = DDTRACE_G(active_stack);
            GC_ADDREF(&DDTRACE_G(active_stack)->std);
            ddtrace_switch_span_stack(stack);
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

ZEND_METHOD(DDTrace_HookData, span) {
    dd_uhook_span(INTERNAL_FUNCTION_PARAM_PASSTHRU, false);
}

ZEND_METHOD(DDTrace_HookData, unlimitedSpan) {
    dd_uhook_span(INTERNAL_FUNCTION_PARAM_PASSTHRU, true);
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
        LOG_LINE_ONCE(ERROR, "Cannot set more args than provided: got too many arguments for hook");
        RETURN_FALSE;
    }

    if (!ZEND_USER_CODE(func->type) && (int)zend_hash_num_elements(args) > passed_args) {
        if (zend_hash_num_elements(args) - passed_args > hookData->vm_stack_top - ZEND_CALL_VAR_NUM(hookData->execute_data, 0)) {
            RETURN_FALSE;
        }
#if PHP_VERSION_ID >= 80200
        // temporaries like the last observed frame are already initialized. Move them.
        memmove(ZEND_CALL_VAR_NUM(hookData->execute_data, zend_hash_num_elements(args)), ZEND_CALL_VAR_NUM(hookData->execute_data, passed_args), func->common.T * sizeof(zval *));
#endif
    }

    if (func->common.required_num_args > zend_hash_num_elements(args)) {
        LOG_LINE_ONCE(ERROR, "Not enough args provided for hook");
        RETURN_FALSE;
    }

    // If we'd remove args, we'd need to re-evaluate potential RECV_INIT opcodes, to get their default values, or outright throw a TypeError
    // Guard against that for now instead of manually re-evaluating RECV_INIT opcodes.
    if (ZEND_USER_CODE(func->type) && MIN(func->common.num_args, passed_args) > zend_hash_num_elements(args)) {
        LOG_LINE_ONCE(ERROR, "Can't pass less args to an untyped function than originally passed (minus extra args)");
        RETURN_FALSE;
    }

#if ZAI_JIT_BLACKLIST_ACTIVE
    // The tracing JIT will make assumptions about the refcounting and default args. Avoid it.
    // Long-winded explanation: the tracing JIT traces the amount of args passed. When there's one arg passed, but the function has two, of which one is optional,
    // then the tracing JIT may, when inlining the function, just assign the variable - after our begin handler was executed -, just assuming the variable has not been set.
    // This will then - obviously - override our value and unconditionally set the value to the default argument, which is bad.
    // There's no way around that, we're forced to blacklist that function completely from JIT.
    if (ZEND_USER_CODE(func->type)) {
        // Note: this isn't perfect, and hooks wishing to override args must do so unconditionally, even if the args are not changed.
        // Otherwise, if overrideArguments was not called on the first time this function was traced, the JIT will have successfully traced the function
        // and inserted its evil code and there's no going back then.
        zai_jit_blacklist_function_inlining(&func->op_array);
    }
#endif

    // When observers are executed, moving extra args behind the last temporary already happened
    zval *arg = ZEND_CALL_VAR_NUM(hookData->execute_data, 0), *last_arg = ZEND_USER_CODE(func->type) ? arg + func->common.num_args : ((void *)~0);
    zval *val, zv;
    int i = 0;
    ZEND_HASH_FOREACH_VAL(args, val) {
        if (arg >= last_arg) {
            // extra-arg handling
            arg = ZEND_CALL_VAR_NUM(hookData->execute_data, func->op_array.last_var + func->op_array.T);
            last_arg = (void *)~0;
        }
        if (i < (int)func->common.num_args && func->common.arg_info && (ZEND_ARG_SEND_MODE(&func->common.arg_info[i]) & ZEND_SEND_BY_REF) && !Z_ISREF_P(val)) {
            Z_TRY_ADDREF_P(val); // copying into the ref
            ZVAL_NEW_REF(&zv, val);
            Z_DELREF_P(&zv); // we'll copy it right below
            val = &zv;
        }
#if PHP_VERSION_ID < 80000
        // While the observer API, triggers immediately after args passing, on PHP 7 the interceptor only triggers after all args have been parsed
        // This only applied to user functions: in internal functions it's directly from zend_execute_internal
        if (i++ < (ZEND_USER_CODE(func->type) ? MAX(passed_args, (int)func->common.num_args) : passed_args)) {
#else
        if (i++ < passed_args) {
#endif
            zval garbage;
            ZVAL_COPY_VALUE(&garbage, arg);
            ZVAL_COPY(arg, val);
            zval_ptr_dtor(&garbage);
        } else {
            ZVAL_COPY(arg, val);
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

ZEND_METHOD(DDTrace_HookData, overrideReturnValue) {
    dd_hook_data *hookData = (dd_hook_data *)Z_OBJ_P(ZEND_THIS);

    zval *retval;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(retval)
    ZEND_PARSE_PARAMETERS_END();

    // post-hook check
    if (!hookData->retval_ptr) {
        RETURN_FALSE;
    }

    if (hookData->returns_reference) {
        ZVAL_MAKE_REF(retval);
    } else {
        ZVAL_DEREF(retval);
    }

    zval_ptr_dtor(hookData->retval_ptr);
    ZVAL_COPY(hookData->retval_ptr, retval);

    RETURN_TRUE;
}

ZEND_METHOD(DDTrace_HookData, disableJitInlining) {
    dd_hook_data *hookData = (dd_hook_data *)Z_OBJ_P(ZEND_THIS);

    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    hookData->dis_jit_inlining_called = true;

    if (hookData->execute_data->func->type != ZEND_USER_FUNCTION) {
        RETURN_FALSE;
    }

#if ZAI_JIT_BLACKLIST_ACTIVE
    zai_jit_blacklist_function_inlining(&hookData->execute_data->func->op_array);
#endif

    RETURN_TRUE;
}

ZEND_METHOD(DDTrace_HookData, suppressCall) {
    dd_hook_data *hookData = (dd_hook_data *)Z_OBJ_P(ZEND_THIS);

    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    if (!hookData->dis_jit_inlining_called) {
        LOG(ERROR, "suppressCall called without disableJitInlining before");
    }

    if (hookData->execute_data->func->type != ZEND_USER_FUNCTION) {
        LOG(ERROR, "suppressCall is only supported for user functions");
        RETURN_FALSE;
    }

    hookData->suppress_call = true;

    RETURN_TRUE;
}

ZEND_METHOD(DDTrace_HookData, allowNestedHook) {
    dd_hook_data *hookData = (dd_hook_data *)Z_OBJ_P(ZEND_THIS);

    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    if (!*hookData->running_ptr) {
        RETURN_FALSE;
    }

    *hookData->running_ptr = false;

    RETURN_TRUE;
}

ZEND_METHOD(DDTrace_HookData, overrideException) {
    dd_hook_data *hookData = (dd_hook_data *)Z_OBJ_P(ZEND_THIS);
    zend_object *throwable = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJ_OF_CLASS_EX(throwable, zend_ce_throwable, 1, 0)
    ZEND_PARSE_PARAMETERS_END();

    if (hookData->exception_override && hookData->exception_override != EXCEPTION_OVERRIDE_CLEAR) {
        OBJ_RELEASE(hookData->exception_override);
    }

    if (throwable) {
        GC_ADDREF(throwable);
        hookData->exception_override = throwable;
    } else {
        hookData->exception_override = EXCEPTION_OVERRIDE_CLEAR;
    }

    RETURN_TRUE;
}

ZEND_METHOD(DDTrace_HookData, getSourceFile) {
    (void)return_value;

    dd_hook_data *hookData = (dd_hook_data *)Z_OBJ_P(ZEND_THIS);
    zend_execute_data *hook_execute_data = hookData->execute_data;
    zend_execute_data *prev = NULL;
    if (hook_execute_data) {
        prev = hook_execute_data->prev_execute_data;
    }

    if (prev && prev->func->type == ZEND_USER_FUNCTION && prev->func->op_array.filename) {
        RETURN_STR_COPY(prev->func->op_array.filename);
    } else {
        RETURN_EMPTY_STRING();
    }
}

void zai_uhook_rinit() {
    zend_hash_init(&DDTRACE_G(uhook_active_hooks), 8, NULL, NULL, 0);
    zend_hash_init(&DDTRACE_G(uhook_closure_hooks), 8, NULL, NULL, 0);
}

void zai_uhook_rshutdown() {
    zend_hash_destroy(&DDTRACE_G(uhook_closure_hooks));
    zend_hash_destroy(&DDTRACE_G(uhook_active_hooks));
}

static zend_object_free_obj_t dd_uhook_closure_free_obj;
static void dd_uhook_closure_free_wrapper(zend_object *object) {
    dd_closure_list *hooks;
    zai_install_address address = zai_hook_install_address(zend_get_closure_method_def(object));
    if ((hooks = zend_hash_index_find_ptr(&DDTRACE_G(uhook_closure_hooks), (zend_ulong)(uintptr_t)object))) {
        for (size_t i = 0; i < hooks->size; ++i) {
            zai_hook_remove_resolved(address, hooks->id[i]);
        }
        efree(hooks);
        zend_hash_index_del(&DDTRACE_G(uhook_closure_hooks), (zend_ulong) (uintptr_t) object);
    }
    dd_uhook_closure_free_obj(object);
}

#if PHP_VERSION_ID >= 80000
void zai_uhook_attributes_minit(void);
void dd_register_opentelemetry_wrapper(void);
#endif
void zai_uhook_minit(int module_number) {
    ddtrace_hook_data_ce = register_class_DDTrace_HookData();
    ddtrace_hook_data_ce->create_object = dd_hook_data_create;
#if PHP_VERSION_ID >= 80000
    ddtrace_hook_data_returned_prop_info = zend_hash_str_find_ptr(&ddtrace_hook_data_ce->properties_info, ZEND_STRL("returned"));
#endif

    zend_register_functions(NULL, ext_functions, NULL, MODULE_PERSISTENT);
    register_uhook_symbols(module_number);

#if PHP_VERSION_ID >= 80000
    zai_uhook_attributes_minit();
    dd_register_opentelemetry_wrapper();
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

    // We must have an interrupt function to be able to suppress calls
#if PHP_VERSION_ID >= 80000
    orig_zend_interrupt_function = zend_interrupt_function;
    zend_interrupt_function = &dd_zend_interrupt_function;
#endif
}

#if PHP_VERSION_ID >= 80000
void zai_uhook_attributes_mshutdown(void);
#endif
void zai_uhook_mshutdown() {
#if PHP_VERSION_ID < 80300
    zend_unregister_functions(ext_functions, sizeof(ext_functions) / sizeof(zend_function_entry) - 1, NULL);
#endif
#if PHP_VERSION_ID >= 80000
    zai_uhook_attributes_mshutdown();
#endif
}
