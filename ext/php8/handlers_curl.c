#include <Zend/zend_interfaces.h>
#include <php.h>

#include "compat_string.h"
#include "configuration.h"
#include "engine_api.h"
#include "engine_hooks.h"  // for ddtrace_backup_error_handling
#include "handlers_internal.h"
#include "span.h"

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
ZEND_TLS zend_function *_dd_SpanContext_ctor = NULL;
ZEND_TLS bool _dd_curl_integration_loaded = false;

static void (*_dd_curl_close_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_dd_curl_exec_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_dd_curl_copy_handle_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_dd_curl_init_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_dd_curl_setopt_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;
static void (*_dd_curl_setopt_array_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

// Should be run after request_init_hook is run *and* all the classes are loaded
static bool _dd_load_curl_integration(void) {
    if (!get_dd_trace_sandbox_enabled() || DDTRACE_G(disable_in_current_request)) {
        return false;
    }

    if (_dd_curl_integration_loaded) {
        return true;
    }

    _dd_ArrayKVStore_ce = ddtrace_lookup_ce(ZEND_STRL("DDTrace\\Util\\ArrayKVStore"));
    _dd_GlobalTracer_ce = ddtrace_lookup_ce(ZEND_STRL("DDTrace\\GlobalTracer"));
    _dd_SpanContext_ce = ddtrace_lookup_ce(ZEND_STRL("DDTrace\\SpanContext"));

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

static void _dd_ArrayKVStore_deleteResource(zval *ch) {
    zval retval = ddtrace_zval_undef();
    zend_call_method_with_1_params(NULL, _dd_ArrayKVStore_ce, &_dd_ArrayKVStore_deleteResource_fe, "deleteresource",
                                   &retval, ch);
}

static zval dd_ArrayKVStore_getForResource(zval *ch, zval *format, zval *value) {
    zval retval = ddtrace_zval_null(), args[3] = {*ch, *format, *value};
    zend_function **fn_proxy = &_dd_ArrayKVStore_getForResource_fe;
    ddtrace_call_method(NULL, _dd_ArrayKVStore_ce, fn_proxy, ZEND_STRL("getForResource"), &retval, 3, args);
    return retval;
}

static void _dd_ArrayKVStore_putForResource(zval *ch, zval *format, zval *value) {
    zval retval = ddtrace_zval_undef(), args[3] = {*ch, *format, *value};
    zend_function **fn_proxy = &_dd_ArrayKVStore_putForResource_fe;
    ddtrace_call_method(NULL, _dd_ArrayKVStore_ce, fn_proxy, ZEND_STRL("putForResource"), &retval, 3, args);
    zval_ptr_dtor(&retval);
}

ZEND_FUNCTION(ddtrace_curl_close) {
    zval *ch;

    if (_dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &ch) == SUCCESS) {
        ddtrace_sandbox_backup backup = ddtrace_sandbox_begin();
        _dd_ArrayKVStore_deleteResource(ch);
        ddtrace_sandbox_end(&backup);
    }

    _dd_curl_close_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_FUNCTION(ddtrace_curl_copy_handle) {
    zval *ch1;
    bool should_hook = _dd_load_curl_integration() &&
                       zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &ch1) == SUCCESS;
    _dd_curl_copy_handle_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (should_hook && Z_TYPE_P(return_value) == IS_RESOURCE && ddtrace_config_distributed_tracing_enabled()) {
        ddtrace_sandbox_backup backup = ddtrace_sandbox_begin();
        zval default_headers, *ch2 = return_value;
        array_init(&default_headers);
        zval http_headers = dd_ArrayKVStore_getForResource(ch1, &_dd_format_curl_http_headers, &default_headers);

        if (Z_TYPE(http_headers) == IS_ARRAY) {
            _dd_ArrayKVStore_putForResource(ch2, &_dd_format_curl_http_headers, &http_headers);
        }

        zval_ptr_dtor(&http_headers);
        zval_ptr_dtor(&default_headers);
        ddtrace_sandbox_end(&backup);
    }
}

/* curl_exec {{{ */
static ZEND_RESULT_CODE dd_span_context_new(zval *context, zval trace_id, zval span_id, zval parent_id, zval origin) {
    if (object_init_ex(context, _dd_SpanContext_ce) == FAILURE) {
        return FAILURE;
    }

    zval construct_args[3] = {trace_id, span_id, parent_id};

    zval retval = ddtrace_zval_undef();
    ZEND_RESULT_CODE result = ddtrace_call_method(Z_OBJ_P(context), _dd_SpanContext_ce, &_dd_SpanContext_ctor,
                                                  ZEND_STRL("__construct"), &retval, 3, construct_args);
    zval_ptr_dtor(&retval);
    if (result == SUCCESS) {
        if (Z_TYPE(origin) == IS_STRING) {
            ddtrace_write_property(context, ZEND_STRL("origin"), &origin);
        }
    }
    return result;
}

// headers will get modified!
static ZEND_RESULT_CODE dd_tracer_inject_helper(zval *headers, zval *format, ddtrace_span_t *span) {
    zval tracer = ddtrace_zval_undef();
    // $tracer = \DDTrace\GlobalTracer::get();
    if (ddtrace_call_method(NULL, _dd_GlobalTracer_ce, &_dd_GlobalTracer_get_fe, ZEND_STRL("get"), &tracer, 0, NULL) ==
        FAILURE) {
        return FAILURE;
    }

    ZEND_RESULT_CODE result = FAILURE;

    /* In limited tracing mode, we need to add the distributed trace headers
     * even if we aren't making a new span for curl_exec. We also need "origin"
     * which exists only on userland span contexts. For both these reasons we
     * fetch the Tracer's active span.
     */
    zval active_span = ddtrace_zval_undef(), active_context = ddtrace_zval_null();
    zval origin = ddtrace_zval_null();
    if (ddtrace_call_method(Z_OBJ(tracer), Z_OBJ(tracer)->ce, NULL, ZEND_STRL("getActiveSpan"), &active_span, 0,
                            NULL) != SUCCESS) {
        goto cleanup_tracer;
    }
    if (Z_TYPE(active_span) == IS_OBJECT) {
        if (ddtrace_call_method(Z_OBJ(active_span), Z_OBJ(active_span)->ce, NULL, ZEND_STRL("getContext"),
                                &active_context, 0, NULL) != SUCCESS) {
            goto cleanup_active_span;
        } else {
            ddtrace_read_property(&origin, &active_context, ZEND_STRL("origin"));
        }
    }

    zval trace_id = ddtrace_zval_long(0);
    zval span_id = ddtrace_zval_long(0);
    zval parent_id = ddtrace_zval_long(0);

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
        zend_object *obj = Z_OBJ(active_context);
        zend_class_entry *ce = obj->ce;
        ddtrace_call_method(obj, ce, NULL, ZEND_STRL("getTraceId"), &trace_id, 0, NULL);
        ddtrace_call_method(obj, ce, NULL, ZEND_STRL("getSpanId"), &span_id, 0, NULL);
        ddtrace_call_method(obj, ce, NULL, ZEND_STRL("getParentId"), &parent_id, 0, NULL);
    } else {
        goto cleanup_active_context;
    }

    zval context = ddtrace_zval_undef();
    if (UNEXPECTED(dd_span_context_new(&context, trace_id, span_id, parent_id, origin) != SUCCESS)) {
        goto cleanup_origin;
    }

    zval inject_args[3] = {context, *format, *headers};
    zval retval = ddtrace_zval_undef();
    // $tracer->inject($context, Format::CURL_HTTP_HEADERS, $httpHeaders);
    result = ddtrace_call_method(Z_OBJ(tracer), Z_OBJ(tracer)->ce, NULL, ZEND_STRL("inject"), &retval, 3, inject_args);
    if (EXPECTED(result == SUCCESS)) {
        ZVAL_COPY_DEREF(headers, &inject_args[2]);
        zval_ptr_dtor(&inject_args[2]);
    }

    zval_ptr_dtor(&retval);
    zval_ptr_dtor(&context);
cleanup_origin:
    zval_ptr_dtor(&parent_id);
    zval_ptr_dtor(&span_id);
    zval_ptr_dtor(&trace_id);
    zval_ptr_dtor(&origin);
cleanup_active_context:
    zval_ptr_dtor(&active_context);
cleanup_active_span:
    zval_ptr_dtor(&active_span);
cleanup_tracer:
    zval_ptr_dtor(&tracer);
    return result;
}

// Assumes distributed tracing is enabled and curl_handle is valid; active_span may be null
static void dd_add_headers_to_curl_handle(zval *curl_handle, ddtrace_span_fci *active_span) {
    ddtrace_sandbox_backup backup = ddtrace_sandbox_begin();
    zval default_headers;
    array_init(&default_headers);
    zval http_headers = dd_ArrayKVStore_getForResource(curl_handle, &_dd_format_curl_http_headers, &default_headers);
    zval_ptr_dtor(&default_headers);

    if (EXPECTED(Z_TYPE(http_headers) == IS_ARRAY)) {
        zval *format = &_dd_format_curl_http_headers;
        if (dd_tracer_inject_helper(&http_headers, format, active_span ? &active_span->span : NULL) == SUCCESS) {
            zval setopt_args[3] = {*curl_handle, _dd_curl_httpheaders, http_headers};
            zval retval = ddtrace_zval_undef();
            // todo: cache curl_setopt lookup
            ddtrace_call_function(NULL, ZEND_STRL("curl_setopt"), &retval, 3, setopt_args);
            zval_ptr_dtor(&retval);
        }
    }
    zval_ptr_dtor(&http_headers);
    ddtrace_sandbox_end(&backup);
}

ZEND_FUNCTION(ddtrace_curl_exec) {
    zval *ch;

    if (le_curl && _dd_load_curl_integration() && Z_TYPE(_dd_curl_httpheaders) == IS_LONG &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "r", &ch) == SUCCESS) {
        void *resource = zend_fetch_resource(Z_RES_P(ch), NULL, le_curl);
        if (resource && ddtrace_config_distributed_tracing_enabled()) {
            dd_add_headers_to_curl_handle(ch, DDTRACE_G(open_spans_top));
        }
    }

    _dd_curl_exec_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}
/* }}} */

ZEND_FUNCTION(ddtrace_curl_init) {
    _dd_curl_init_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    if (Z_TYPE_P(return_value) == IS_RESOURCE) {
        if (!le_curl) {
            le_curl = Z_RES_TYPE_P(return_value);
        }
        if (_dd_load_curl_integration()) {
            _dd_ArrayKVStore_deleteResource(return_value);
        }
    }
}

ZEND_FUNCTION(ddtrace_curl_setopt) {
    zval *zid, *zvalue;
    zend_long option;

    if (!le_curl || !_dd_load_curl_integration() ||
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "rlz", &zid, &option, &zvalue) == FAILURE) {
        _dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    _dd_curl_setopt_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    ddtrace_sandbox_backup backup = ddtrace_sandbox_begin();

    bool call_succeeded = Z_TYPE_P(return_value) == IS_TRUE;
    bool option_matches = Z_TYPE(_dd_curl_httpheaders) == IS_LONG && Z_LVAL(_dd_curl_httpheaders) == option;
    if (call_succeeded && option_matches && ddtrace_config_distributed_tracing_enabled()) {
        _dd_ArrayKVStore_putForResource(zid, &_dd_format_curl_http_headers, zvalue);
    }

    ddtrace_sandbox_end(&backup);
}

ZEND_FUNCTION(ddtrace_curl_setopt_array) {
    zval *zid, *arr;

    if (le_curl && _dd_load_curl_integration() &&
        zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "ra", &zid, &arr) == SUCCESS) {
        ddtrace_sandbox_backup backup = ddtrace_sandbox_begin();

        void *resource = zend_fetch_resource(Z_RES_P(zid), NULL, le_curl);
        if (resource && ddtrace_config_distributed_tracing_enabled()) {
            if (Z_TYPE(_dd_curl_httpheaders) == IS_LONG) {
                zval *value = zend_hash_index_find(Z_ARR_P(arr), Z_LVAL(_dd_curl_httpheaders));
                if (value) {
                    _dd_ArrayKVStore_putForResource(zid, &_dd_format_curl_http_headers, value);
                }
            }
        }

        ddtrace_sandbox_end(&backup);
    }

    _dd_curl_setopt_array_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

struct _dd_curl_handler {
    const char *name;
    size_t name_len;
    void (**old_handler)(INTERNAL_FUNCTION_PARAMETERS);
    void (*new_handler)(INTERNAL_FUNCTION_PARAMETERS);
};
typedef struct _dd_curl_handler _dd_curl_handler;

static void _dd_install_handler(_dd_curl_handler handler) {
    zend_function *old_handler;
    old_handler = zend_hash_str_find_ptr(CG(function_table), handler.name, handler.name_len);
    if (old_handler != NULL) {
        *handler.old_handler = old_handler->internal_function.handler;
        old_handler->internal_function.handler = handler.new_handler;
    }
}

void ddtrace_curl_handlers_startup(void) {
    // if we cannot find ext/curl then do not instrument it
    zend_string *curl = zend_string_init(ZEND_STRL("curl"), 0);
    _dd_ext_curl_loaded = zend_hash_exists(&module_registry, curl);
    zend_string_release(curl);
    if (!_dd_ext_curl_loaded) {
        return;
    }

    /* We hook into curl_exec twice:
     *   - One that handles general dispatch so it will call the associated closure with curl_exec
     *   - One that handles the distributed tracing headers
     * The latter expects the former is already done because it needs a span id for the distributed tracing headers;
     * register them inside-out.
     */
    _dd_curl_handler handlers[] = {
        {ZEND_STRL("curl_close"), &_dd_curl_close_handler, ZEND_FN(ddtrace_curl_close)},
        {ZEND_STRL("curl_copy_handle"), &_dd_curl_copy_handle_handler, ZEND_FN(ddtrace_curl_copy_handle)},
        {ZEND_STRL("curl_exec"), &_dd_curl_exec_handler, ZEND_FN(ddtrace_curl_exec)},
        {ZEND_STRL("curl_init"), &_dd_curl_init_handler, ZEND_FN(ddtrace_curl_init)},
        {ZEND_STRL("curl_setopt"), &_dd_curl_setopt_handler, ZEND_FN(ddtrace_curl_setopt)},
        {ZEND_STRL("curl_setopt_array"), &_dd_curl_setopt_array_handler, ZEND_FN(ddtrace_curl_setopt_array)},
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        _dd_install_handler(handlers[i]);
    }

    if (ddtrace_resource != -1) {
        ddtrace_string curl_exec = DDTRACE_STRING_LITERAL("curl_exec");
        ddtrace_replace_internal_function(CG(function_table), curl_exec);
    }
}

void ddtrace_curl_handlers_rshutdown(void) {
    le_curl = 0;
    zval_ptr_dtor(&_dd_format_curl_http_headers);
    ZVAL_UNDEF(&_dd_curl_httpheaders);
    ZVAL_UNDEF(&_dd_format_curl_http_headers);
    _dd_ArrayKVStore_ce = NULL;
    _dd_GlobalTracer_ce = NULL;
    _dd_SpanContext_ce = NULL;
    _dd_ArrayKVStore_putForResource_fe = NULL;
    _dd_ArrayKVStore_getForResource_fe = NULL;
    _dd_ArrayKVStore_deleteResource_fe = NULL;
    _dd_GlobalTracer_get_fe = NULL;
    _dd_SpanContext_ctor = NULL;
    _dd_curl_integration_loaded = false;
}
