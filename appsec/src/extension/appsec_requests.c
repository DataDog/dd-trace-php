// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "appsec_requests.h"
#include "backtrace.h"
#include "configuration.h"
#include "ddappsec.h"
#include "logging.h"
#include "php_objects.h"
#include "request_abort.h"
#include "request_lifecycle.h"
#include "string_helpers.h"
#include "tags.h"
#include "telemetry.h"
#include "tracer_bridge.h"
#include <components-rs/datadog.h>
#include <ext/compatibility.h>
#include <fcntl.h>
#include <unistd.h>

static THREAD_LOCAL_ON_ZTS bool _request_started;

// Whether the helper was last reachable, to count changes for telemetry.
typedef enum {
    helper_state_unknown = 0,
    helper_state_reachable,
    helper_state_unreachable,
} helper_state;
static THREAD_LOCAL_ON_ZTS helper_state _helper_state;

static THREAD_LOCAL_ON_ZTS int _mock_fd = -1;

static void _register_testing_objects(void);

void dd_appsec_requests_startup(void)
{
    if (get_global_DD_APPSEC_TESTING()) {
        _register_testing_objects();
    }
}

bool dd_appsec_request_started(void) { return _request_started; }

void dd_appsec_request_forget(void) { _request_started = false; }

void dd_appsec_requests_mark_started(void) { _request_started = true; }

static ddog_SidecarTransport *nullable *nonnull _transport(void)
{
    return &DATADOG_G(sidecar);
}

static ddog_CharSlice _slice(const zend_string *nullable zstr)
{
    return zstr ? (ddog_CharSlice){.ptr = ZSTR_VAL(zstr), .len = ZSTR_LEN(zstr)}
                : (ddog_CharSlice){.ptr = "", .len = 0};
}

static bool _str_eq(ddog_CharSlice s, const char *nonnull lit, size_t len)
{
    return s.len == len && memcmp(s.ptr, lit, len) == 0;
}
#define _lstr_eq(s, lit) _str_eq(s, "" lit, sizeof(lit) - 1)

static void _set_block_code_and_type(struct block_params *nonnull block_params,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    int code, dd_response_type type, zend_string *nullable security_response_id)
{
    block_params->security_response_id = security_response_id;
    block_params->response_type = type;
    block_params->response_code = code;
    block_params->redirection_location = NULL;
}

static int _parse_status_code(ddog_CharSlice value)
{
    if (value.len != 3) {
        mlog(dd_log_warning, "Invalid http status code received %.*s",
            (int)value.len, value.ptr);
        return -1;
    }
    char code_str[4] = {0};
    memcpy(code_str, value.ptr, 3);
    const int base = 10;
    return (int)strtol(code_str, NULL, base);
}

static void _process_block_parameters(struct block_params *nonnull block_params,
    const ddog_AppSecActionView *nonnull action)
{
    int status_code = DEFAULT_BLOCKING_RESPONSE_CODE;
    dd_response_type type = DEFAULT_RESPONSE_TYPE;
    zend_string *security_response_id = NULL;

    for (size_t i = 0; i < action->parameters_len; i++) {
        ddog_CharSlice key = action->parameters[i].key;
        ddog_CharSlice value = action->parameters[i].value;

        if (_lstr_eq(key, "status_code")) {
            int parsed_value = _parse_status_code(value);
            // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
            if (parsed_value > 99 && parsed_value < 1000) {
                status_code = parsed_value;
            }
        } else if (_lstr_eq(key, "type")) {
            if (_lstr_eq(value, "json")) {
                type = response_type_json;
            } else if (_lstr_eq(value, "html")) {
                type = response_type_html;
            } else if (_lstr_eq(value, "auto")) {
                type = response_type_auto;
            } else {
                mlog(dd_log_warning, "Invalid http content-type received %.*s",
                    (int)value.len, value.ptr);
            }
        } else if (_lstr_eq(key, "security_response_id") &&
                   !security_response_id) {
            security_response_id = zend_string_init(value.ptr, value.len, 0);
        }
    }

    mlog(dd_log_debug,
        "Blocking parameters: status_code=%d, type=%d, security_response_id=%s",
        status_code, type,
        security_response_id ? ZSTR_VAL(security_response_id) : "NULL");
    _set_block_code_and_type(
        block_params, status_code, type, security_response_id);
}

static void _process_redirect_parameters(
    struct block_params *nonnull block_params,
    const ddog_AppSecActionView *nonnull action)
{
    int status_code = 0;
    zend_string *location = NULL;
    zend_string *security_response_id = NULL;

    for (size_t i = 0; i < action->parameters_len; i++) {
        ddog_CharSlice key = action->parameters[i].key;
        ddog_CharSlice value = action->parameters[i].value;

        if (_lstr_eq(key, "status_code")) {
            int parsed_value = _parse_status_code(value);
            if (parsed_value >= 0) {
                status_code = parsed_value;
            }
        } else if (_lstr_eq(key, "location") && !location) {
            location = zend_string_init(value.ptr, value.len, 0);
        } else if (_lstr_eq(key, "security_response_id") &&
                   !security_response_id) {
            security_response_id = zend_string_init(value.ptr, value.len, 0);
        }
    }

    mlog(dd_log_debug,
        "Redirect parameters: status_code=%d, location=%s, "
        "security_response_id=%s",
        status_code, location ? ZSTR_VAL(location) : "NULL",
        security_response_id ? ZSTR_VAL(security_response_id) : "NULL");

    block_params->security_response_id = security_response_id;
    block_params->response_type = response_type_auto;
    block_params->response_code = status_code;
    block_params->redirection_location = location;
}

static void _process_stack_trace_parameters(
    const ddog_AppSecActionView *nonnull action)
{
    for (size_t i = 0; i < action->parameters_len; i++) {
        if (_lstr_eq(action->parameters[i].key, "stack_id")) {
            ddog_CharSlice value = action->parameters[i].value;
            zend_string *id = zend_string_init(value.ptr, value.len, 0);
            dd_report_exploit_backtrace(id);
            zend_string_release(id);
            break;
        }
    }
}

static dd_result _process_actions(
    const ddog_AppSecVerdictView *nonnull view, struct req_info *nonnull ctx)
{
    dd_result res = dd_success;

    for (size_t i = 0; i < view->actions_len; i++) {
        const ddog_AppSecActionView *action = &view->actions[i];
        mlog(dd_log_debug, "Verdict of %s was '%.*s'",
            ctx->command_name ? ctx->command_name : "(unknown)",
            (int)action->action.len, action->action.ptr);

        // Redirect takes over block, block over record
        if (_lstr_eq(action->action, "block") && res != dd_should_block &&
            res != dd_should_redirect) {
            res = dd_should_block;
            _process_block_parameters(&ctx->block_params, action);
            dd_req_lifecycle_set_blocked();
        } else if (_lstr_eq(action->action, "redirect") &&
                   res != dd_should_redirect) {
            if (res == dd_should_block) {
                dd_request_abort_destroy_block_params(&ctx->block_params);
            }
            res = dd_should_redirect;
            _process_redirect_parameters(&ctx->block_params, action);
            dd_req_lifecycle_set_blocked();
        } else if (_lstr_eq(action->action, "record") && res == dd_success) {
            res = dd_should_record;
        } else if (_lstr_eq(action->action, "stack_trace")) {
            _process_stack_trace_parameters(action);
        }
    }

    return res;
}

static void _add_appsec_span_data(const ddog_AppSecVerdictView *nonnull view)
{
    for (size_t i = 0; i < view->triggers_len; i++) {
        ddog_CharSlice frag = view->triggers[i];
        if (frag.len == 0) {
            mlog(dd_log_warning, "Empty appsec event data. Bug");
            continue;
        }
        if (frag.len >= DD_TAG_DATA_MAX_LEN) {
            mlog(dd_log_warning,
                "Appsec event data has size %zu, which exceed the maximum %zu",
                frag.len, DD_TAG_DATA_MAX_LEN);
            continue;
        }
        dd_tags_add_appsec_json_frag(zend_string_init(frag.ptr, frag.len, 0));
    }
}

static void _process_meta(
    const ddog_AppSecVerdictView *nonnull view, zend_object *nonnull span)
{
    bool has_schemas = false;

    for (size_t i = 0; i < view->meta_len; i++) {
        ddog_CharSlice key = view->meta[i].key;
        ddog_CharSlice value = view->meta[i].value;
        size_t key_len = MIN(key.len, INT_MAX);

        if (!has_schemas && dd_string_starts_with_lc(
                                key.ptr, key_len, ZEND_STRL("_dd.appsec.s."))) {
            has_schemas = true;
        }

        if (!dd_trace_span_add_attribute_str(
                span, key.ptr, key_len, value.ptr, value.len)) {
            mlog(dd_log_warning, "Failed to add tag %.*s", (int)key_len,
                key.ptr);
            return;
        }

        dd_telemetry_note_helper_string_meta(
            key.ptr, key_len, value.ptr, value.len);
    }

    if (has_schemas) {
        dd_telemetry_note_schema_extracted();
        if (!get_DD_APM_TRACING_ENABLED()) {
            dd_trace_emit_asm_event();
        }
    }
}

static void _process_metrics(
    const ddog_AppSecVerdictView *nonnull view, zend_object *nonnull span)
{
    if (view->metrics_len == 0) {
        return;
    }
    zval *attributes = dd_trace_span_get_attributes(span);
    if (attributes == NULL) {
        return;
    }

    for (size_t i = 0; i < view->metrics_len; i++) {
        ddog_CharSlice key = view->metrics[i].key;
        size_t key_len = MIN(key.len, INT_MAX);

        zval zv;
        ZVAL_DOUBLE(&zv, view->metrics[i].value);
        zend_string *ztag = zend_string_init(key.ptr, key_len, 0);

        mlog(dd_log_debug, "Adding to root span the metric '%.*s'",
            (int)key_len, key.ptr);

        zval *res = zend_hash_add(Z_ARRVAL_P(attributes), ztag, &zv);
        zend_string_release(ztag);
        if (res == NULL) {
            mlog(dd_log_warning, "Failed to add metric %.*s", (int)key_len,
                key.ptr);
            return;
        }
    }
}

static void _note_helper_state(helper_state state)
{
    if (_helper_state == state) {
        return;
    }
    _helper_state = state;
    if (state == helper_state_reachable) {
        dd_telemetry_helper_conn_success();
    } else {
        dd_telemetry_helper_conn_error();
    }
}

static dd_result _process_verdict(
    const ddog_AppSecVerdictView *nonnull view, struct req_info *nonnull ctx)
{
    switch (view->kind) {
    case DDOG_APP_SEC_VERDICT_KIND_OK:
        break;
    case DDOG_APP_SEC_VERDICT_KIND_UNAVAILABLE:
        mlog_g(dd_log_debug,
            "No AppSec helper client handled %s; skipping AppSec for this "
            "request",
            ctx->command_name);
        return dd_error;
    case DDOG_APP_SEC_VERDICT_KIND_ERROR:
        mlog_g(dd_log_info, "The AppSec helper failed %s: %.*s",
            ctx->command_name, (int)view->error.len, view->error.ptr);
        return dd_error;
    case DDOG_APP_SEC_VERDICT_KIND_TRANSPORT_ERROR:
    default:
        mlog_g(dd_log_info, "Could not send %s to the sidecar: %.*s",
            ctx->command_name, (int)view->error.len, view->error.ptr);
        return dd_error;
    }

    dd_result res = _process_actions(view, ctx);
    if (res == dd_should_block || res == dd_should_redirect ||
        res == dd_should_record) {
        _add_appsec_span_data(view);
    }

    if (view->force_keep) {
        dd_trace_emit_asm_event();
    }

    if (ctx->root_span) {
        _process_meta(view, ctx->root_span);
        _process_metrics(view, ctx->root_span);
    }

    mlog(dd_log_info, "%s succeed and told to %s", ctx->command_name,
        dd_result_to_string(res));
    return res;
}

// The verdict is owned by Rust: release it even if processing bails out.
static dd_result _finish(
    ddog_AppSecVerdictResult *nonnull result, struct req_info *nonnull ctx)
{
    volatile dd_result res = dd_error;
    zend_try { res = _process_verdict(ddog_appsec_verdict_view(result), ctx); }
    zend_catch
    {
        ddog_appsec_verdict_drop(result);
        zend_bailout();
    }
    zend_end_try();
    ddog_appsec_verdict_drop(result);
    return res;
}

static ddog_AppSecVerdictKind _kind(ddog_AppSecVerdictResult *nonnull result)
{
    return ddog_appsec_verdict_view(result)->kind;
}

void dd_appsec_addresses_init(dd_appsec_addresses *nonnull addresses)
{
    addresses->data = NULL;
    addresses->size = 0;
    mpack_writer_init_growable(
        &addresses->writer, &addresses->data, &addresses->size);
}

// Finishes the addresses. On success, they are to be freed by the caller.
static bool _finish_addresses(dd_appsec_addresses *nonnull addresses,
    const char *nonnull command, ddog_CharSlice *nonnull data)
{
    mpack_error_t err = mpack_writer_destroy(&addresses->writer);
    if (err != mpack_ok) {
        mlog(dd_log_warning, "Failed to serialize the %s addresses: %s",
            command, mpack_error_to_string(err));
        return false;
    }
    *data = (ddog_CharSlice){.ptr = addresses->data, .len = addresses->size};
    return true;
}

static void _free_addresses(dd_appsec_addresses *nonnull addresses)
{
    MPACK_FREE(addresses->data);
    addresses->data = NULL;
}

dd_result dd_appsec_send_request_init(dd_appsec_addresses *nonnull addresses,
    bool keep_bound_application, struct req_info *nonnull ctx)
{
    ctx->command_name = "request_init";
    ddog_CharSlice data;
    if (!_finish_addresses(addresses, ctx->command_name, &data)) {
        _request_started = false;
        return dd_error;
    }
    ddog_AppSecVerdictResult *result =
        ddog_appsec_request_init(_transport(), data, keep_bound_application);
    _free_addresses(addresses);
    ddog_AppSecVerdictKind kind = _kind(result);
    _request_started = kind == DDOG_APP_SEC_VERDICT_KIND_OK;
    if (kind == DDOG_APP_SEC_VERDICT_KIND_OK ||
        kind == DDOG_APP_SEC_VERDICT_KIND_ERROR) {
        _note_helper_state(helper_state_reachable);
    } else {
        _note_helper_state(helper_state_unreachable);
    }
    return _finish(result, ctx);
}

dd_result dd_appsec_send_request_exec(dd_appsec_addresses *nonnull addresses,
    zend_string *nullable rasp_rule, zend_string *nullable rule_variant,
    struct req_info *nonnull ctx)
{
    ctx->command_name = "request_exec";
    ddog_CharSlice data;
    if (!_finish_addresses(addresses, ctx->command_name, &data)) {
        return dd_error;
    }
    ddog_AppSecVerdictResult *result = ddog_appsec_request_exec(
        _transport(), data, _slice(rasp_rule), _slice(rule_variant));
    _free_addresses(addresses);
    return _finish(result, ctx);
}

dd_result dd_appsec_send_request_shutdown(
    dd_appsec_addresses *nonnull addresses, uint64_t api_sec_samp_key,
    bool input_truncated, struct req_info *nonnull ctx)
{
    ctx->command_name = "request_shutdown";
    _request_started = false;
    ddog_CharSlice data;
    if (!_finish_addresses(addresses, ctx->command_name, &data)) {
        return dd_error;
    }
    ddog_AppSecVerdictResult *result = ddog_appsec_request_shutdown(
        _transport(), data, api_sec_samp_key, input_truncated);
    _free_addresses(addresses);
    return _finish(result, ctx);
}

/* PHP function: datadog\appsec\testing\set_mock_helper_fd(resource $stream):
 * bool. Requests then go to the mock helper over the stream, as JSON, instead
 * of the sidecar. The fd is dup()'d so the PHP-side stream can be closed
 * independently. */
static PHP_FUNCTION(datadog_appsec_testing_set_mock_helper_fd) // NOLINT
{
    zval *zstream;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "r", &zstream) == FAILURE) {
        RETURN_FALSE;
    }

    php_stream *stream;
    php_stream_from_zval_no_verify(stream, zstream);
    if (!stream) {
        mlog(dd_log_warning, "set_mock_helper_fd: invalid stream argument");
        RETURN_FALSE;
    }

    int fd = -1;
    if (php_stream_cast(stream, PHP_STREAM_AS_FD | PHP_STREAM_CAST_INTERNAL,
            (void **)&fd, 1) != SUCCESS ||
        fd < 0) {
        mlog(dd_log_warning,
            "set_mock_helper_fd: cannot extract fd from stream");
        RETURN_FALSE;
    }

    // Not inherited by the mock helper, which is spawned afterwards: closing it
    // must leave the mock without any reference to our end.
    int new_fd = fcntl(fd, F_DUPFD_CLOEXEC, 0);
    if (new_fd < 0) {
        mlog_err(dd_log_warning, "set_mock_helper_fd: fcntl(F_DUPFD_CLOEXEC)");
        RETURN_FALSE;
    }

    if (_mock_fd >= 0) {
        close(_mock_fd);
    }
    _mock_fd = new_fd;
    ddog_appsec_testing_set_mock_fd(new_fd);
    RETURN_TRUE;
}

/* PHP function: datadog\appsec\testing\close_helper_connection(): bool.
 * Closes the stream to the mock helper, which then exits. */
static PHP_FUNCTION(datadog_appsec_testing_close_helper_connection) // NOLINT
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    if (_mock_fd < 0) {
        RETURN_FALSE;
    }
    ddog_appsec_testing_set_mock_fd(-1);
    close(_mock_fd);
    _mock_fd = -1;
    _request_started = false;
    RETURN_TRUE;
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_bool_arginfo, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(set_fd_arginfo, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_INFO(0, stream)
ZEND_END_ARG_INFO()

static const zend_function_entry _functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "set_mock_helper_fd",
        PHP_FN(datadog_appsec_testing_set_mock_helper_fd), set_fd_arginfo, 0,
        NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "close_helper_connection",
        PHP_FN(datadog_appsec_testing_close_helper_connection),
        void_ret_bool_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void) { dd_phpobj_reg_funcs(_functions); }
