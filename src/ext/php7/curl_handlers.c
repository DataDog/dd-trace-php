#include "curl_handlers.h"

#include <Zend/zend_interfaces.h>
#include <php.h>

#include "compat_string.h"
#include "configuration.h"
#include "engine_hooks.h"  // for ddtrace_backup_error_handling

#ifndef ZVAL_COPY_DEREF
#define ZVAL_COPY_DEREF(z, v)                     \
    do {                                          \
        zval *_z3 = (v);                          \
        if (Z_OPT_REFCOUNTED_P(_z3)) {            \
            if (UNEXPECTED(Z_OPT_ISREF_P(_z3))) { \
                _z3 = Z_REFVAL_P(_z3);            \
                if (Z_OPT_REFCOUNTED_P(_z3)) {    \
                    Z_ADDREF_P(_z3);              \
                }                                 \
            } else {                              \
                Z_ADDREF_P(_z3);                  \
            }                                     \
        }                                         \
        ZVAL_COPY_VALUE(z, _z3);                  \
    } while (0)
#endif

bool _dd_ext_curl_loaded = false;  // True global -- do not modify after startup

/* "le_curl" is ext/curl's resource type.
 * "le_curl" is what php_curl.h names this variable
 */
ZEND_TLS int le_curl = 0;

/* Cache things we tend to use a few times */
ZEND_TLS zval _dd_curl_httpheaders = {.u1.type_info = IS_UNDEF};
ZEND_TLS zval _dd_format_curl_http_headers = {.u1.type_info = IS_UNDEF};
ZEND_TLS zend_class_entry *_dd_ArrayKVStore_ce = NULL;
ZEND_TLS zend_class_entry *_dd_GlobalTracer_ce = NULL;
ZEND_TLS zend_class_entry *_dd_SpanContext_ce = NULL;
ZEND_TLS zend_function *_dd_ArrayKVStore_putForResource_fe = NULL;
ZEND_TLS zend_function *_dd_ArrayKVStore_getForResource_fe = NULL;
ZEND_TLS zend_function *_dd_ArrayKVStore_deleteResource_fe = NULL;
ZEND_TLS zend_function *_dd_GlobalTracer_get_fe = NULL;
ZEND_TLS zend_function *_dd_GlobalTracer_inject_fe = NULL;
ZEND_TLS zend_function *_dd_SpanContext_ctor = NULL;
ZEND_TLS bool _dd_curl_integration_loaded = false;

// Do not pass things like "parent", "self", "static" -- fully qualified names only!
static zend_class_entry *_dd_lookup_ce(const char *str, size_t len, int use_autoload) {
    zend_string *name = zend_string_init(str, len, 0);
    zend_class_entry *ce = zend_lookup_class_ex(name, NULL, use_autoload);
    zend_string_release(name);
    return ce;
}

/* Returns a zval containing a copy of the string; caller must release.
 * Makes initialization easier e.g.
 *     zval putForResource = ddtrace_zval_stringl(ZEND_STRL("putForResource"));
 *     // don't forget!
 *     zend_string_release(Z_STR(putForResource));
 */
static zval ddtrace_zval_stringl(const char *str, size_t len) {
    zval zv;
    ZVAL_STRINGL(&zv, str, len);
    return zv;
}

static zval ddtrace_zval_long(zend_long num) {
    zval zv;
    ZVAL_LONG(&zv, num);
    return zv;
}

static zval ddtrace_zval_null(void) {
    zval zv;
    ZVAL_NULL(&zv);
    return zv;
}

/**
 * Calls the method `fname` on the object, or static method on the `ce` if no object.
 * If you only have 0-2 args, consider zend_call_method_with_{0..2}_params instead
 *
 * @param obj May be null
 * @param ce Must not be null
 * @param fn_proxy The result of the method lookup is cached here, unless NULL
 * @param fname Must not be null
 * @param retval Must not be null
 * @param argc The number of items in @param argv
 * @param argv This is a plain C array e.g. `zval args[argc]`
 * @return
 */
static ZEND_RESULT_CODE ddtrace_call_method(zend_object *obj, zend_class_entry *ce, zend_function **fn_proxy,
                                            const char *fname, size_t fname_len, zval *retval, int argc, zval *argv) {
    zend_function *method;
    if (fn_proxy && *fn_proxy) {
        method = *fn_proxy;
    } else {
        zend_string *zstr = zend_string_init(fname, fname_len, 0);
        method = obj ? obj->handlers->get_method(&obj, zstr, NULL) : zend_std_get_static_method(ce, zstr, NULL);
        if (fn_proxy) {
            *fn_proxy = method;
        }
        zend_string_release(zstr);
    }

    zend_fcall_info fci = {
        .size = sizeof(zend_fcall_info),
        .retval = retval,
        .params = argv,
        .object = obj,
        /* Must be 0 to allow for by-ref args
         * BUT if you use by-ref args, you need to free the ref if it gets separated!
         */
        .no_separation = 0,
        .param_count = argc,
    };
    ZVAL_STR(&fci.function_name, method->common.function_name);

    zend_fcall_info_cache fcc = {
#if PHP_VERSION_ID < 70300
        .initialized = 1,
#endif
        .function_handler = method,
        .calling_scope = ce,
        .called_scope = ce,
        .object = obj,
    };

    ZEND_RESULT_CODE result = zend_call_function(&fci, &fcc);
    return result;
}

static ZEND_RESULT_CODE ddtrace_call_instance_method(zend_object *obj, zend_class_entry *ce, zend_function **fn_proxy,
                                                     const char *method, size_t method_len, zval *retval, int argc,
                                                     zval argv[]) {
    return ddtrace_call_method(obj, (ce ?: obj->ce), fn_proxy, method, method_len, retval, argc, argv);
}

static ZEND_RESULT_CODE ddtrace_call_function(const char *name, size_t name_len, zval *retval, int argc, zval argv[]) {
    zval fname = ddtrace_zval_stringl(name, name_len);
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
    zend_fcall_info_init(&fname, IS_CALLABLE_CHECK_SILENT, &fci, &fcc, NULL, NULL);
    fci.retval = retval;
    fci.params = argv;
    fci.no_separation = 0;  // allow for by-ref args
    fci.param_count = argc;
    ZEND_RESULT_CODE result = zend_call_function(&fci, &fcc);
    zend_string_release(Z_STR(fname));
    return result;
}

static void ddtrace_write_property(zval *obj, const char *prop, size_t prop_len, zval *value) {
    zval member = ddtrace_zval_stringl(prop, prop_len);
    // the underlying API doesn't tell you if it worked _shrug_
    Z_OBJ_P(obj)->handlers->write_property(obj, &member, value, NULL);
    zend_string_release(Z_STR(member));
}

// Modeled after PHP's property_exists for the Z_TYPE_P(object) == IS_OBJECT case
static bool ddtrace_property_exists(zval *object, zval *property) {
    zend_class_entry *ce;
    zend_property_info *property_info;

    ZEND_ASSERT(Z_TYPE_P(object) == IS_OBJECT);
    ZEND_ASSERT(Z_TYPE_P(property) == IS_STRING);

    ce = Z_OBJCE_P(object);
    property_info = zend_hash_find_ptr(&ce->properties_info, Z_STR_P(property));
#if PHP_VERSION_ID < 70400
    if (property_info && (property_info->flags & ZEND_ACC_SHADOW) == 0) {
        return true;
    }

    if (Z_OBJ_HANDLER_P(object, has_property) && Z_OBJ_HANDLER_P(object, has_property)(object, property, 2, NULL)) {
        return true;
    }
#else
    if (property_info && (!(property_info->flags & ZEND_ACC_PRIVATE) || property_info->ce == ce)) {
        return true;
    }

    if (Z_OBJ_HANDLER_P(object, has_property)(object, property, 2, NULL)) {
        return true;
    }
#endif
    return false;
}

static ZEND_RESULT_CODE ddtrace_read_property(zval *dest, zval *obj, const char *prop, size_t prop_len) {
    zval rv, member = ddtrace_zval_stringl(prop, prop_len);
    if (ddtrace_property_exists(obj, &member)) {
        zval *result = Z_OBJ_P(obj)->handlers->read_property(obj, &member, BP_VAR_R, NULL, &rv);
        zend_string_release(Z_STR(member));
        if (result) {
            ZVAL_COPY(dest, result);
            zval_ptr_dtor(result);
            return SUCCESS;
        }
    }
    zend_string_release(Z_STR(member));
    return FAILURE;
}

// Should be run after request_init_hook is run *and* all the classes are loaded
static bool _dd_load_curl_integration(void) {
    if (!get_dd_trace_sandbox_enabled()) {
        return false;
    }

    if (_dd_curl_integration_loaded) {
        return true;
    }

    _dd_ArrayKVStore_ce = _dd_lookup_ce(ZEND_STRL("DDTrace\\Util\\ArrayKVStore"), 0);
    _dd_GlobalTracer_ce = _dd_lookup_ce(ZEND_STRL("DDTrace\\GlobalTracer"), 0);
    _dd_SpanContext_ce = _dd_lookup_ce(ZEND_STRL("DDTrace\\SpanContext"), 0);

    if (!_dd_ArrayKVStore_ce || !_dd_GlobalTracer_ce || !_dd_SpanContext_ce) {
        return false;
    }

    zend_string *format_headers = zend_string_init(ZEND_STRL("DDTrace\\Format::CURL_HTTP_HEADERS"), 0);
    zval *format_headers_zv = zend_get_constant_ex(format_headers, NULL, ZEND_FETCH_CLASS_SILENT);
    zend_string_release(format_headers);
    if (format_headers_zv) {
        ZVAL_COPY(&_dd_format_curl_http_headers, format_headers_zv);
    } else {
        return false;
    }

    zend_string *curlopt_httpheader = zend_string_init(ZEND_STRL("CURLOPT_HTTPHEADER"), 0);
    zval *curlopt_httpheader_zv = zend_get_constant_ex(curlopt_httpheader, NULL, ZEND_FETCH_CLASS_SILENT);
    zend_string_release(curlopt_httpheader);
    if (curlopt_httpheader_zv) {
        ZVAL_COPY(&_dd_curl_httpheaders, curlopt_httpheader_zv);
    } else {
        return false;
    }

    _dd_curl_integration_loaded = true;
    return true;
}

static zval *_dd_ArrayKVStore_getForResource(zval *ch, zval *format, zval *value, zval *retval) {
    zval args[3] = {*ch, *format, *value};

    int result = ddtrace_call_method(NULL, _dd_ArrayKVStore_ce, &_dd_ArrayKVStore_getForResource_fe,
                                     ZEND_STRL("getForResource"), retval, 3, args);

    return (result == SUCCESS) ? retval : NULL;
}

static void _dd_ArrayKVStore_putForResource(zval *ch, zval *format, zval *value) {
    zval args[3] = {*ch, *format, *value};
    zval retval;
    ZVAL_UNDEF(&retval);

    ddtrace_call_method(NULL, _dd_ArrayKVStore_ce, &_dd_ArrayKVStore_putForResource_fe, ZEND_STRL("putForResource"),
                        &retval, 3, args);

    zval_dtor(&retval);
}

/* curl_close {{{ */
static void (*_dd_curl_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

ZEND_FUNCTION(ddtrace_curl_close) {
    zval *ch;

    if (_dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &ch) == SUCCESS) {
        zval retval;
        zend_call_method_with_1_params(NULL, _dd_ArrayKVStore_ce, &_dd_ArrayKVStore_deleteResource_fe, "deleteresource",
                                       &retval, ch);
    }

    _dd_curl_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

static void _dd_instrument_curl_close(void) {
    zend_function *curl_close;
    curl_close = zend_hash_str_find_ptr(CG(function_table), "curl_close", sizeof("curl_close") - 1);
    if (curl_close != NULL) {
        _dd_curl_close_handler = curl_close->internal_function.handler;
        curl_close->internal_function.handler = ZEND_FN(ddtrace_curl_close);
    }
}
/* }}} */

/* curl_copy_handle {{{ */
static void (*_dd_curl_copy_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

ZEND_FUNCTION(ddtrace_curl_copy_handle) {
    zval *ch1;

    if (!_dd_load_curl_integration() ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &ch1) == FAILURE) {
        _dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    _dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_RESOURCE) {
        zval *ch2 = return_value;

        zval default_headers;
        array_init(&default_headers);
        zval http_headers;
        zval *existing_headers =
            _dd_ArrayKVStore_getForResource(ch1, &_dd_format_curl_http_headers, &default_headers, &http_headers);

        if (existing_headers && Z_TYPE_P(existing_headers) == IS_ARRAY) {
            _dd_ArrayKVStore_putForResource(ch2, &_dd_format_curl_http_headers, existing_headers);
            zval_ptr_dtor(existing_headers);
        }

        zval_dtor(&default_headers);
    }
}

static void _dd_instrument_curl_copy_handle(void) {
    zend_function *curl_copy_handle;
    curl_copy_handle = zend_hash_str_find_ptr(CG(function_table), "curl_copy_handle", sizeof("curl_copy_handle") - 1);
    if (curl_copy_handle != NULL) {
        _dd_curl_copy_handle_handler = curl_copy_handle->internal_function.handler;
        curl_copy_handle->internal_function.handler = ZEND_FN(ddtrace_curl_copy_handle);
    }
}
/* }}} */

/* curl_exec {{{ */
static void (*_dd_curl_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

static ZEND_RESULT_CODE _dd_span_context_new(zval *dest, zval trace_id, zval span_id, zval parent_id, zval origin) {
    zval context;
    if (object_init_ex(&context, _dd_SpanContext_ce) == FAILURE) {
        return FAILURE;
    }

    zval construct_args[3] = {trace_id, span_id, parent_id};

    zval retval;
    ZEND_RESULT_CODE result = ddtrace_call_method(Z_OBJ(context), _dd_SpanContext_ce, &_dd_SpanContext_ctor,
                                                  ZEND_STRL("__construct"), &retval, 3, construct_args);

    if (result == SUCCESS) {
        ZVAL_COPY(dest, &context);
        if (Z_TYPE(origin) == IS_STRING) {
            ddtrace_write_property(&context, ZEND_STRL("origin"), &origin);
        }
    }
    return result;
}

// headers will get modified!
static ZEND_RESULT_CODE _dd_tracer_inject_helper(zval *headers, zval *format, ddtrace_span_t *span) {
    zval tracer;
    // $tracer = \DDTrace\GlobalTracer::get();
    if (ddtrace_call_method(NULL, _dd_GlobalTracer_ce, &_dd_GlobalTracer_get_fe, ZEND_STRL("get"), &tracer, 0, NULL) ==
        FAILURE) {
        return FAILURE;
    }

    zval trace_id = ddtrace_zval_long(0);
    zval span_id = ddtrace_zval_long(0);
    zval parent_id = ddtrace_zval_long(0);
    zval origin = ddtrace_zval_null();

    /* In limited tracing mode, we need to add the distributed trace headers
     * even if we aren't making a new span for curl_exec. We also need "origin"
     * which exists only on userland span contexts. For both these reasons we
     * fetch the Tracer's active span.
     */
    zval active_span, active_context = ddtrace_zval_null();
    if (ddtrace_call_instance_method(Z_OBJ(tracer), NULL, NULL, ZEND_STRL("getActiveSpan"), &active_span, 0, NULL) ==
        FAILURE) {
        zval_dtor(&tracer);
        return FAILURE;
    }
    if (Z_TYPE(active_span) == IS_OBJECT) {
        if (ddtrace_call_method(Z_OBJ(active_span), Z_OBJ(active_span)->ce, NULL, ZEND_STRL("getContext"),
                                &active_context, 0, NULL) == FAILURE) {
            zval_dtor(&active_span);
            zval_dtor(&tracer);
            return FAILURE;
        } else {
            ddtrace_read_property(&origin, &active_context, ZEND_STRL("origin"));
        }
    }

    if (span) {
        zval tmp = ddtrace_zval_long(span->trace_id);
        ddtrace_convert_to_string(&trace_id, &tmp);

        tmp = ddtrace_zval_long(span->span_id);
        ddtrace_convert_to_string(&span_id, &tmp);

        if (span->parent_id > 0) {
            tmp = ddtrace_zval_long(span->parent_id);
            ddtrace_convert_to_string(&parent_id, &tmp);
        }
    } else if (Z_TYPE(active_context) == IS_OBJECT) {
        ddtrace_call_instance_method(Z_OBJ(active_context), NULL, NULL, ZEND_STRL("getTraceId"), &trace_id, 0, NULL);
        ddtrace_call_instance_method(Z_OBJ(active_context), NULL, NULL, ZEND_STRL("getSpanId"), &span_id, 0, NULL);
        ddtrace_call_instance_method(Z_OBJ(active_context), NULL, NULL, ZEND_STRL("getParentId"), &parent_id, 0, NULL);
    } else {
        zval_dtor(&active_context);
        zval_dtor(&active_span);
        zval_dtor(&tracer);
        return FAILURE;
    }

    zval context;
    if (_dd_span_context_new(&context, trace_id, span_id, parent_id, origin) == FAILURE) {
        zval_dtor(&active_context);
        zval_dtor(&active_span);
        zval_dtor(&origin);
        zval_dtor(&parent_id);
        zval_dtor(&span_id);
        zval_dtor(&trace_id);
        zval_dtor(&tracer);
        return FAILURE;
    }

    zval inject_args[3] = {context, *format, *headers};
    zval retval;
    ZEND_RESULT_CODE result = ddtrace_call_method(Z_OBJ(tracer), Z_OBJ(tracer)->ce, &_dd_GlobalTracer_inject_fe,
                                                  ZEND_STRL("inject"), &retval, 3, inject_args);
    // $tracer->inject($context, Format::CURL_HTTP_HEADERS, $httpHeaders);

    if (result == SUCCESS) {
        // todo: dtor headers?
        ZVAL_COPY_DEREF(headers, &inject_args[2]);
        zval_dtor(&inject_args[2]);
    }
    zval_dtor(&context);
    zval_dtor(&active_context);
    zval_dtor(&active_span);
    zval_dtor(&origin);
    zval_dtor(&parent_id);
    zval_dtor(&span_id);
    zval_dtor(&trace_id);
    zval_dtor(&context);
    zval_dtor(&tracer);
    return result;
}

ZEND_FUNCTION(ddtrace_curl_exec) {
    zval *ch;

    if (_dd_load_curl_integration() && Z_TYPE(_dd_curl_httpheaders) == IS_LONG &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &ch) == SUCCESS) {
        void *resource = zend_fetch_resource(Z_RES_P(ch), NULL, le_curl);
        if (resource) {
            zval default_headers;
            array_init(&default_headers);
            zval http_headers;
            zval *existing_headers =
                _dd_ArrayKVStore_getForResource(ch, &_dd_format_curl_http_headers, &default_headers, &http_headers);

            if (existing_headers) {
                if (Z_TYPE_P(existing_headers) == IS_ARRAY) {
                    ddtrace_span_t *active_span = DDTRACE_G(open_spans_top);
                    if (_dd_tracer_inject_helper(existing_headers, &_dd_format_curl_http_headers, active_span) ==
                        SUCCESS) {
                        // curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
                        zval setopt_args[3] = {*ch, _dd_curl_httpheaders, *existing_headers};
                        zval retval;
                        ddtrace_call_function(ZEND_STRL("curl_setopt"), &retval, 3, setopt_args);
                    }
                }
                zval_ptr_dtor(existing_headers);
            }
            zval_dtor(&default_headers);
        }
    }

    _dd_curl_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

static void _dd_instrument_curl_exec(void) {
    zend_function *curl_exec;
    curl_exec = zend_hash_str_find_ptr(CG(function_table), "curl_exec", sizeof("curl_exec") - 1);
    if (curl_exec != NULL) {
        _dd_curl_exec_handler = curl_exec->internal_function.handler;
        curl_exec->internal_function.handler = ZEND_FN(ddtrace_curl_exec);
    }
}
/* }}} */

/* curl_setopt_handler {{{ */
static void (*_dd_curl_setopt_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

ZEND_FUNCTION(ddtrace_curl_setopt) {
    zval *zid, *zvalue;
    zend_long option;

    if (!_dd_load_curl_integration() ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "rlz", &zid, &option, &zvalue) == FAILURE) {
        _dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    _dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_TRUE && Z_TYPE(_dd_curl_httpheaders) == IS_LONG &&
        Z_LVAL(_dd_curl_httpheaders) == option) {
        _dd_ArrayKVStore_putForResource(zid, &_dd_format_curl_http_headers, zvalue);
    }
}

static void _dd_instrument_curl_setopt(void) {
    zend_function *curl_setopt;
    curl_setopt = zend_hash_str_find_ptr(CG(function_table), "curl_setopt", sizeof("curl_setopt") - 1);
    if (curl_setopt != NULL) {
        _dd_curl_setopt_handler = curl_setopt->internal_function.handler;
        curl_setopt->internal_function.handler = ZEND_FN(ddtrace_curl_setopt);
    }
}
/* }}} */

/* curl_setopt_array_handler {{{ */
static void (*_dd_curl_setopt_array_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

ZEND_FUNCTION(ddtrace_curl_setopt_array) {
    zval *zid, *arr;

    // todo: does merely parsing the array args here increase the refcount?
    if (_dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "ra", &zid, &arr) == SUCCESS) {
        void *resource = zend_fetch_resource(Z_RES_P(zid), NULL, le_curl);
        if (resource) {
            if (Z_TYPE(_dd_curl_httpheaders) == IS_LONG) {
                zval *value = zend_hash_index_find(Z_ARR_P(arr), Z_LVAL(_dd_curl_httpheaders));
                if (value) {
                    _dd_ArrayKVStore_putForResource(zid, &_dd_format_curl_http_headers, value);
                }
            }
        }
    }

    _dd_curl_setopt_array_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

static void _dd_instrument_curl_setopt_array(void) {
    zend_function *curl_setopt_array;
    curl_setopt_array =
        zend_hash_str_find_ptr(CG(function_table), "curl_setopt_array", sizeof("curl_setopt_array") - 1);
    if (curl_setopt_array != NULL) {
        _dd_curl_setopt_array_handler = curl_setopt_array->internal_function.handler;
        curl_setopt_array->internal_function.handler = ZEND_FN(ddtrace_curl_setopt_array);
    }
}
/* }}} */

void ddtrace_curl_handlers_startup(void) {
    // if we cannot find ext/curl then do not instrument it
    zend_string *curl = zend_string_init(ZEND_STRL("curl"), 0);
    _dd_ext_curl_loaded = zend_hash_exists(&module_registry, curl);
    zend_string_release(curl);
    if (!_dd_ext_curl_loaded) {
        return;
    }

    if (!get_dd_trace_sandbox_enabled()) {
        return;
    }

    _dd_instrument_curl_exec();

    /* todo: skip if distributed tracing is not enabled
     * todo: sandbox the PHP_FUNCTION of each of these
     * {{{ */
    _dd_instrument_curl_close();
    _dd_instrument_curl_copy_handle();
    _dd_instrument_curl_setopt();
    _dd_instrument_curl_setopt_array();
    /* }}} */
}

static void _dd_find_curl_resource_type(void) {
    zval retval;

    if (!_dd_ext_curl_loaded) {
        return;
    }

    ddtrace_error_handling eh;
    ddtrace_backup_error_handling(&eh, EH_THROW);

    zval *curl_var = zend_call_method_with_0_params(NULL, NULL, NULL, "curl_init", &retval);
    if (curl_var && Z_TYPE_P(curl_var) == IS_RESOURCE) {
        le_curl = Z_RES_P(curl_var)->type;

        zend_call_method_with_1_params(NULL, NULL, NULL, "curl_close", &retval, curl_var);
    }

    ddtrace_restore_error_handling(&eh);
    // this doesn't throw (today anyway) but let's be safe
    ddtrace_maybe_clear_exception();
}

void ddtrace_curl_handlers_rinit(void) { _dd_find_curl_resource_type(); }
void ddtrace_curl_handlers_rshutdown(void) { zval_dtor(&_dd_format_curl_http_headers); }
