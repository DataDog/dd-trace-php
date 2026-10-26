// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "tracer_bridge.h"
#include <components-rs/common.h>
#include <components-rs/datadog.h>
#include <components-rs/sidecar.h>
#include <fcntl.h>
#include <stdlib.h>
#include <string.h>
#include <tracer/asm_event.h>
#include <tracer/auto_flush.h>
#include <tracer/endpoint_guessing.h>
#include <tracer/ip_extraction.h>
#include <tracer/span.h>
#include <tracer/tracer_tag_propagation/tracer_tag_propagation.h>
#include <unistd.h>

#include "configuration.h"
#include "ddappsec.h"
#include "logging.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "string_helpers.h"
#include <ext/compatibility.h>
#ifdef TESTING
#    include "test_mock_transport.h"
#endif

static zend_string *_meta_propname;
static zend_string *_metrics_propname;
static zend_string *_meta_struct_propname;
static THREAD_LOCAL_ON_ZTS bool _asm_event_emitted;

static void _register_testing_objects(void);

void dd_trace_startup(void)
{
    _meta_propname = zend_string_init_interned(LSTRARG("meta"), 1);
    _metrics_propname = zend_string_init_interned(LSTRARG("metrics"), 1);
    _meta_struct_propname =
        zend_string_init_interned(LSTRARG("meta_struct"), 1);

    if (get_global_DD_APPSEC_TESTING()) {
        _register_testing_objects();
#ifdef TESTING
        dd_testing_setup_mock_transport();
#endif
    }
}

void dd_trace_rinit(void) { _asm_event_emitted = false; }

void dd_trace_shutdown(void) {}

bool dd_trace_enabled(void) { return get_DD_TRACE_ENABLED(); }

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
    ddtrace_close_all_spans_and_flush();
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

zend_string *nullable dd_trace_get_formatted_runtime_id(bool persistent)
{
    enum { runtime_id_len = 36 };
    zend_string *encoded_id = zend_string_alloc(runtime_id_len, persistent);
    datadog_format_runtime_id(
        (uint8_t (*)[runtime_id_len])ZSTR_VAL(encoded_id));
    ZSTR_VAL(encoded_id)[runtime_id_len] = '\0';
    return encoded_id;
}

ddog_AppsecCResponse dd_trace_send_appsec_message(
    const uint8_t *nonnull request, size_t request_len, bool client_init)
{
#ifdef TESTING
    if (dd_testing_send_active) {
        ddog_CharSlice data = {
            .ptr = (const char *)request,
            .len = request_len,
        };
        return dd_testing_mock_send_appsec_message(data);
    }
#endif

    if (DATADOG_G(sidecar) == NULL) {
        mlog_once(dd_log_warning,
            "Could not communicate with the helper. Sidecar is not connected");
        return (ddog_AppsecCResponse){0};
    }

    ddog_CharSlice request_slice = {
        .ptr = (const char *)request,
        .len = request_len,
    };

    if (client_init) {
        return ddog_sidecar_send_appsec_message(
            &DATADOG_G(sidecar), request_slice, true);
    }
    return datadog_sidecar_send_appsec_message_without_reconnect(
        &DATADOG_G(sidecar), request_slice, false);
}

ddog_AppsecBodyTape dd_trace_parse_json_body(
    const char *nonnull data, size_t len, int max_depth)
{
    return ddog_appsec_parse_json(
        (ddog_CharSlice){.ptr = data, .len = len}, max_depth);
}

ddog_AppsecBodyTape dd_trace_parse_xml_body(
    const char *nonnull data, size_t len, int max_depth)
{
    return ddog_appsec_parse_xml(
        (ddog_CharSlice){.ptr = data, .len = len}, max_depth);
}

void dd_trace_drop_body_tape(ddog_AppsecBodyTape tape)
{
    ddog_appsec_body_tape_drop(tape);
}

void dd_trace_free_appsec_message_response(ddog_AppsecCResponse response)
{
    if (response.ptr == NULL) {
        return;
    }
#ifdef TESTING
    if (dd_testing_send_active) {
        free(response.ptr);
        return;
    }
#endif
    ddog_sidecar_appsec_response_drop(response);
}

void dd_trace_set_priority_sampling_on_span_zobj(zend_object *nonnull root_span,
    zend_long priority, enum dd_sampling_mechanism mechanism)
{
    ddtrace_set_priority_sampling_on_span_zobj(root_span, priority, mechanism);
}

zend_long dd_trace_get_priority_sampling_on_span_zobj(
    zend_object *nonnull root_span)
{
    return ddtrace_get_priority_sampling_on_span_zobj(root_span);
}

bool dd_trace_user_req_add_listeners(
    ddtrace_user_req_listeners *nonnull listeners)
{
    return ddtrace_user_req_add_listeners(listeners);
}

zend_object *nullable dd_trace_get_active_root_span(void)
{
    return ddtrace_get_root_span();
}

zend_string *nullable dd_ip_extraction_find(zval *nonnull server)
{
    return ddtrace_ip_extraction_find(server);
}

struct telemetry_rc_info dd_trace_get_telemetry_rc_info(void)
{
    __auto_type tel_rc_info = datadog_get_telemetry_rc_info();

    mlog(dd_log_trace,
        "Remote config path: %s, service name: %.*s, env name: %.*s",
        tel_rc_info.rc_path ? tel_rc_info.rc_path : "(unset)",
        ZSTR_PRINTF(tel_rc_info.service_name),
        ZSTR_PRINTF(tel_rc_info.env_name));

    return tel_rc_info;
}

void dd_trace_span_add_propagated_tags(
    zend_string *nonnull key, zval *nonnull value)
{
    ddtrace_add_propagated_tag_on_span_zobj(key, value);
}

void dd_trace_emit_asm_event(void)
{
    if (_asm_event_emitted) {
        return;
    }

    mlog(dd_log_trace, "Emitting ASM event");

    ddtrace_emit_asm_event();
    _asm_event_emitted = true;
}

zend_string *dd_trace_guess_endpoint_from_url(
    const char *nonnull url, size_t url_len)
{
    return ddtrace_guess_endpoint_from_url(url, url_len);
}

static PHP_FUNCTION(datadog_appsec_testing_ddtrace_rshutdown)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    if (DATADOG_G(rshutdown_products_done)) {
        RETURN_FALSE;
    }

    mlog(dd_log_debug, "Calling ddtrace's RSHUTDOWN");
    RETURN_BOOL(datadog_rshutdown_products(datadog_module->type,
                    datadog_module->module_number) == SUCCESS);
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
