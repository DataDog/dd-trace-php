// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "ddtrace.h"
#include <fcntl.h>
#include <unistd.h>

#include "compatibility.h"
#include "configuration.h"
#include "ddappsec.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "string_helpers.h"
#include <Zend/zend_extensions.h>

void (*nullable ddtrace_metric_register_buffer)(
    zend_string *nonnull name, ddtrace_metric_type type, ddtrace_metric_ns ns);
bool (*nullable ddtrace_metric_add_point)(
    zend_string *nonnull name, double value, zend_string *nonnull tags);

static int (*_orig_ddtrace_shutdown)(SHUTDOWN_FUNC_ARGS);
static int _mod_type;
static int _mod_number;
static const char *_mod_version;
static bool _ddtrace_loaded;
static zend_string *_ddtrace_root_span_fname;
static zend_string *_meta_propname;
static zend_string *_metrics_propname;
static zend_string *_meta_struct_propname;
static THREAD_LOCAL_ON_ZTS bool _suppress_ddtrace_rshutdown;
static uint8_t *_ddtrace_runtime_id = NULL;

static void _setup_testing_telemetry_functions(void);
static zend_module_entry *_find_ddtrace_module(void);
static int _ddtrace_rshutdown_testing(SHUTDOWN_FUNC_ARGS);
static void _register_testing_objects(void);

static zend_object *(*nullable _ddtrace_get_root_span)(void);
static void (*nullable _ddtrace_close_all_spans_and_flush)(void);
static void (*nullable _ddtrace_set_priority_sampling_on_span_zobj)(
    zend_object *nonnull zobj, zend_long priority,
    enum dd_sampling_mechanism mechanism);
static zend_long (*nullable _ddtrace_get_priority_sampling_on_span_zobj)(
    zend_object *nonnull root_span);
static void (*nullable _ddtrace_add_propagated_tag_on_span_zobj)(
    zend_string *nonnull key, zval *nonnull value);

static bool (*nullable _ddtrace_user_req_add_listeners)(
    ddtrace_user_req_listeners *listeners);

static zend_string *(*_ddtrace_ip_extraction_find)(zval *server);

static const char *nullable (*_ddtrace_remote_config_get_path)(void);
static void *(*nullable _ddtrace_emit_asm_event)(void);

static void _test_ddtrace_metric_register_buffer(
    zend_string *nonnull name, ddtrace_metric_type type, ddtrace_metric_ns ns);
static bool _test_ddtrace_metric_add_point(
    zend_string *nonnull name, double value, zend_string *nonnull tags);

static void dd_trace_load_symbols(zend_module_entry *module)
{
    bool testing = get_global_DD_APPSEC_TESTING();
    // prefer loading directly from the ddtrace zend_extension
    zend_extension *ddtrace = zend_get_extension("ddtrace");
    DL_HANDLE handle = ddtrace ? ddtrace->handle : module->handle;
    bool manually_opened = false;
    if (!handle) {
        // fallback, e.g. with static build
        handle = dlopen(NULL, RTLD_NOW | RTLD_GLOBAL);
        manually_opened = true;
        if (!handle) {
            if (!testing) {
                mlog(dd_log_error, "Failed to acquire handle for ddtrace");
            }
            return;
        }
    }

#define ASSIGN_DLSYM(var, export)                                              \
    do {                                                                       \
        (var) = (typeof(var))(uintptr_t)dlsym(handle, export "");              \
        if ((var) == NULL && !testing) {                                       \
            /* NOLINTNEXTLINE(concurrency-mt-unsafe) */                        \
            mlog(dd_log_error, "Failed to load %s: %s", export, dlerror());    \
        }                                                                      \
    } while (0)

    ASSIGN_DLSYM(_ddtrace_close_all_spans_and_flush,
        "ddtrace_close_all_spans_and_flush");
    ASSIGN_DLSYM(_ddtrace_get_root_span, "ddtrace_get_root_span");
    ASSIGN_DLSYM(_ddtrace_runtime_id, "ddtrace_runtime_id");
    ASSIGN_DLSYM(_ddtrace_set_priority_sampling_on_span_zobj,
        "ddtrace_set_priority_sampling_on_span_zobj");
    ASSIGN_DLSYM(_ddtrace_get_priority_sampling_on_span_zobj,
        "ddtrace_get_priority_sampling_on_span_zobj");
    ASSIGN_DLSYM(_ddtrace_add_propagated_tag_on_span_zobj,
        "ddtrace_add_propagated_tag_on_span_zobj");
    ASSIGN_DLSYM(
        _ddtrace_user_req_add_listeners, "ddtrace_user_req_add_listeners");
    ASSIGN_DLSYM(_ddtrace_ip_extraction_find, "ddtrace_ip_extraction_find");
    ASSIGN_DLSYM(
        _ddtrace_remote_config_get_path, "ddtrace_remote_config_get_path");
    ASSIGN_DLSYM(
        ddtrace_metric_register_buffer, "ddtrace_metric_register_buffer");
    ASSIGN_DLSYM(ddtrace_metric_add_point, "ddtrace_metric_add_point");
    ASSIGN_DLSYM(_ddtrace_emit_asm_event, "ddtrace_emit_asm_event");

    if (manually_opened) {
        dlclose(handle);
    }
}

void dd_trace_startup(void)
{
    _ddtrace_root_span_fname = zend_string_init_interned(
        LSTRARG("ddtrace\\root_span"), 1 /* permanent */);
    _meta_propname = zend_string_init_interned(LSTRARG("meta"), 1);
    _metrics_propname = zend_string_init_interned(LSTRARG("metrics"), 1);
    _meta_struct_propname =
        zend_string_init_interned(LSTRARG("meta_struct"), 1);

    if (get_global_DD_APPSEC_TESTING()) {
        _register_testing_objects();
        _setup_testing_telemetry_functions();
    }

    zend_module_entry *mod = _find_ddtrace_module();
    if (mod == NULL) {
        mlog(dd_log_debug, "Cannot find ddtrace extension");
        _ddtrace_loaded = false;
        return;
    }

    _ddtrace_loaded = true;
    _mod_type = mod->type;
    _mod_number = mod->module_number;
    _mod_version = mod->version;

    dd_trace_load_symbols(mod);

    if (get_global_DD_APPSEC_TESTING()) {
        _orig_ddtrace_shutdown = mod->request_shutdown_func;
        mod->request_shutdown_func = _ddtrace_rshutdown_testing;
    }
}

static void _setup_testing_telemetry_functions(void)
{
    if (ddtrace_metric_register_buffer == NULL) {
        ddtrace_metric_register_buffer = _test_ddtrace_metric_register_buffer;
    }
    if (ddtrace_metric_add_point == NULL) {
        ddtrace_metric_add_point = _test_ddtrace_metric_add_point;
    }
}

static zend_module_entry *_find_ddtrace_module(void)
{
    zend_string *ddtrace_name =
        zend_string_init("ddtrace", LSTRLEN("ddtrace"), 0 /* persistent */);
    zend_module_entry *mod = zend_hash_find_ptr(&module_registry, ddtrace_name);
    zend_string_free(ddtrace_name);
    return mod;
}

void dd_trace_shutdown(void)
{
    zend_module_entry *mod = _find_ddtrace_module();
    if (mod && _orig_ddtrace_shutdown) {
        mod->request_shutdown_func = _orig_ddtrace_shutdown;
    }
    _orig_ddtrace_shutdown = NULL;
    _mod_type = 0;
    _mod_number = 0;
}

static int _ddtrace_rshutdown_testing(SHUTDOWN_FUNC_ARGS)
{
    if (_suppress_ddtrace_rshutdown) {
        _suppress_ddtrace_rshutdown = false;
        return SUCCESS;
    }

    return _orig_ddtrace_shutdown(SHUTDOWN_FUNC_ARGS_PASSTHRU);
}

const char *nullable dd_trace_version(void) { return _mod_version; }

bool dd_trace_loaded(void) { return _ddtrace_loaded; }
bool dd_trace_enabled(void)
{
    return _ddtrace_loaded && get_DD_TRACE_ENABLED();
}

bool dd_trace_span_add_tag(
    zend_object *nonnull span, zend_string *nonnull tag, zval *nonnull value)
{
    zval *meta = dd_trace_span_get_meta(span);
    if (!meta) {
        if (!get_global_DD_APPSEC_TESTING()) {
            mlog(dd_log_warning, "Failed to retrieve root span meta");
        }
        zval_ptr_dtor(value);
        return false;
    }

    if (Z_TYPE_P(value) == IS_STRING) {
        mlog(dd_log_debug, "Adding to root span the tag '%s' with value '%s'",
            ZSTR_VAL(tag), Z_STRVAL_P(value));
    } else {
        mlog(dd_log_debug, "Adding to root span the tag '%s'", ZSTR_VAL(tag));
    }

    if (zend_hash_add(Z_ARRVAL_P(meta), tag, value) == NULL) {
        zval_ptr_dtor(value);
        return false;
    }

    return true;
}

bool dd_trace_span_add_tag_str(zend_object *nonnull span,
    const char *nonnull tag, size_t tag_len, const char *nonnull value,
    size_t value_len)
{
    if (UNEXPECTED(value_len > INT_MAX)) {
        mlog(dd_log_warning, "Value for tag is too large");
        return false;
    }

    zval *meta = dd_trace_span_get_meta(span);
    if (!meta) {
        if (!get_global_DD_APPSEC_TESTING()) {
            mlog(dd_log_warning, "Failed to retrieve root span meta");
        }
        return false;
    }

    zend_string *ztag = zend_string_init(tag, tag_len, 0);

    zval zvalue;
    ZVAL_STRINGL(&zvalue, value, value_len);

    mlog(dd_log_debug, "Adding to root span the tag '%.*s' with value '%.*s'",
        (int)tag_len, tag, (int)value_len, value);

    bool res = zend_hash_add(Z_ARRVAL_P(meta), ztag, &zvalue) != NULL;
    zend_string_release(ztag);

    if (!res) {
        mlog(dd_log_info,
            "Failed adding the tag '%.*s': no root span or it already exists",
            (int)tag_len, tag);
        zval_ptr_dtor(&zvalue);
        return false;
    }

    return true;
}

void dd_trace_close_all_spans_and_flush(void)
{
    if (UNEXPECTED(_ddtrace_close_all_spans_and_flush == NULL)) {
        mlog_g(dd_log_debug,
            "Skipping flushing tracer; ddtrace symbol unresolved");
        return;
    }

    (*_ddtrace_close_all_spans_and_flush)();
}

static zval *_get_span_modifiable_array_property(
    zend_object *nonnull zobj, zend_string *nonnull propname)
{
#if PHP_VERSION_ID >= 80000
    zval *res =
        zobj->handlers->get_property_ptr_ptr(zobj, propname, BP_VAR_IS, NULL);
#else
    zval obj;
    ZVAL_OBJ(&obj, zobj);
    zval prop;
    ZVAL_STR(&prop, propname);
    zval *res =
        zobj->handlers->get_property_ptr_ptr(&obj, &prop, BP_VAR_IS, NULL);

#endif

    if (Z_TYPE_P(res) == IS_REFERENCE) {
        ZVAL_DEREF(res);
        if (Z_TYPE_P(res) == IS_ARRAY) {
            return res;
        }
        return NULL;
    }
    if (Z_TYPE_P(res) != IS_ARRAY) {
        return NULL;
    }

    SEPARATE_ZVAL_NOREF(res);

    return res;
}

zval *nullable dd_trace_span_get_meta(zend_object *nonnull zobj)
{
    return _get_span_modifiable_array_property(zobj, _meta_propname);
}

zval *nullable dd_trace_span_get_metrics(zend_object *nonnull zobj)
{
    return _get_span_modifiable_array_property(zobj, _metrics_propname);
}

zval *nullable dd_trace_span_get_meta_struct(zend_object *nonnull zobj)
{
    return _get_span_modifiable_array_property(zobj, _meta_struct_propname);
}

// NOLINTBEGIN(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
zend_string *nullable dd_trace_get_formatted_runtime_id(bool persistent)
{
    if (_ddtrace_runtime_id == NULL) {
        return NULL;
    }

    zend_string *encoded_id = zend_string_alloc(36, persistent);

    size_t length = sprintf(ZSTR_VAL(encoded_id),
        "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
        _ddtrace_runtime_id[0], _ddtrace_runtime_id[1], _ddtrace_runtime_id[2],
        _ddtrace_runtime_id[3], _ddtrace_runtime_id[4], _ddtrace_runtime_id[5],
        _ddtrace_runtime_id[6], _ddtrace_runtime_id[7], _ddtrace_runtime_id[8],
        _ddtrace_runtime_id[9], _ddtrace_runtime_id[10],
        _ddtrace_runtime_id[11], _ddtrace_runtime_id[12],
        _ddtrace_runtime_id[13], _ddtrace_runtime_id[14],
        _ddtrace_runtime_id[15]);

    if (length != 36) {
        zend_string_free(encoded_id);
        encoded_id = NULL;
    }

    return encoded_id;
}
// NOLINTEND(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)

void dd_trace_set_priority_sampling_on_span_zobj(zend_object *nonnull root_span,
    zend_long priority, enum dd_sampling_mechanism mechanism)
{
    if (_ddtrace_set_priority_sampling_on_span_zobj == NULL) {
        return;
    }

    _ddtrace_set_priority_sampling_on_span_zobj(root_span, priority, mechanism);
}

zend_long dd_trace_get_priority_sampling_on_span_zobj(
    zend_object *nonnull root_span)
{
    if (_ddtrace_set_priority_sampling_on_span_zobj == NULL) {
        return -1;
    }

    return _ddtrace_get_priority_sampling_on_span_zobj(root_span);
}

bool dd_trace_user_req_add_listeners(
    ddtrace_user_req_listeners *nonnull listeners)
{
    if (_ddtrace_user_req_add_listeners == NULL) {
        return false;
    }

    return _ddtrace_user_req_add_listeners(listeners);
}

zend_object *nullable dd_trace_get_active_root_span(void)
{
    if (UNEXPECTED(_ddtrace_get_root_span == NULL)) {
        return NULL;
    }

    return _ddtrace_get_root_span();
}

zend_string *nullable dd_ip_extraction_find(zval *nonnull server)
{
    if (!_ddtrace_ip_extraction_find) {
        return NULL;
    }
    return _ddtrace_ip_extraction_find(server);
}

const char *nullable dd_trace_remote_config_get_path(void)
{
    if (!_ddtrace_remote_config_get_path) {
        return NULL;
    }
    __auto_type path = _ddtrace_remote_config_get_path();
    mlog(dd_log_trace, "Remote config path: %s", path ? path : "(unset)");
    return path;
}

void dd_trace_span_add_propagated_tags(
    zend_string *nonnull key, zval *nonnull value)
{
    if (UNEXPECTED(_ddtrace_add_propagated_tag_on_span_zobj == NULL)) {
        return;
    }

    _ddtrace_add_propagated_tag_on_span_zobj(key, value);
}

void dd_trace_emit_asm_event(void)
{
    if (UNEXPECTED(_ddtrace_emit_asm_event == NULL)) {
        return;
    }

    mlog(dd_log_trace, "Emitting ASM event");

    _ddtrace_emit_asm_event();
}

static PHP_FUNCTION(datadog_appsec_testing_ddtrace_rshutdown)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    if (!_orig_ddtrace_shutdown) {
        mlog(dd_log_warning,
            "ddtrace was not searched or found during startup; skipping");
        RETURN_FALSE;
    }

    _suppress_ddtrace_rshutdown = true;
    mlog(dd_log_debug, "Calling ddtrace's RSHUTDOWN");
    int res = _orig_ddtrace_shutdown(_mod_type, _mod_number);
    if (res == SUCCESS) {
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}

static PHP_FUNCTION(datadog_appsec_testing_root_span_add_tag)
{
    zend_string *tag = NULL;
    zval *value = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "Sz", &tag, &value) != SUCCESS) {
        RETURN_FALSE;
    }

    if (!tag || !value || Z_TYPE_P(value) != IS_STRING) {
        RETURN_FALSE;
    }

    __auto_type root_span = dd_trace_get_active_root_span();
    if (!root_span) {
        RETURN_FALSE;
    }

    Z_TRY_ADDREF_P(value);
    bool result = dd_trace_span_add_tag(root_span, tag, value);

    RETURN_BOOL(result);
}

static PHP_FUNCTION(datadog_appsec_testing_root_span_get_meta) // NOLINT
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    __auto_type root_span = dd_trace_get_active_root_span();
    if (!root_span) {
        RETURN_NULL();
    }

    zval *meta_zv = dd_trace_span_get_meta(root_span);
    if (meta_zv) {
        RETURN_ZVAL(meta_zv, 1 /* copy */, 0 /* no destroy original */);
    }
}

static PHP_FUNCTION(datadog_appsec_testing_root_span_get_meta_struct) // NOLINT
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    __auto_type root_span = dd_trace_get_active_root_span();
    if (!root_span) {
        RETURN_NULL();
    }

    zval *meta_struct_zv = dd_trace_span_get_meta_struct(root_span);
    if (meta_struct_zv) {
        RETURN_ZVAL(meta_struct_zv, 1 /* copy */, 0 /* no destroy original */);
    }
}

static PHP_FUNCTION(datadog_appsec_testing_root_span_get_metrics) // NOLINT
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    __auto_type root_span = dd_trace_get_active_root_span();
    if (!root_span) {
        RETURN_NULL();
    }

    zval *metrics_zv = dd_trace_span_get_metrics(root_span);
    if (metrics_zv) {
        RETURN_ZVAL(metrics_zv, 1 /* copy */, 0 /* no destroy original */);
    }
}

static PHP_FUNCTION(datadog_appsec_testing_get_formatted_runtime_id) // NOLINT
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    zend_string *id = dd_trace_get_formatted_runtime_id(false);
    if (id != NULL) {
        RETURN_STR(id);
    }
    RETURN_EMPTY_STRING();
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_bool_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_nullable_array, 0, 0, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

    ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_nullable_string, 0, 0, IS_STRING, 1)
ZEND_END_ARG_INFO()


ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_root_span_add_tag, 0, 0, _IS_BOOL, 2)
ZEND_ARG_TYPE_INFO(0, tag, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "ddtrace_rshutdown", PHP_FN(datadog_appsec_testing_ddtrace_rshutdown), void_ret_bool_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "root_span_add_tag", PHP_FN(datadog_appsec_testing_root_span_add_tag), arginfo_root_span_add_tag, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "root_span_get_meta", PHP_FN(datadog_appsec_testing_root_span_get_meta), void_ret_nullable_array, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "root_span_get_meta_struct", PHP_FN(datadog_appsec_testing_root_span_get_meta_struct), void_ret_nullable_array, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "root_span_get_metrics", PHP_FN(datadog_appsec_testing_root_span_get_metrics), void_ret_nullable_array, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "get_formatted_runtime_id", PHP_FN(datadog_appsec_testing_get_formatted_runtime_id), void_ret_nullable_string, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void) { dd_phpobj_reg_funcs(functions); }

static void _test_ddtrace_metric_register_buffer(
    zend_string *nonnull name, ddtrace_metric_type type, ddtrace_metric_ns ns)
{
    php_error_docref(NULL, E_NOTICE,
        "Would call ddtrace_metric_register_buffer with name=%.*s "
        "type=%d ns=%d",
        (int)ZSTR_LEN(name), ZSTR_VAL(name), type, ns);
}
static bool _test_ddtrace_metric_add_point(
    zend_string *nonnull name, double value, zend_string *nonnull tags)
{
    php_error_docref(NULL, E_NOTICE,
        "Would call to ddtrace_metric_add_point with name=%.*s value=%f "
        "tags=%.*s",
        (int)ZSTR_LEN(name), ZSTR_VAL(name), value, (int)ZSTR_LEN(tags),
        ZSTR_VAL(tags));
    return true;
}
