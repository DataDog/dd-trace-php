#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#include "ddtrace.h"
#include "configuration.h"
#include "exception_serialize.h"
#include "compat_string.h"
#include "SAPI.h"
#include "components/log/log.h"
#include "sidecar.h"
#include "live_debugger.h"
#include "ext/hash/php_hash.h"
#include <zend_abstract_interface/symbols/symbols.h>
#include <exceptions/exceptions.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static zend_result dd_exception_to_error_msg(zend_object *exception, void *context, add_tag_fn_t add_tag, enum dd_exception exception_state) {
    zend_string *msg = zai_exception_message(exception);
    zend_long line = zval_get_long(zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_LINE)));
    zend_string *file = ddtrace_convert_to_str(zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_FILE)));

    char *error_text, *status_line = NULL;

    if (SG(sapi_headers).http_response_code >= 500) {
        if (SG(sapi_headers).http_status_line) {
            UNUSED(asprintf(&status_line, " (%s)", SG(sapi_headers).http_status_line));
        } else {
            UNUSED(asprintf(&status_line, " (%d)", SG(sapi_headers).http_response_code));
        }
    }

    const char *exception_type;
    switch (exception_state) {
        case DD_EXCEPTION_CAUGHT: exception_type = "Caught"; break;
        case DD_EXCEPTION_UNCAUGHT: exception_type = "Uncaught"; break;
        default: exception_type = "Thrown"; break;
    }

    int error_len = asprintf(&error_text, "%s %s%s%s%s in %s:" ZEND_LONG_FMT, exception_type,
            ZSTR_VAL(exception->ce->name), status_line ? status_line : "", ZSTR_LEN(msg) > 0 ? ": " : "",
            ZSTR_VAL(msg), ZSTR_VAL(file), line);

    free(status_line);

    ddtrace_string key = DDTRACE_STRING_LITERAL("error.message");
    ddtrace_string value = {error_text, error_len};
    zend_result result = add_tag(context, key, value);

    zend_string_release(file);
    free(error_text);
    return result;
}

static zend_result dd_exception_to_error_type(zend_object *exception, void *context, add_tag_fn_t add_tag) {
    ddtrace_string value, key = DDTRACE_STRING_LITERAL("error.type");

    if (instanceof_function(exception->ce, ddtrace_ce_fatal_error)) {
        zval *code = zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_CODE));
        const char *error_type_string = "{unknown error}";

        if (Z_TYPE_P(code) == IS_LONG) {
            switch (Z_LVAL_P(code)) {
                case E_ERROR:
                    error_type_string = "E_ERROR";
                    break;
                case E_CORE_ERROR:
                    error_type_string = "E_CORE_ERROR";
                    break;
                case E_COMPILE_ERROR:
                    error_type_string = "E_COMPILE_ERROR";
                    break;
                case E_USER_ERROR:
                    error_type_string = "E_USER_ERROR";
                    break;
                default:
                    LOG_UNREACHABLE("Unhandled error type in DDTrace\\FatalError; is a fatal error case missing?");
            }

        } else {
            LOG_UNREACHABLE("Exception was a DDTrace\\FatalError but failed to get an exception code");
        }

        value = ddtrace_string_cstring_ctor((char *)error_type_string);
    } else {
        zend_string *type_name = exception->ce->name;
        value.ptr = ZSTR_VAL(type_name);
        value.len = ZSTR_LEN(type_name);
    }

    return add_tag(context, key, value);
}

static zend_result dd_exception_trace_to_error_stack(zend_string *trace, void *context, add_tag_fn_t add_tag) {
    ddtrace_string key = DDTRACE_STRING_LITERAL("error.stack");
    ddtrace_string value = {ZSTR_VAL(trace), ZSTR_LEN(trace)};
    zend_result result = add_tag(context, key, value);
    zend_string_release(trace);
    return result;
}

static void ddtrace_capture_string_value(zend_string *str, struct ddog_CaptureValue *value, const ddog_CaptureConfiguration *config) {
    value->type = DDOG_CHARSLICE_C("string");
    if (!value->not_captured_reason.len) {
        value->value = (ddog_CharSlice) {.ptr = ZSTR_VAL(str), .len = ZSTR_LEN(str)};
        if (value->value.len > config->max_length) {
            char *integer = zend_arena_alloc(&DDTRACE_G(debugger_capture_arena), 20);
            int len = sprintf(integer, "%" PRIuPTR, value->value.len);
            value->size = (ddog_CharSlice) {.ptr = integer, .len = len};
            value->value.len = config->max_length;
            value->truncated = true;
        }
    }
}

static void ddtrace_capture_long_value(zend_long num, struct ddog_CaptureValue *value) {
    value->type = DDOG_CHARSLICE_C("int");
    if (!value->not_captured_reason.len) {
        char *integer = zend_arena_alloc(&DDTRACE_G(debugger_capture_arena), 20);
        int len = sprintf(integer, ZEND_LONG_FMT, num);
        value->value = (ddog_CharSlice) {.ptr = integer, .len = len};
    }
}

void ddtrace_create_capture_value(zval *zv, struct ddog_CaptureValue *value, const ddog_CaptureConfiguration *config, int remaining_nesting) {
    ZVAL_DEREF(zv);
    switch (Z_TYPE_P(zv)) {
        case IS_FALSE:
            value->type = DDOG_CHARSLICE_C("bool");
            if (!value->not_captured_reason.len) {
                value->value = DDOG_CHARSLICE_C("false");
            }
            break;

        case IS_TRUE:
            value->type = DDOG_CHARSLICE_C("bool");
            if (!value->not_captured_reason.len) {
                value->value = DDOG_CHARSLICE_C("true");
            }
            break;

        case IS_LONG:
            ddtrace_capture_long_value(Z_LVAL_P(zv), value);
            break;

        case IS_DOUBLE: {
            value->type = DDOG_CHARSLICE_C("float");
            if (!value->not_captured_reason.len) {
                char *num = zend_arena_alloc(&DDTRACE_G(debugger_capture_arena), 20);
                php_gcvt(Z_DVAL_P(zv), (int) EG(precision), '.', 'E', num);
                value->value = (ddog_CharSlice) {.ptr = num, .len = strlen(num)};
            }
            break;
        }

        case IS_STRING:
            ddtrace_capture_string_value(Z_STR_P(zv), value, config);
            break;

        case IS_ARRAY: {
            value->type = DDOG_CHARSLICE_C("array");
            if (value->not_captured_reason.len) {
                break;
            }
            if (remaining_nesting == 0) {
                value->not_captured_reason = DDOG_CHARSLICE_C("depth");
                break;
            }
            zval *val;
            if (zend_array_is_list(Z_ARR_P(zv))) {
                int remaining_fields = config->max_collection_size;
                ZEND_HASH_FOREACH_VAL(Z_ARR_P(zv), val) {
                    if (remaining_fields-- == 0) {
                        value->not_captured_reason = DDOG_CHARSLICE_C("collectionSize");
                        break;
                    }

                    struct ddog_CaptureValue value_capture = {0};
                    ddtrace_create_capture_value(val, &value_capture, config, remaining_nesting - 1);
                    ddog_capture_value_add_element(value, value_capture);
                } ZEND_HASH_FOREACH_END();
            } else {
                zend_long idx;
                zend_string *key;
                int remaining_fields = config->max_collection_size;
                ZEND_HASH_FOREACH_KEY_VAL(Z_ARR_P(zv), idx, key, val) {
                    if (remaining_fields-- == 0) {
                        value->not_captured_reason = DDOG_CHARSLICE_C("collectionSize");
                        break;
                    }

                    struct ddog_CaptureValue key_capture = {0}, value_capture = {0};
                    if (key) {
                        ddtrace_capture_string_value(key, &key_capture, config);
                        ddtrace_snapshot_redacted_name(&value_capture, dd_zend_string_to_CharSlice(key));
                    } else {
                        ddtrace_capture_long_value(idx, &key_capture);
                    }
                    ddtrace_create_capture_value(val, &value_capture, config, remaining_nesting - 1);
                    ddog_capture_value_add_entry(value, key_capture, value_capture);
                } ZEND_HASH_FOREACH_END();
            }
            break;
        }

        case IS_OBJECT: {
            zend_class_entry *ce = Z_OBJCE_P(zv);
            value->type = (ddog_CharSlice){ .ptr = ZSTR_VAL(ce->name), .len = ZSTR_LEN(ce->name) };
            if (value->not_captured_reason.len) {
                break;
            }
            if (remaining_nesting == 0) {
                value->not_captured_reason = DDOG_CHARSLICE_C("depth");
                break;
            }
            if (ddog_snapshot_redacted_type(dd_zend_string_to_CharSlice(ce->name))) {
                value->not_captured_reason = DDOG_CHARSLICE_C("redactedType");
                break;
            }
            zval *val;
            zend_string *key;
            int remaining_fields = config->max_field_count;
#if PHP_VERSION_ID < 70400
            int is_temp = 0;
#endif
            // reverse to prefer child class properties first
            HashTable *ht = ce->type == ZEND_INTERNAL_CLASS ?
                            #if PHP_VERSION_ID < 70400
                            Z_OBJDEBUG_P(zv, is_temp)
                            #else
                            zend_get_properties_for(zv, ZEND_PROP_PURPOSE_DEBUG)
                            #endif
                                                            : Z_OBJPROP_P(zv);

            if (!ht) {
                break;
            }
            ZEND_HASH_REVERSE_FOREACH_STR_KEY_VAL(ht, key, val) {
                if (!key) {
                    continue;
                }

                if (remaining_fields-- == 0) {
                    value->not_captured_reason = DDOG_CHARSLICE_C("fieldCount");
                    break;
                }

                struct ddog_CaptureValue value_capture = {0};
                ddog_CharSlice fieldname;
                if (ZSTR_LEN(key) < 3 || ZSTR_VAL(key)[0]) {
                    fieldname = (ddog_CharSlice) {.ptr = ZSTR_VAL(key), .len = ZSTR_LEN(key)};
                } else if (ZSTR_VAL(key)[1] == '*') { // skip \0*\0
                    fieldname = (ddog_CharSlice) {.ptr = ZSTR_VAL(key) + 3, .len = ZSTR_LEN(key) - 3};
                } else {
                    char *name = zend_arena_alloc(&DDTRACE_G(debugger_capture_arena), ZSTR_LEN(key));
                    int classname_len = strlen(ZSTR_VAL(key) + 1);
                    memcpy(name, ZSTR_VAL(key) + 1, classname_len);
                    name[classname_len++] = ':';
                    name[classname_len++] = ':';
                    memcpy(name + classname_len, ZSTR_VAL(key) + classname_len, ZSTR_LEN(key) - classname_len);
                    fieldname = (ddog_CharSlice) {.ptr = name, .len = ZSTR_LEN(key)};
                }
                ddtrace_snapshot_redacted_name(&value_capture, fieldname);
                ZVAL_DEINDIRECT(val);
                ddtrace_create_capture_value(val, &value_capture, config, remaining_nesting - 1);
                ddog_capture_value_add_field(value, fieldname, value_capture);
            } ZEND_HASH_FOREACH_END();
            if (ce->type == ZEND_INTERNAL_CLASS) {
#if PHP_VERSION_ID < 70400
                if (is_temp) {
                    zend_array_release(ht);
                }
#else
                zend_hash_next_index_insert_ptr(&DDTRACE_G(debugger_capture_ephemerals), ht);
#endif
            }
            break;
        }

        case IS_RESOURCE: {
            const char *type_name = zend_rsrc_list_get_rsrc_type(Z_RES_P(zv));
            ddtrace_capture_long_value(Z_RES_P(zv)->handle, value);
            value->type = (ddog_CharSlice){ .ptr = type_name, .len = strlen(type_name) };
            break;
        }

        default:
            value->type = DDOG_CHARSLICE_C("null");
            value->is_null = true;
    }
}

#define uuid_len 36
#define hash_len 16

static ddog_DebuggerCapture *dd_create_frame_and_collect_locals(char *exception_id, char *exception_hash, int frame_num, ddog_CharSlice class_slice, ddog_CharSlice func_slice, zval *locals, zend_string *service_name, const ddog_CaptureConfiguration *capture_config, uint64_t time, void *context, add_tag_fn_t add_meta) {
    char *snapshot_id = zend_arena_alloc(&DDTRACE_G(debugger_capture_arena), uuid_len);
    ddog_snapshot_format_new_uuid((uint8_t(*)[uuid_len])snapshot_id);

    char msg[40];
    int len = sprintf(msg, "_dd.debug.error.%d.snapshot_id", frame_num);
    add_meta(context, (ddtrace_string){msg, len}, (ddtrace_string){snapshot_id, 36});

    ddog_DebuggerCapture *capture = ddog_create_exception_snapshot(&DDTRACE_G(exception_debugger_buffer),
                                                                   (ddog_CharSlice){ .ptr = ZSTR_VAL(service_name), .len = ZSTR_LEN(service_name) },
                                                                   DDOG_CHARSLICE_C("php"),
                                                                   (ddog_CharSlice){ .ptr = snapshot_id, .len = uuid_len },
                                                                   (ddog_CharSlice){ .ptr = exception_id, .len = uuid_len },
                                                                   (ddog_CharSlice){ .ptr = exception_hash, .len = hash_len },
                                                                   frame_num,
                                                                   class_slice,
                                                                   func_slice,
                                                                   time);

    if (locals && Z_TYPE_P(locals) == IS_ARRAY) {
        zend_string *key;
        zval *val;
        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARR_P(locals), key, val) {
            if (!zend_string_equals_literal(key, "GLOBALS")) {
                struct ddog_CaptureValue capture_value = {0};
                ddtrace_create_capture_value(val, &capture_value, capture_config, capture_config->max_reference_depth);
                ddog_snapshot_add_field(capture, DDOG_FIELD_TYPE_LOCAL, (ddog_CharSlice) {.ptr = ZSTR_VAL(key), .len = ZSTR_LEN(key)}, capture_value);
            }
        } ZEND_HASH_FOREACH_END();
    }

    return capture;
}

static inline void dd_extend_hash(zend_ulong *hash, zend_string *str) {
    *hash = *hash * 33 * ZSTR_LEN(str) + ZSTR_HASH(str);
}

static zend_ulong ddtrace_compute_exception_hash(zend_object *exception) {
    zend_ulong hash = 0;

    zval ex, *previous = &ex;
    ZVAL_OBJ(&ex, exception);

    do {
        exception = Z_OBJ_P(previous);
        dd_extend_hash(&hash, exception->ce->name);

        zval *frame;
        zval *trace = zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_TRACE));
        if (Z_TYPE_P(trace) == IS_ARRAY) {
            ZEND_HASH_FOREACH_VAL(Z_ARR_P(trace), frame) {
                if (Z_TYPE_P(frame) != IS_ARRAY) {
                    continue;
                }

                zval *class_name = zend_hash_find(Z_ARR_P(frame), ZSTR_KNOWN(ZEND_STR_CLASS));
                if (class_name && Z_TYPE_P(class_name) == IS_STRING) {
                    dd_extend_hash(&hash, Z_STR_P(class_name));
                }
                zval *func_name = zend_hash_find(Z_ARR_P(frame), ZSTR_KNOWN(ZEND_STR_FUNCTION));
                if (func_name && Z_TYPE_P(func_name) == IS_STRING) {
                    dd_extend_hash(&hash, Z_STR_P(func_name));
                }
            } ZEND_HASH_FOREACH_END();
        }
        Z_PROTECT_RECURSION_P(previous);
        previous = zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_PREVIOUS));
    } while (Z_TYPE_P(previous) == IS_OBJECT && !Z_IS_RECURSIVE_P(previous) &&
            instanceof_function(Z_OBJCE_P(previous), zend_ce_throwable));

    return hash;
}

static void ddtrace_collect_exception_debug_data(zend_object *exception, zend_string *service_name, uint64_t time, void *context, add_tag_fn_t add_meta) {
    if (!ddtrace_exception_debugging_is_active()) {
        return;
    }

    zval *trace = zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_TRACE));
    if (Z_TYPE_P(trace) != IS_ARRAY) {
        return;
    }

    zend_string *key_locals = zend_string_init(ZEND_STRL("locals"), 0);
    zval *locals = zai_exception_read_property(exception, key_locals);

    if (!DDTRACE_G(debugger_capture_arena)) {
        DDTRACE_G(debugger_capture_arena) = zend_arena_create(65536);
    }

    const ddog_CaptureConfiguration capture_config = ddog_capture_defaults();

    char *exception_hash = zend_arena_alloc(&DDTRACE_G(debugger_capture_arena), hash_len);
    zend_ulong exception_long_hash = ddtrace_compute_exception_hash(exception);
    php_hash_bin2hex(exception_hash, (unsigned char *)&exception_long_hash, sizeof(exception_long_hash));

    add_meta(context, DDTRACE_STRING_LITERAL("error.debug_info_captured"), DDTRACE_STRING_LITERAL("true"));
    add_meta(context, DDTRACE_STRING_LITERAL("_dd.debug.error.exception_hash"), (ddtrace_string){exception_hash, hash_len});

    if (!ddog_exception_hash_limiter_inc(ddtrace_sidecar, (uint64_t)exception_long_hash, get_DD_EXCEPTION_REPLAY_CAPTURE_INTERVAL_SECONDS())) {
        LOG(TRACE, "Skipping exception replay capture due to hash %.*s already recently hit", hash_len, exception_hash);
        return;
    }

    char *exception_id = zend_arena_alloc(&DDTRACE_G(debugger_capture_arena), uuid_len);
    ddog_snapshot_format_new_uuid((uint8_t(*)[uuid_len])exception_id);

    add_meta(context, DDTRACE_STRING_LITERAL("_dd.debug.error.exception_capture_id"), (ddtrace_string){exception_id, uuid_len});

    memset(&DDTRACE_G(exception_debugger_buffer), 0, sizeof(DDTRACE_G(exception_debugger_buffer)));

    zval *frame;
    int frame_num = 0;
    ZEND_HASH_FOREACH_NUM_KEY_VAL(Z_ARR_P(trace), frame_num, frame) {
        if (get_DD_EXCEPTION_REPLAY_CAPTURE_MAX_FRAMES() >= 0 && get_DD_EXCEPTION_REPLAY_CAPTURE_MAX_FRAMES() < frame_num) {
            break;
        }

        if (Z_TYPE_P(frame) != IS_ARRAY) {
            continue;
        }

        zend_class_entry *ce = NULL;
        zend_function *func = NULL;
        ddog_CharSlice func_slice = DDOG_CHARSLICE_C("");
        ddog_CharSlice class_slice = DDOG_CHARSLICE_C("");
        zval *class_name = zend_hash_find(Z_ARR_P(frame), ZSTR_KNOWN(ZEND_STR_CLASS));
        if (class_name && Z_TYPE_P(class_name) == IS_STRING) {
            ce = zai_symbol_lookup_class_global(zai_str_from_zstr(Z_STR_P(class_name)));
            class_slice = dd_zend_string_to_CharSlice(Z_STR_P(class_name));
        }
        zval *func_name = zend_hash_find(Z_ARR_P(frame), ZSTR_KNOWN(ZEND_STR_FUNCTION));
        if (func_name && Z_TYPE_P(func_name) == IS_STRING) {
            zai_str wtf = zai_str_from_zstr(Z_STR_P(func_name));
            func = zai_symbol_lookup_function(ce ? ZAI_SYMBOL_SCOPE_CLASS : ZAI_SYMBOL_SCOPE_GLOBAL, ce, &wtf);
            func_slice = dd_zend_string_to_CharSlice(Z_STR_P(func_name));
        }

        ddog_DebuggerCapture *capture = dd_create_frame_and_collect_locals(exception_id, exception_hash, frame_num, class_slice, func_slice, locals, service_name, &capture_config, time, context, add_meta);
        locals = zend_hash_find(Z_ARR_P(frame), key_locals);

        zend_string *key;
        zval *val;
        zval *args = zend_hash_find(Z_ARR_P(frame), ZSTR_KNOWN(ZEND_STR_ARGS));
        if (args && Z_TYPE_P(args) == IS_ARRAY) {
            zend_long idx;
            ZEND_HASH_FOREACH_KEY_VAL(Z_ARR_P(args), idx, key, val) {
                struct ddog_CaptureValue capture_value = {0};
                ddtrace_create_capture_value(val, &capture_value, &capture_config, capture_config.max_reference_depth);
                ddog_CharSlice arg_name;
                if (key) {
                    arg_name = (ddog_CharSlice) {.ptr = ZSTR_VAL(key), .len = ZSTR_LEN(key)};
                } else if (func && idx < func->common.num_args) {
                    if (ZEND_USER_CODE(func->type)) {
                        zend_string *name = func->op_array.arg_info[idx].name;
                        arg_name = (ddog_CharSlice) {.ptr = ZSTR_VAL(name), .len = ZSTR_LEN(name)};
                    } else {
                        const char *name = func->internal_function.arg_info[idx].name;
                        arg_name = (ddog_CharSlice) {.ptr = name, .len = strlen(name)};
                    }
                } else {
                    char *integer = zend_arena_alloc(&DDTRACE_G(debugger_capture_arena), 23);
                    int len = sprintf(integer, "arg" ZEND_LONG_FMT, idx);
                    arg_name = (ddog_CharSlice){ .ptr = integer, .len = len };
                }
                ddog_snapshot_add_field(capture, DDOG_FIELD_TYPE_ARG, arg_name, capture_value);
            } ZEND_HASH_FOREACH_END();
        }

        zval *obj = zend_hash_find(Z_ARR_P(frame), ZSTR_KNOWN(ZEND_STR_OBJECT));
        if (obj) {
            struct ddog_CaptureValue capture_value = {0};
            ddtrace_create_capture_value(obj, &capture_value, &capture_config, capture_config.max_reference_depth);
            ddog_snapshot_add_field(capture, DDOG_FIELD_TYPE_ARG, DDOG_CHARSLICE_C("this"), capture_value);
        }
    } ZEND_HASH_FOREACH_END();

    if (get_DD_EXCEPTION_REPLAY_CAPTURE_MAX_FRAMES() < 0 || get_DD_EXCEPTION_REPLAY_CAPTURE_MAX_FRAMES() > frame_num) {
        if (locals && Z_TYPE_P(locals) == IS_ARRAY) {
            dd_create_frame_and_collect_locals(exception_id, exception_hash, frame_num + 1, DDOG_CHARSLICE_C(""), DDOG_CHARSLICE_C(""), locals, service_name, &capture_config, time, context, add_meta);
        }
    }

    // Note: We MUST immediately send this, and not defer, as stuff may be freed during span processing. Including stuff potentially contained within the exception debugger payload.
    ddtrace_sidecar_send_debugger_data(DDTRACE_G(exception_debugger_buffer));
    if (DDTRACE_G(debugger_capture_arena)) {
        zend_arena_destroy(DDTRACE_G(debugger_capture_arena));
        DDTRACE_G(debugger_capture_arena) = NULL;
    }

    zend_string_release(key_locals);
}

// Guarantees that add_tag will only be called once per tag, will stop trying to add tags if one fails.
zend_result ddtrace_exception_to_meta(zend_object *exception, zend_string *service_name, uint64_t time, void *context, add_tag_fn_t add_meta, enum dd_exception exception_state) {
    zend_object *exception_root = exception;
    zend_string *full_trace = zai_get_trace_without_args_from_exception(exception);

    zval *previous = zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_PREVIOUS));
    while (Z_TYPE_P(previous) == IS_OBJECT && !Z_IS_RECURSIVE_P(previous) &&
           instanceof_function(Z_OBJCE_P(previous), zend_ce_throwable)) {
        zend_string *trace_string = zai_get_trace_without_args_from_exception(Z_OBJ_P(previous));

        zend_string *msg = zai_exception_message(exception);
        zend_long line = zval_get_long(zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_LINE)));
        zend_string *file = ddtrace_convert_to_str(zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_FILE)));

        zend_string *complete_trace =
        zend_strpprintf(0, "%s\n\nNext %s%s%s in %s:" ZEND_LONG_FMT "\nStack trace:\n%s", ZSTR_VAL(trace_string),
                ZSTR_VAL(exception->ce->name), ZSTR_LEN(msg) ? ": " : "", ZSTR_VAL(msg), ZSTR_VAL(file),
                line, ZSTR_VAL(full_trace));
        zend_string_release(trace_string);
        zend_string_release(full_trace);
        zend_string_release(file);
        full_trace = complete_trace;

        Z_PROTECT_RECURSION_P(previous);
        exception = Z_OBJ_P(previous);
        previous = zai_exception_read_property(exception, ZSTR_KNOWN(ZEND_STR_PREVIOUS));
    }

    // exception is now the innermost exception, i.e. what we need
    ddtrace_collect_exception_debug_data(exception, service_name, time / 1000000, context, add_meta);

    previous = zai_exception_read_property(exception_root, ZSTR_KNOWN(ZEND_STR_PREVIOUS));
    while (Z_TYPE_P(previous) == IS_OBJECT && !Z_IS_RECURSIVE_P(previous) &&
           instanceof_function(Z_OBJCE_P(previous), zend_ce_throwable)) {
        Z_UNPROTECT_RECURSION_P(previous);
        previous = zai_exception_read_property(Z_OBJ_P(previous), ZSTR_KNOWN(ZEND_STR_PREVIOUS));
    }

    bool success = dd_exception_to_error_msg(exception, context, add_meta, exception_state) == SUCCESS &&
                   dd_exception_to_error_type(exception, context, add_meta) == SUCCESS &&
                   dd_exception_trace_to_error_stack(full_trace, context, add_meta) == SUCCESS;
    return success ? SUCCESS : FAILURE;
}
