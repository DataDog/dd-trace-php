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
#include <tracer/ip_extraction.h>
#include <tracer/serializer.h>
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

static zend_string *_component_propname;
static THREAD_LOCAL_ON_ZTS bool _asm_event_emitted;
// Set by datadog\appsec\testing\set_remote_asm_features(); tests have no
// agent to deliver ASM_FEATURES. Each call sets the features of a remote
// config target, which becomes the current one.
struct asm_features_override {
    char target[64];
    ddog_AsmFeatures features;
};
static THREAD_LOCAL_ON_ZTS bool _asm_features_overridden;
static THREAD_LOCAL_ON_ZTS struct asm_features_override
    _asm_features_overrides[4];
static THREAD_LOCAL_ON_ZTS size_t _asm_features_override_count;
static THREAD_LOCAL_ON_ZTS size_t _asm_features_override_current;
static THREAD_LOCAL_ON_ZTS bool _asm_features_override_has_kept;
static THREAD_LOCAL_ON_ZTS size_t _asm_features_override_kept;

static void _register_testing_objects(void);

void dd_trace_startup(void)
{
    _component_propname = zend_string_init_interned(LSTRARG("component"), 1);

    if (get_global_DD_APPSEC_TESTING()) {
        _register_testing_objects();
    }
}

void dd_trace_root_span_changed(void) { _asm_event_emitted = false; }

bool dd_trace_enabled(void) { return get_DD_TRACE_ENABLED(); }

bool dd_trace_span_add_attribute(
    zend_object *nonnull span, zend_string *nonnull tag, zval *nonnull value)
{
    zval *attributes = dd_trace_span_get_attributes(span);
    if (!attributes) {
        if (!get_global_DD_APPSEC_TESTING()) {
            mlog(dd_log_warning, "Failed to retrieve root span attributes");
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

    if (zend_hash_add(Z_ARRVAL_P(attributes), tag, value) == NULL) {
        zval_ptr_dtor(value);
        return false;
    }

    return true;
}

bool dd_trace_span_add_attribute_str(zend_object *nonnull span,
    const char *nonnull tag, size_t tag_len, const char *nonnull value,
    size_t value_len)
{
    if (UNEXPECTED(value_len > INT_MAX)) {
        mlog(dd_log_warning, "Value for tag is too large");
        return false;
    }

    zval *attributes = dd_trace_span_get_attributes(span);
    if (!attributes) {
        if (!get_global_DD_APPSEC_TESTING()) {
            mlog(dd_log_warning, "Failed to retrieve root span attributes");
        }
        return false;
    }

    zend_string *ztag = zend_string_init(tag, tag_len, 0);

    zval zvalue;
    ZVAL_STRINGL(&zvalue, value, value_len);

    mlog(dd_log_debug, "Adding to root span the tag '%.*s' with value '%.*s'",
        (int)tag_len, tag, (int)value_len, value);

    bool res = zend_hash_add(Z_ARRVAL_P(attributes), ztag, &zvalue) != NULL;
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

// Spans are the tracer's own objects: read their properties directly. Like
// the tracer, (re)initialize the property if userland replaced the array.
static zval *nonnull _span_array_property(zval *nonnull prop)
{
    ZVAL_DEREF(prop);
    ddtrace_property_array(prop);
    return prop;
}

zval *nullable dd_trace_span_get_attributes(zend_object *nonnull zobj)
{
    return _span_array_property(&OBJ_SPANDATA(zobj)->property_attributes);
}

zend_string *nullable dd_trace_span_get_component(zend_object *nonnull zobj)
{
#if PHP_VERSION_ID >= 80600
    // 8.6 asserts type != BP_VAR_IS; BP_VAR_W matches the old IS path
    zval *res = zobj->handlers->get_property_ptr_ptr(
        zobj, _component_propname, BP_VAR_W, NULL);
#elif PHP_VERSION_ID >= 80000
    zval *res = zobj->handlers->get_property_ptr_ptr(
        zobj, _component_propname, BP_VAR_IS, NULL);
#else
    zval obj;
    ZVAL_OBJ(&obj, zobj);
    zval prop;
    ZVAL_STR(&prop, _component_propname);
    zval *res =
        zobj->handlers->get_property_ptr_ptr(&obj, &prop, BP_VAR_IS, NULL);
#endif
    if (res == NULL) {
        return NULL;
    }
    ZVAL_DEREF(res);
    return Z_TYPE_P(res) == IS_STRING ? Z_STR_P(res) : NULL;
}

zend_string *nullable dd_trace_get_formatted_runtime_id(bool persistent)
{
    return ddtrace_formatted_runtime_id(persistent);
}

ddog_AppsecBodyMessagePack dd_trace_parse_json_body(const char *nonnull data,
    size_t len, int max_depth, ddog_AppsecBodyAllocator allocator)
{
    return ddog_appsec_parse_json(
        (ddog_CharSlice){.ptr = data, .len = len}, max_depth, allocator);
}

ddog_AppsecBodyMessagePack dd_trace_parse_xml_body(const char *nonnull data,
    size_t len, int max_depth, ddog_AppsecBodyAllocator allocator)
{
    return ddog_appsec_parse_xml(
        (ddog_CharSlice){.ptr = data, .len = len}, max_depth, allocator);
}

void dd_trace_drop_body_msgpack(ddog_AppsecBodyMessagePack body)
{
    ddog_appsec_body_msgpack_drop(body);
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

zend_string *nonnull dd_trace_build_req_url(const zend_array *nonnull server)
{
    return ddtrace_build_req_url((zend_array *)server);
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

ddog_AsmFeatures dd_trace_get_asm_features(void)
{
    if (UNEXPECTED(_asm_features_overridden)) {
        return _asm_features_overrides[_asm_features_override_current].features;
    }
    if (!DATADOG_G(remote_config_state)) {
        return (ddog_AsmFeatures){0};
    }
    return ddog_remote_config_asm_features(DATADOG_G(remote_config_state));
}

void dd_trace_keep_asm_features_target(void)
{
    if (UNEXPECTED(_asm_features_overridden)) {
        _asm_features_override_kept = _asm_features_override_current;
        _asm_features_override_has_kept = true;
        return;
    }
    if (DATADOG_G(remote_config_state)) {
        ddog_remote_config_keep_asm_features_target(
            DATADOG_G(remote_config_state));
    }
}

ddog_AsmFeatures dd_trace_get_kept_asm_features(void)
{
    if (UNEXPECTED(_asm_features_overridden)) {
        size_t target = _asm_features_override_has_kept
                            ? _asm_features_override_kept
                            : _asm_features_override_current;
        return _asm_features_overrides[target].features;
    }
    if (!DATADOG_G(remote_config_state)) {
        return (ddog_AsmFeatures){0};
    }
    return ddog_remote_config_kept_asm_features(DATADOG_G(remote_config_state));
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

static PHP_FUNCTION(datadog_appsec_testing_root_span_add_attribute)
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
    bool result = dd_trace_span_add_attribute(root_span, tag, value);

    RETURN_BOOL(result);
}

static PHP_FUNCTION(datadog_appsec_testing_root_span_get_attributes) // NOLINT
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    __auto_type root_span = dd_trace_get_active_root_span();
    if (!root_span) {
        RETURN_NULL();
    }

    zval *attributes_zv = dd_trace_span_get_attributes(root_span);
    if (attributes_zv) {
        RETURN_ZVAL(attributes_zv, 1 /* copy */, 0 /* no destroy original */);
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

// Arguments: ?bool $asm_enabled (null: no ASM_FEATURES file sets it),
// string $auto_user_instrum (the RC mode, matched like the RC parser does),
// string $target (the remote config target the features are those of).
static PHP_FUNCTION(datadog_appsec_testing_set_remote_asm_features) // NOLINT
{
    zend_bool enabled = 0;
    zend_bool enabled_is_null = 1;
    zend_string *mode = NULL;
    zend_string *target = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "b!|SS", &enabled,
            &enabled_is_null, &mode, &target) == FAILURE) {
        RETURN_FALSE;
    }
    const char *target_name = target ? ZSTR_VAL(target) : "";
    size_t target_len = target ? ZSTR_LEN(target) : 0;
    if (target_len >= sizeof(_asm_features_overrides[0].target)) {
        php_error_docref(NULL, E_WARNING, "target is too long");
        RETURN_FALSE;
    }
    size_t index = 0;
    while (index < _asm_features_override_count &&
           strcmp(_asm_features_overrides[index].target, target_name) != 0) {
        index++;
    }
    if (index ==
        sizeof(_asm_features_overrides) / sizeof(_asm_features_overrides[0])) {
        php_error_docref(NULL, E_WARNING, "too many targets");
        RETURN_FALSE;
    }

    ddog_AsmFeatures features = {
        .activation = enabled_is_null ? DDOG_ASM_ACTIVATION_UNSET
                      : enabled       ? DDOG_ASM_ACTIVATION_ENABLED
                                      : DDOG_ASM_ACTIVATION_DISABLED,
        .auto_user_instrum = DDOG_ASM_AUTO_USER_INSTRUM_MODE_UNDEFINED,
    };
    if (mode) {
        static const struct {
            const char *name;
            size_t len;
            ddog_AsmAutoUserInstrumMode mode;
        } modes[] = {
            {ZEND_STRL("undefined"), DDOG_ASM_AUTO_USER_INSTRUM_MODE_UNDEFINED},
            {ZEND_STRL("disabled"), DDOG_ASM_AUTO_USER_INSTRUM_MODE_DISABLED},
            {ZEND_STRL("identification"),
                DDOG_ASM_AUTO_USER_INSTRUM_MODE_IDENTIFICATION},
            {ZEND_STRL("anonymization"),
                DDOG_ASM_AUTO_USER_INSTRUM_MODE_ANONYMIZATION},
        };
        features.auto_user_instrum = DDOG_ASM_AUTO_USER_INSTRUM_MODE_UNKNOWN;
        for (size_t i = 0; i < sizeof(modes) / sizeof(modes[0]); i++) {
            if (dd_string_equals_lc(ZSTR_VAL(mode), ZSTR_LEN(mode),
                    modes[i].name, modes[i].len)) {
                features.auto_user_instrum = modes[i].mode;
                break;
            }
        }
    }

    if (index == _asm_features_override_count) {
        memcpy(
            _asm_features_overrides[index].target, target_name, target_len + 1);
        _asm_features_override_count++;
    }
    _asm_features_overrides[index].features = features;
    _asm_features_override_current = index;
    _asm_features_overridden = true;
    RETURN_TRUE;
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_bool_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_nullable_array, 0, 0, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

    ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_nullable_string, 0, 0, IS_STRING, 1)
ZEND_END_ARG_INFO()


ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_set_remote_asm_features, 0, 1, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, asm_enabled, _IS_BOOL, 1)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, auto_user_instrum, IS_STRING, 0, "\"undefined\"")
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, target, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_root_span_add_attribute, 0, 0, _IS_BOOL, 2)
ZEND_ARG_TYPE_INFO(0, tag, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "ddtrace_rshutdown", PHP_FN(datadog_appsec_testing_ddtrace_rshutdown), void_ret_bool_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "root_span_add_attribute", PHP_FN(datadog_appsec_testing_root_span_add_attribute), arginfo_root_span_add_attribute, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "root_span_get_attributes", PHP_FN(datadog_appsec_testing_root_span_get_attributes), void_ret_nullable_array, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "get_formatted_runtime_id", PHP_FN(datadog_appsec_testing_get_formatted_runtime_id), void_ret_nullable_string, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "set_remote_asm_features", PHP_FN(datadog_appsec_testing_set_remote_asm_features), arginfo_set_remote_asm_features, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void) { dd_phpobj_reg_funcs(functions); }
