#include <php.h>
#include <Zend/zend_attributes.h>
#include <Zend/zend_smart_str.h>
#include "uhook_attributes_arginfo.h"
#include "../ddtrace.h"
#include "../configuration.h"
#include "uhook.h"

#include <hook/hook.h>

zend_class_entry *ddtrace_hook_attribute_ce;
static zend_string *dd_hook_attribute_lcname;

typedef struct {
    zend_string *name;
    zend_string *resource;
    zend_string *service;
    zend_string *type;
    zend_array *tags;
    bool args;
    zend_array *arg_whitelist;
    bool retval;
    bool run_if_limited;
    bool active;
    bool disallow_recursion;
} dd_uhook_def;

typedef struct {
    ddtrace_span_data *span;
    bool skipped;
    bool was_primed;
} dd_uhook_dynamic;

static void dd_uhook_save_value_nested(smart_str *str, zval *value, int remaining_nesting) {
    const int string_maxlen = 255;
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) == IS_ARRAY) {
        HashTable *ht = Z_ARR_P(value);

        if (remaining_nesting == 0) {
            smart_str_append_printf(str, "[size %d]", zend_hash_num_elements(ht));
        }

        smart_str_appendc(str, '[');
        const int max_values = remaining_nesting == 1 ? 3 : 10;
        int counter = 0;
        zend_string *strkey;
        zend_long numkey;
        zval *arrval;
        ZEND_HASH_FOREACH_KEY_VAL(ht, numkey, strkey, arrval) {
            if (++counter > max_values) {
                break;
            }
            if (counter > 1) {
                smart_str_appends(str, ", ");
            }
            if (strkey) {
                smart_str_appendc(str, '\'');
                smart_str_append(str, strkey);
                smart_str_append_printf(str, "%.*s", MIN(string_maxlen, (int)ZSTR_LEN(strkey)), ZSTR_VAL(strkey));
                smart_str_appendc(str, '\'');
            } else {
                smart_str_append_printf(str, ZEND_LONG_FMT, numkey);
            }
            smart_str_appends(str, " => ");

            dd_uhook_save_value_nested(str, arrval, remaining_nesting - 1);
        } ZEND_HASH_FOREACH_END();
        if (counter > max_values) {
            smart_str_appends(str, ", ...");
        }
        smart_str_appendc(str, ']');
    } else if (Z_TYPE_P(value) == IS_OBJECT) {
        smart_str_appends(str, "Object of type ");
        smart_str_append(str, Z_OBJCE_P(value)->name);
    } else if (Z_TYPE_P(value) == IS_FALSE) {
        smart_str_appends(str, "false");
    } else if (Z_TYPE_P(value) == IS_TRUE) {
        smart_str_appends(str, "true");
    } else if (Z_TYPE_P(value) == IS_NULL) {
        smart_str_appends(str, "null");
    } else {
        if (remaining_nesting < 2) {
            smart_str_appendc(str, '\'');
        }
        zend_string *val = zval_get_string(value);
        smart_str_append_printf(str, "%.*s", MIN(string_maxlen, (int)ZSTR_LEN(val)), ZSTR_VAL(val));
        zend_string_release(val);
        if (remaining_nesting < 2) {
            smart_str_appendc(str, '\'');
        }
    }
}

static zval dd_uhook_save_value(zval *value) {
    smart_str str = {0};
    dd_uhook_save_value_nested(&str, value, 2);
    smart_str_0(&str);
    zval ret;
    ZVAL_STR(&ret, str.s);
    return ret;
}

static void dd_fill_span_data(dd_uhook_def *def, ddtrace_span_data *span) {
    if (def->name) {
        zval *name = ddtrace_spandata_property_name(span);
        zval_ptr_dtor(name);
        ZVAL_STR_COPY(name, def->name);
    }
    if (def->resource) {
        zval *resource = ddtrace_spandata_property_resource(span);
        zval_ptr_dtor(resource);
        ZVAL_STR_COPY(resource, def->resource);
    }
    if (def->service) {
        zval *service = ddtrace_spandata_property_service(span);
        zval_ptr_dtor(service);
        ZVAL_STR_COPY(service, def->service);
    }
    if (def->type) {
        zval *type = ddtrace_spandata_property_type(span);
        zval_ptr_dtor(type);
        ZVAL_STR_COPY(type, def->type);
    }
    if (def->tags) {
        zend_array *meta = ddtrace_spandata_property_meta(span);
        zend_string *key;
        zval *value;
        ZEND_HASH_FOREACH_STR_KEY_VAL(def->tags, key, value) {
            if (key) {
                zend_hash_update(meta, key, value);
            }
        } ZEND_HASH_FOREACH_END();
    }
}

void dd_uhook_fill_args_in_meta(dd_uhook_def *def, HashTable *meta, zend_execute_data *execute_data) {
    uint32_t num_args = EX_NUM_ARGS();

    if (!num_args || !def->args) {
        return;
    }

    zval *p = EX_VAR_NUM(0);
    zend_function *func = EX(func);

    uint32_t first_extra_arg = MIN(num_args, func->common.num_args);

    if (func->type == ZEND_USER_FUNCTION) {
        uint32_t varnum = 0;
        for (zval *end = p + first_extra_arg; p < end; ++p) {
            zend_string *arg_name = func->op_array.vars[varnum++];
            if (!def->arg_whitelist || zend_hash_exists(def->arg_whitelist, arg_name)) {
                zend_string *arg = zend_strpprintf(0, "arg.%.*s", (int) ZSTR_LEN(arg_name), ZSTR_VAL(arg_name));
                zval zv = dd_uhook_save_value(p);
                zend_hash_update(meta, arg, &zv);
                zend_string_release(arg);
            }
        }

        p = EX_VAR_NUM(func->op_array.last_var + func->op_array.T);
    } else {
        uint32_t varnum = 0;
        for (zval *end = p + first_extra_arg; p < end; ++p) {
            zend_string *arg_name = func->common.arg_info[varnum++].name;
            if (!def->arg_whitelist || zend_hash_exists(def->arg_whitelist, arg_name)) {
                zend_string *arg = zend_strpprintf(0, "arg.%.*s", (int) ZSTR_LEN(arg_name), ZSTR_VAL(arg_name));
                zval zv = dd_uhook_save_value(p);
                zend_hash_update(meta, arg, &zv);
                zend_string_release(arg);
            }
        }
    }

    num_args -= first_extra_arg;
    if (!def->arg_whitelist) {
        // collect trailing variadic args
        uint32_t varnum = first_extra_arg;
        for (zval *end = p + num_args; p < end; ++p) {
            zend_string *arg = zend_strpprintf(0, "arg.%d", varnum++);
            zval zv = dd_uhook_save_value(p);
            zend_hash_update(meta, arg, &zv);
            zend_string_release(arg);
        }
    }
}


static bool dd_uhook_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if ((!def->run_if_limited && ddtrace_tracer_is_limited()) || (def->active && def->disallow_recursion) || !get_DD_TRACE_ENABLED()) {
        dyn->skipped = false;
        dyn->span = NULL;
        return true;
    }

    def->active = true; // recursion protection
    dyn->was_primed = false;

    dyn->span = ddtrace_alloc_execute_data_span(invocation, execute_data);
    dd_fill_span_data(def, dyn->span);
    dd_uhook_fill_args_in_meta(def, ddtrace_spandata_property_meta(dyn->span), execute_data);

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
        dyn->span = NULL;
        return;
    }

    dyn->span = ddtrace_alloc_execute_data_span(invocation, execute_data);
    dd_fill_span_data(def, dyn->span);
    if (def->retval) {
        zend_array *meta = ddtrace_spandata_property_meta(dyn->span);
        zval val = dd_uhook_save_value(value);
        zend_hash_str_update(meta, ZEND_STRL("send_value"), &val);
    }
}

static void dd_uhook_generator_yield(zend_ulong invocation, zend_execute_data *execute_data, zval *key, zval *value, void *auxiliary, void *dynamic) {
    (void)key, (void)execute_data;

    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (!dyn->span) {
        return;
    }

    if (dyn->span->duration == DDTRACE_DROPPED_SPAN) {
        dyn->span = NULL;
        ddtrace_clear_execute_data_span(invocation, false);
    } else {
        zval *exception_zv = ddtrace_spandata_property_exception(dyn->span);
        if (EG(exception) && Z_TYPE_P(exception_zv) <= IS_FALSE) {
            ZVAL_OBJ_COPY(exception_zv, EG(exception));
        }

        dd_trace_stop_span_time(dyn->span);

        if (def->retval) {
            zend_array *meta = ddtrace_spandata_property_meta(dyn->span);
            zval keyzv = dd_uhook_save_value(key);
            zend_hash_str_update(meta, ZEND_STRL("yield_key"), &keyzv);
            zval val = dd_uhook_save_value(value);
            zend_hash_str_update(meta, ZEND_STRL("yield_value"), &val);
        }
    }

    ddtrace_clear_execute_data_span(invocation, true);
    dyn->span = NULL;
}

extern void (*profiling_interrupt_function)(zend_execute_data *);
static void dd_uhook_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    dd_uhook_def *def = auxiliary;
    dd_uhook_dynamic *dyn = dynamic;

    if (!dyn->span) {
        return;
    }

    if (dyn->span->duration == DDTRACE_DROPPED_SPAN) {
        ddtrace_clear_execute_data_span(invocation, false);
    } else {
        zval *exception_zv = ddtrace_spandata_property_exception(dyn->span);
        if (EG(exception) && Z_TYPE_P(exception_zv) <= IS_FALSE) {
            ZVAL_OBJ_COPY(exception_zv, EG(exception));
        }

        dd_trace_stop_span_time(dyn->span);

        if (def->retval) {
            zend_array *meta = ddtrace_spandata_property_meta(dyn->span);
            zval val = dd_uhook_save_value(retval);
            zend_hash_str_update(meta, ZEND_STRL("return_value"), &val);
        }
    }

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

    ddtrace_clear_execute_data_span(invocation, true);

    def->active = false;
}

static void dd_uhook_dtor(void *data) {
    dd_uhook_def *def = data;
    if (def->name) {
        zend_string_release(def->name);
    }
    if (def->resource) {
        zend_string_release(def->resource);
    }
    if (def->service) {
        zend_string_release(def->service);
    }
    if (def->type) {
        zend_string_release(def->type);
    }
    if (def->arg_whitelist) {
        zend_array_release(def->arg_whitelist);
    }
    if (def->tags) {
        zend_array_release(def->tags);
    }
    efree(def);
}


void dd_uhook_on_function_resolve(zend_function *func) {
    zend_attribute *attr = zend_get_attribute(func->common.attributes, dd_hook_attribute_lcname);
    if (attr) {
        dd_uhook_def *def = ecalloc(1, sizeof(*def));
        zend_arg_info *arg_info = ddtrace_hook_attribute_ce->constructor->common.arg_info;
        uint32_t max_num_args = ddtrace_hook_attribute_ce->constructor->common.num_args;
        for (uint32_t i = 0; i < attr->argc; ++i) {
            zend_attribute_arg *arg = &attr->args[i];
            zend_string *name = arg->name;
            if (!name && i < max_num_args) {
                name = zend_string_copy(arg_info[i].name);
            }

            zval value;
            ZVAL_COPY_OR_DUP(&value, &arg->value);

            if (Z_TYPE(value) == IS_CONSTANT_AST) {
                if (SUCCESS != zval_update_constant_ex(&value, func->common.scope)) {
                    zval_ptr_dtor(&value);
                    continue;
                }
            }

            if (zend_string_equals_literal(name, "name") && Z_TYPE(value) == IS_STRING) {
                if (!def->name) {
                    def->name = zend_string_copy(Z_STR(value));
                }
            } else if (zend_string_equals_literal(name, "resource") && Z_TYPE(value) == IS_STRING) {
                if (!def->resource) {
                    def->resource = zend_string_copy(Z_STR(value));
                }
            } else if (zend_string_equals_literal(name, "service") && Z_TYPE(value) == IS_STRING) {
                if (!def->service) {
                    def->service = zend_string_copy(Z_STR(value));
                }
            } else if (zend_string_equals_literal(name, "type") && Z_TYPE(value) == IS_STRING) {
                if (!def->type) {
                    def->type = zend_string_copy(Z_STR(value));
                }
            } else if (zend_string_equals_literal(name, "saveArgs")) {
                if (!def->arg_whitelist) {
                    if (Z_TYPE(value) == IS_ARRAY) {
                        def->args = true;
                        def->arg_whitelist = zend_new_array(zend_hash_num_elements(Z_ARR(value)));
                        zval *val;
                        ZEND_HASH_FOREACH_VAL(Z_ARR(value), val) {
                            if (Z_TYPE_P(val) == IS_STRING) {
                                zend_hash_add_empty_element(def->arg_whitelist, Z_STR_P(val));
                            }
                        } ZEND_HASH_FOREACH_END();
                    } else {
                        def->args = zend_is_true(&value);
                    }
                }
            } else if (zend_string_equals_literal(name, "saveReturn")) {
                def->retval = zend_is_true(&value);
            } else if (zend_string_equals_literal(name, "run_if_limited")) {
                def->run_if_limited = zend_is_true(&value);
            } else if (zend_string_equals_literal(name, "recurse")) {
                def->disallow_recursion = !zend_is_true(&value);
            } else if (zend_string_equals_literal(name, "tags") && Z_TYPE(value) == IS_ARRAY) {
                if (!def->tags) {
                    def->tags = Z_ARR(value);
                    GC_ADDREF(def->tags);
                }
            }

            zval_ptr_dtor(&value);
        }

        zai_hook_install_resolved_generator(func,
                                            dd_uhook_begin, dd_uhook_generator_resumption, dd_uhook_generator_yield, dd_uhook_end,
                                            ZAI_HOOK_AUX(def, dd_uhook_dtor), sizeof(dd_uhook_dynamic));

    }
}

void zai_uhook_attributes_minit(void) {
    zai_hook_on_function_resolve = dd_uhook_on_function_resolve;

    ddtrace_hook_attribute_ce = register_class_DDTrace_Trace();
#if PHP_VERSION_ID >= 80200
    zend_mark_internal_attribute(ddtrace_hook_attribute_ce);
#endif
    dd_hook_attribute_lcname = zend_string_tolower_ex(ddtrace_hook_attribute_ce->name, true);
}

void zai_uhook_attributes_mshutdown(void) {
    zend_string_release(dd_hook_attribute_lcname);
}

ZEND_METHOD(DDTrace_Trace, __construct) {
    (void) execute_data, (void) return_value;
}
