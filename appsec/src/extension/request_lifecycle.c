#include "request_lifecycle.h"
#include "attributes.h"
#include "commands/client_init.h"
#include "commands/config_sync.h"
#include "commands/request_init.h"
#include "commands/request_shutdown.h"
#include "configuration.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "ddtrace.h"
#include "entity_body.h"
#include "helper_process.h"
#include "ip_extraction.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "request_abort.h"
#include "string_helpers.h"
#include "tags.h"

#include <SAPI.h>
#include <Zend/zend_exceptions.h>
#include <stdio.h>

static void _do_request_finish_php(bool ignore_verdict);
static zend_array *nullable _do_request_begin(
    zval *nullable rbe_zv, bool user_req);
static void _do_request_begin_php(void);
static zend_array *_do_request_finish_user_req(bool ignore_verdict,
    zend_array *nonnull superglob_equiv, int status_code,
    zend_array *nullable resp_headers, zend_string *nullable entity);
static zend_array *nullable _do_request_begin_user_req(zval *nullable rbe_zv);
static zend_string *nullable _extract_ip_from_autoglobal(void);
static zend_string *nullable _get_entity_as_string(zval *rbe_zv);
static void _set_cur_span(zend_object *nullable span);
static void _reset_globals(void);
const zend_array *nonnull _get_server_equiv(
    const zend_array *nonnull superglob_equiv);
static void _register_testing_objects(void);

static bool _enabled_user_req;
static zend_string *_server_zstr;

static THREAD_LOCAL_ON_ZTS zend_object *nullable _cur_req_span;
static THREAD_LOCAL_ON_ZTS zend_array *nullable _superglob_equiv;
static THREAD_LOCAL_ON_ZTS zend_string *nullable _client_ip;
static THREAD_LOCAL_ON_ZTS zval _blocking_function;
static THREAD_LOCAL_ON_ZTS bool _shutdown_done_on_commit;
static THREAD_LOCAL_ON_ZTS bool _empty_service_or_env;
#define MAX_LENGTH_OF_REM_CFG_PATH 31
static THREAD_LOCAL_ON_ZTS char
    _last_rem_cfg_path[MAX_LENGTH_OF_REM_CFG_PATH + 1];
#define CLIENT_IP_LOOKUP_FAILED ((zend_string *)-1)

bool dd_req_is_user_req() { return _enabled_user_req; }

void dd_req_lifecycle_startup()
{
    _enabled_user_req = strcmp(sapi_module.name, "cli") == 0 &&
                        !get_global_DD_APPSEC_CLI_START_ON_RINIT();

    if (_enabled_user_req) {
        bool res = dd_trace_user_req_add_listeners(&dd_user_req_listeners);
        if (!res) {
            if (!get_global_DD_APPSEC_TESTING()) {
                // on testing, ddtrace is frequently not loaded
                mlog(dd_log_warning,
                    "Failed to register user request listeners");
            }
        } else {
            mlog(dd_log_debug, "Request lifecycle driven by user request");
        }
    } else {
        mlog(dd_log_debug, "Request lifecycle matches PHP's");
    }

    _server_zstr = zend_string_init_interned(LSTRARG("_SERVER"), 1);

    _register_testing_objects();
}

void dd_req_lifecycle_rinit(bool force)
{
    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED) {
        mlog(dd_log_debug,
            "Skipping request init actions because appsec is fully disabled");
        return;
    }

    if (_enabled_user_req) {
        mlog(dd_log_debug, "Skipping automatic request init on CLI because "
                           "DD_APPSEC_CLI_START_ON_RINIT=false");
        return;
    }

    if (get_global_DD_APPSEC_TESTING() && !force) {
        mlog(dd_log_debug, "Skipping automatic request init in testing");
        if (!_enabled_user_req) {
            _set_cur_span(dd_trace_get_active_root_span());
            if (!_cur_req_span) {
                mlog(dd_log_debug, "No root span available on request init");
            }
        }
        return;
    }

    _empty_service_or_env = zend_string_equals_literal(get_DD_ENV(), "") ||
                            zend_string_equals_literal(get_DD_SERVICE(), "");

    _set_cur_span(dd_trace_get_active_root_span());
    if (!_cur_req_span) {
        mlog(dd_log_debug, "No root span available on request init");
    }
    _do_request_begin_php();
}

static void _do_request_begin_php()
{
    zend_string *nonnull req_body =
        dd_request_body_buffered(get_DD_APPSEC_MAX_BODY_BUFF_SIZE());
    zval req_body_zv;
    ZVAL_STR(&req_body_zv, req_body);
    (void)_do_request_begin(&req_body_zv, false);
}

static zend_array *nullable _do_request_begin_user_req(zval *nullable rbe_zv)
{
    if (rbe_zv) {
        Z_TRY_ADDREF_P(rbe_zv);
    }
    return _do_request_begin(rbe_zv, true);
}

static bool _rem_cfg_path_changed(bool ignore_empty /* called from rinit */)
{
    if (ignore_empty && _empty_service_or_env &&
        _last_rem_cfg_path[0] != '\0') {
        return false;
    }

    const char *cur_path = dd_trace_remote_config_get_path();
    if (!cur_path) {
        cur_path = "";
    }
    if (strcmp(cur_path, _last_rem_cfg_path) == 0) {
        return false;
    }

    if (strlen(cur_path) > MAX_LENGTH_OF_REM_CFG_PATH) {
        mlog(dd_log_warning, "Remote config path too long: %s", cur_path);
        return false;
    }

    mlog(dd_log_info, "Remote config path changed from %s to %s",
        _last_rem_cfg_path[0] ? _last_rem_cfg_path : "(none)",
        cur_path[0] ? cur_path : "(none)");

    // NOLINTNEXTLINE(clang-analyzer-security.insecureAPI.strcpy)
    strcpy(_last_rem_cfg_path, cur_path);

    return true;
}

static zend_array *nullable _do_request_begin(
    zval *nullable rbe_zv /* needs free */, bool user_req)
{
    dd_tags_rinit();

    zend_string *nullable rbe = NULL;
    if (rbe_zv) {
        rbe = _get_entity_as_string(rbe_zv);
        zval_ptr_dtor(rbe_zv);
    }

    struct req_info_init req_info = {
        .req_info.root_span = dd_req_lifecycle_get_cur_span(),
        .req_info.client_ip = dd_req_lifecycle_get_client_ip(),
        .superglob_equiv = _superglob_equiv,
        .entity = rbe,
    };

    // connect/client_init
    dd_conn *conn =
        dd_helper_mgr_acquire_conn((client_init_func)dd_client_init, &req_info);
    if (conn == NULL) {
        mlog_g(dd_log_debug,
            "No connection; skipping rest of request initialization");
        if (rbe) {
            zend_string_release(rbe);
        }
        return NULL;
    }

    int res = dd_success;
    if (_rem_cfg_path_changed(true) ||
        (!DDAPPSEC_G(active) &&
            DDAPPSEC_G(enabled) == APPSEC_ENABLED_VIA_REMCFG)) {
        res = dd_config_sync(conn,
            &(struct config_sync_data){.rem_cfg_path = _last_rem_cfg_path});
        if (res == dd_success && DDAPPSEC_G(active)) {
            res = dd_request_init(conn, &req_info);
        }
    } else if (DDAPPSEC_G(active)) {
        // request_init
        res = dd_request_init(conn, &req_info);
    }

    if (rbe) {
        zend_string_release(rbe);
    }

    // we might have been disabled by request_init

    if (res == dd_network) {
        mlog_g(dd_log_info,
            "request_init/config_sync failed with dd_network; closing "
            "connection to helper");
        dd_helper_close_conn();
    } else if (res == dd_should_block) {
        if (user_req) {
            const zend_array *nonnull sv = _get_server_equiv(_superglob_equiv);
            return dd_request_abort_static_page_spec(sv);
        }
        dd_request_abort_static_page();
    } else if (res == dd_should_redirect) {
        if (user_req) {
            return dd_request_abort_redirect_spec();
        }
        dd_request_abort_redirect();
    } else if (res) {
        mlog_g(
            dd_log_info, "request init failed: %s", dd_result_to_string(res));
    }

    return NULL;
}

void dd_req_lifecycle_rshutdown(bool ignore_verdict, bool force)
{
    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED) {
        mlog_g(dd_log_debug, "Skipping all request shutdown actions because "
                             "appsec is fully disabled");
        return;
    }

    if (get_global_DD_APPSEC_TESTING() && !force) {
        mlog_g(dd_log_debug, "Skipping automatic request shutdown in testing");
        _reset_globals();
        return;
    }

    if (_enabled_user_req) {
        if (_cur_req_span) {
            mlog_g(dd_log_info,
                "Finishing user request whose corresponding "
                "span is presumably still unclosed on rshutdown");
            _do_request_finish_user_req(true, _superglob_equiv, 0, NULL, NULL);
            _reset_globals();
        }
    } else {
        _do_request_finish_php(ignore_verdict);
        // _rest_globals already called
    }

    // if we don't have service/env, our only chance to update the remote config
    // path is rshutdown because ddtrace's rinit is called before ours and it
    // resets the path
    if (_empty_service_or_env && _rem_cfg_path_changed(false)) {
        mlog_g(dd_log_debug, "No DD_SERVICE/DD_ENV; trying to sync remote "
                             "config path on rshutdown");
        dd_conn *conn = dd_helper_mgr_cur_conn();
        if (conn == NULL) {
            mlog_g(dd_log_debug,
                "No connection to the helper for rshutdown config sync");
        } else {
            dd_result res = dd_config_sync(conn,
                &(struct config_sync_data){.rem_cfg_path = _last_rem_cfg_path});
            if (res) {
                mlog_g(dd_log_info,
                    "Failed to sync remote config path on rshutdown: %s",
                    dd_result_to_string(res));
            }
        }
    }
}

static void _do_request_finish_php(bool ignore_verdict)
{
    int verdict = dd_success;
    dd_conn *conn = dd_helper_mgr_cur_conn();

    if (conn && DDAPPSEC_G(active)) {
        struct req_shutdown_info ctx = {
            .req_info.root_span = dd_req_lifecycle_get_cur_span(),
            .req_info.client_ip = dd_req_lifecycle_get_client_ip(),
            .status_code = SG(sapi_headers).http_response_code,
            .resp_headers_fmt = RESP_HEADERS_LLIST,
            .resp_headers_llist = &SG(sapi_headers).headers,
            .entity = dd_response_body_buffered(),
        };

        int res = dd_request_shutdown(conn, &ctx);
        if (res == dd_network) {
            mlog_g(dd_log_info,
                "request_shutdown failed with dd_network; closing "
                "connection to helper");
            dd_helper_close_conn();
        } else if (res == dd_should_block || res == dd_should_redirect) {
            verdict = ignore_verdict ? dd_success : res;
        } else if (res) {
            mlog_g(dd_log_info, "request shutdown failed: %s",
                dd_result_to_string(res));
        }
    }

    dd_helper_rshutdown();

    if (DDAPPSEC_G(active) && _cur_req_span) {
        dd_tags_add_tags(_cur_req_span, NULL);
    }
    dd_tags_rshutdown();

    _reset_globals();

    // TODO when blocking on shutdown, let the tracer handle flushing
    if (verdict == dd_should_block) {
        dd_trace_close_all_spans_and_flush();
        dd_request_abort_static_page();
    } else if (verdict == dd_should_redirect) {
        dd_trace_close_all_spans_and_flush();
        dd_request_abort_redirect();
    }
}

static zend_array *_do_request_finish_user_req(bool ignore_verdict,
    zend_array *nonnull superglob_equiv, int status_code,
    zend_array *nullable resp_headers, zend_string *nullable entity)
{
    int verdict = dd_success;
    dd_conn *conn = dd_helper_mgr_cur_conn();

    if (conn && DDAPPSEC_G(active)) {
        struct req_shutdown_info ctx = {
            .req_info.root_span = dd_req_lifecycle_get_cur_span(),
            .req_info.client_ip = dd_req_lifecycle_get_client_ip(),
            .status_code = status_code,
            .resp_headers_fmt = RESP_HEADERS_MAP_STRING_LIST,
            .resp_headers_arr = resp_headers ? resp_headers : &zend_empty_array,
            .entity = entity,
        };

        int res = dd_request_shutdown(conn, &ctx);
        if (res == dd_network) {
            mlog_g(dd_log_info,
                "request_shutdown failed with dd_network; closing "
                "connection to helper");
            dd_helper_close_conn();
        } else if (res == dd_should_block || res == dd_should_redirect) {
            verdict = ignore_verdict ? dd_success : res;
        } else if (res) {
            mlog_g(dd_log_info, "request shutdown failed: %s",
                dd_result_to_string(res));
        }
    }

    dd_helper_rshutdown();

    if (DDAPPSEC_G(active) && _cur_req_span) {
        dd_tags_add_tags(_cur_req_span, superglob_equiv);
    }

    if (verdict == dd_should_block) {
        const zend_array *nonnull sv = _get_server_equiv(superglob_equiv);
        return dd_request_abort_static_page_spec(sv);
    }
    if (verdict == dd_should_redirect) {
        return dd_request_abort_redirect_spec();
    }

    return NULL;
}

static void _reset_globals()
{
    _set_cur_span(NULL);

    if (_superglob_equiv) {
        if (GC_TRY_DELREF(_superglob_equiv), // could be zend_empty_array
            GC_REFCOUNT(_superglob_equiv) == 0) {
            zend_array_destroy(_superglob_equiv);
        }

        _superglob_equiv = NULL;
    }

    if (_client_ip && _client_ip != CLIENT_IP_LOOKUP_FAILED) {
        zend_string_release(_client_ip);
        _client_ip = NULL;
    }

    if (Z_TYPE(_blocking_function) != IS_UNDEF) {
        zval_ptr_dtor(&_blocking_function);
    }
    ZVAL_UNDEF(&_blocking_function);

    _shutdown_done_on_commit = false;
    dd_tags_rshutdown();
}

static zend_string *nullable _extract_ip_from_autoglobal()
{
    zval *_server =
        dd_php_get_autoglobal(TRACK_VARS_SERVER, LSTRARG("_SERVER"));
    if (!_server) {
        mlog(dd_log_info, "No SERVER autoglobal available");
        return NULL;
    }
    return dd_ip_extraction_find(_server);
}

static void _set_cur_span(zend_object *nullable span)
{
    if (_cur_req_span) {
        if (GC_DELREF(_cur_req_span) == 0) {
            zend_objects_store_del(_cur_req_span);
        }
    }
    _cur_req_span = span;
    if (_cur_req_span) {
        GC_ADDREF(_cur_req_span);
    }
}

zend_object *nullable dd_req_lifecycle_get_cur_span() { return _cur_req_span; }

zend_string *nullable dd_req_lifecycle_get_client_ip()
{
    if (!_client_ip) {
        if (_superglob_equiv) {
            zval *_server = zend_hash_find(_superglob_equiv, _server_zstr);
            if (_server) {
                _client_ip = dd_ip_extraction_find(_server);
            }
        } else {
            _client_ip = _extract_ip_from_autoglobal();
        }
        if (!_client_ip) {
            _client_ip = CLIENT_IP_LOOKUP_FAILED;
        }
    }

    if (_client_ip == CLIENT_IP_LOOKUP_FAILED) {
        return NULL;
    }
    return _client_ip;
}

static zend_array *nullable _start_user_req(
    ddtrace_user_req_listeners *listener, zend_object *span,
    zend_array *super_global_equiv, zval *nullable rbe_zv)
{
    UNUSED(listener);

    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED) {
        return NULL;
    }

    if (_cur_req_span != NULL) {
        mlog(dd_log_warning, "User request already started; only one user "
                             "request can be active at a time. Finishing the "
                             "previous request before starting the new one");
        zend_array *spec =
            _do_request_finish_user_req(true, _superglob_equiv, 0, NULL, NULL);
        _reset_globals();
        UNUSED(spec);
        assert(spec == NULL);
    }

    mlog(dd_log_debug, "Starting user request for span %p", span);

    _set_cur_span(span);
    GC_TRY_ADDREF(super_global_equiv);
    _superglob_equiv = super_global_equiv;
    return _do_request_begin_user_req(rbe_zv);
}

static zend_array *nullable _response_commit(
    ddtrace_user_req_listeners *listener, zend_object *span, int status,
    zend_array *resp_headers, zval *rbe_zv)
{
    UNUSED(listener);

    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED) {
        return NULL;
    }

    if (!_cur_req_span) {
        mlog(dd_log_warning,
            "Request commit callback called, but there is no "
            "root span currently associated through the "
            "request started span (or it was cleared already)");
        return NULL;
    }
    if (_cur_req_span != span) {
        mlog(dd_log_warning,
            "Request commit callback called, but the root span currently "
            "associated is not the same as the one that was provided");
        return NULL;
    }
    if (_shutdown_done_on_commit) {
        mlog(dd_log_warning,
            "Request commit callback called twice for the same span");
        return NULL;
    }

    mlog(dd_log_debug, "Committing user request for span %p", span);

    zend_string *rbe = _get_entity_as_string(rbe_zv);

    zend_array *res = _do_request_finish_user_req(
        false, _superglob_equiv, status, resp_headers, rbe);

    if (rbe) {
        zend_string_release(rbe);
    }

    _shutdown_done_on_commit = true;

    return res;
}

static zend_string *nullable _get_entity_as_string(zval *rbe_zv)
{
    if (!rbe_zv) {
        return NULL;
    }

    const size_t max_size = (size_t)get_DD_APPSEC_MAX_BODY_BUFF_SIZE();

    if (Z_TYPE_P(rbe_zv) == IS_STRING) {
        if (Z_STRLEN_P(rbe_zv) <= max_size) {
            zend_string *res = Z_STR_P(rbe_zv);
            zend_string_addref(res);
            return res;
        }
        mlog(dd_log_debug,
            "Response body entity is larger than %zu bytes (got %zu); ignoring",
            max_size, Z_STRLEN_P(rbe_zv));
        return NULL;
    }

    if (Z_TYPE_P(rbe_zv) != IS_RESOURCE) {
        mlog(dd_log_debug,
            "Response body entity is not a string or a stream; ignoring");
        return NULL;
    }

    php_stream *stream = NULL;
    php_stream_from_zval_no_verify(stream, rbe_zv);
    if (!stream) {
        mlog(dd_log_debug,
            "Response body entity is not a string or a stream; ignoring");
        return NULL;
    }

    // TODO: support non-seekable streams. Needs replacing the stream
    if (stream->flags & PHP_STREAM_FLAG_NO_SEEK) {
        __auto_type lvl =
            get_global_DD_APPSEC_TESTING() ? dd_log_info : dd_log_debug;
        mlog(lvl, "Response body entity is a stream, but it is "
                  "not seekable; ignoring");
        return NULL;
    }

    zend_off_t start_pos = php_stream_tell(stream);
    if (start_pos < 0) {
        mlog(dd_log_info, "Failed to get current position of response body "
                          "entity stream; ignoring");
        return NULL;
    }

    if (php_stream_seek(stream, 0, SEEK_END) < 0) {
        mlog(dd_log_info,
            "Failed to seek to end of response body entity stream; ignoring");
        return NULL;
    }

    size_t stream_size = (size_t)php_stream_tell(stream);
    if (stream_size == (size_t)-1) {
        mlog(dd_log_error,
            "Failed to get current position of response body "
            "entity stream after seek; response stream is likely corrupted");
        return NULL;
    }

    if (stream_size < (size_t)start_pos) {
        mlog(dd_log_error,
            "Response body entity stream shrank after seek (%zu to %zu); "
            "response stream is likely corrupted",
            (size_t)start_pos, stream_size);
        return NULL;
    }

    if (php_stream_seek(stream, start_pos, SEEK_SET) < 0) {
        mlog(dd_log_error, "Failed to rewind response body entity stream; "
                           "response stream is likely corrupted");
        return NULL;
    }

    size_t effective_size = stream_size - (size_t)start_pos;
    if (effective_size >= (size_t)get_DD_APPSEC_MAX_BODY_BUFF_SIZE()) {
        __auto_type lvl =
            get_global_DD_APPSEC_TESTING() ? dd_log_info : dd_log_debug;
        mlog(lvl,
            "Response body entity is larger than %zu bytes (got %zu); ignoring",
            max_size, effective_size);
        return NULL;
    }

    if (effective_size == 0) {
        return NULL;
    }

    zend_string *buf = zend_string_alloc(effective_size, 0);
    char *p = ZSTR_VAL(buf);
    const char *end = p + effective_size;
    while (!php_stream_eof(stream) && p < end) {
        size_t read = php_stream_read(stream, p, end - p);
        if (read == (size_t)-1 || (read == 0 && !php_stream_eof(stream))) {
            mlog(dd_log_error, "Failed to read response body entity stream; "
                               "response stream is likely corrupted");
            zend_string_release(buf);
            return NULL;
        }
        if (read == 0) {
            break;
        }
        p += read;
    }
    size_t total_read = (size_t)(p - ZSTR_VAL(buf));
    if (total_read != effective_size) {
        mlog(dd_log_info,
            "Read fewer data than expected (expected %zu, got %zu)",
            effective_size, total_read);
        if (total_read == 0) {
            zend_string_release(buf);
            return NULL;
        }
        ZSTR_LEN(buf) = total_read;
    }

    if (php_stream_seek(stream, start_pos, SEEK_SET) < 0) {
        mlog(dd_log_error, "Failed to rewind response body entity stream; "
                           "response stream is likely corrupted");
        zend_string_release(buf);
        return NULL;
    }

    return buf;
}

static void _finish_user_req(
    ddtrace_user_req_listeners *listener, zend_object *span)
{
    UNUSED(listener);

    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED) {
        return;
    }

    if (_shutdown_done_on_commit) {
        mlog(dd_log_debug, "Skipping user request shutdown because it was "
                           "already done on commit");
        _reset_globals();
        return;
    }

    if (_cur_req_span == NULL) {
        mlog(dd_log_warning,
            "Request finished callback called, but there is no "
            "root span currently associated through the "
            "request started span (or it was cleared already). "
            "Resetting");
        _reset_globals();
        return;
    }
    if (_cur_req_span != span) {
        mlog(dd_log_warning,
            "Request finished callback called, but the root span currently "
            "associated is not the same as the one that was provided. "
            "Resetting");
        _reset_globals();
    }

    mlog(dd_log_debug, "Finishing user request for span %p", span);

    zend_array *arr =
        _do_request_finish_user_req(true, _superglob_equiv, 0, NULL, NULL);
    UNUSED(arr);
    assert(arr == NULL);
    _reset_globals();
}

const zend_array *nonnull _get_server_equiv(
    const zend_array *nonnull superglob_equiv)
{
    zval *_server = zend_hash_str_find(superglob_equiv, LSTRARG("_SERVER"));
    if (_server && Z_TYPE_P(_server) == IS_ARRAY) {
        return Z_ARRVAL_P(_server);
    }

    return &zend_empty_array;
}

static void _set_blocking_function(ddtrace_user_req_listeners *nonnull self,
    zend_object *nonnull span, zval *nonnull blocking_function)
{
    UNUSED(self);

    if (_cur_req_span == NULL) {
        mlog(dd_log_warning, "set_blocking_function called, but there is no "
                             "root span currently associated through "
                             "notify_start (or it was cleared already)");
        return;
    }
    if (_cur_req_span != span) {
        mlog(dd_log_warning,
            "set_blocking_function called, but the root span currently "
            "associated is not the same as the one that was provided");
        return;
    }
    if (_shutdown_done_on_commit) {
        mlog(dd_log_warning,
            "set_blocking_function called after request shutdown");
        return;
    }
    if (Z_TYPE(_blocking_function) != IS_UNDEF) {
        mlog(dd_log_warning,
            "set_blocking_function called twice for the same span");
        return;
    }

    ZVAL_COPY(&_blocking_function, blocking_function);
}

void dd_req_call_blocking_function(dd_result res)
{
    if (Z_TYPE(_blocking_function) == IS_UNDEF) {
        mlog(dd_log_debug, "dd_req_call_blocking_function called with no "
                           "blocking function set");
        return;
    }
    if (!_superglob_equiv) {
        mlog(dd_log_warning, "dd_req_call_blocking_function called, but there "
                             "is no active span");
        return;
    }
    if (_shutdown_done_on_commit) {
        mlog(dd_log_warning,
            "dd_user_req_abort_static_page called after request shutdown");
        return;
    }

    mlog(dd_log_debug, "Calling blocking function for span %p", _cur_req_span);

    const zend_array *nonnull sv = _get_server_equiv(_superglob_equiv);
    zend_array *spec = NULL;
    if (res == dd_should_block) {
        spec = dd_request_abort_static_page_spec(sv);
    } else if (res == dd_should_redirect) {
        spec = dd_request_abort_redirect_spec();
    } else {
        mlog(dd_log_warning,
            "dd_req_call_blocking_function called with "
            "invalid result %d",
            res);
    }
    assert(spec != NULL);

    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
    char *error = NULL;
    if (zend_fcall_info_init(
            &_blocking_function, 0, &fci, &fcc, NULL, &error) == FAILURE) {
        mlog(dd_log_warning, "Failure resolving callable: %s",
            error ? error : "");
        if (error) {
            efree(error);
        }
        return;
    }
    fci.param_count = 1;
    zval zv;
    ZVAL_ARR(&zv, spec);
    fci.params = &zv;
    zval retval;
    fci.retval = &retval;
    if (zend_call_function(&fci, &fcc) == FAILURE) {
        mlog(dd_log_warning, "Failure calling blocking function");
        return;
    }
    if (EG(exception)) {
        mlog(dd_log_debug, "Blocking function threw an exception");
    }
    zval_ptr_dtor(&retval);
}

ddtrace_user_req_listeners dd_user_req_listeners = {
    .priority = -10, // NOLINT
    .start_user_req = _start_user_req,
    .response_committed = _response_commit,
    .set_blocking_function = _set_blocking_function,
    .finish_user_req = _finish_user_req,
};

PHP_FUNCTION(datadog_appsec_testing_dump_req_lifecycle_state)
{
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }

    array_init(return_value);
    {
        zval zcur_span;
        if (_cur_req_span) {
            GC_ADDREF(_cur_req_span);
            ZVAL_OBJ(&zcur_span, _cur_req_span);
        } else {
            ZVAL_NULL(&zcur_span);
        }
        zend_hash_str_add_new(
            Z_ARRVAL_P(return_value), "span", sizeof("span") - 1, &zcur_span);
    }
    {
        zval zsuperglob_equiv;
        if (_superglob_equiv) {
            // may be zend_empty_array; dup it unconditionally because this is
            // for testing and it's not so simple to handle for all PHP versions
            ZVAL_ARR(&zsuperglob_equiv, zend_array_dup(_superglob_equiv));
        } else {
            ZVAL_NULL(&zsuperglob_equiv);
        }
        zend_hash_str_add_new(Z_ARRVAL_P(return_value), "superglob_equiv",
            sizeof("superglob_equiv") - 1, &zsuperglob_equiv);
    }
    {
        zval zshutdown_on_commit;
        ZVAL_BOOL(&zshutdown_on_commit, _shutdown_done_on_commit);
        zend_hash_str_add_new(Z_ARRVAL_P(return_value),
            "shutdown_done_on_commit", sizeof("shutdown_done_on_commit") - 1,
            &zshutdown_on_commit);
    }
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(dump_arginfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "dump_req_lifecycle_state", PHP_FN(datadog_appsec_testing_dump_req_lifecycle_state), dump_arginfo, 0)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects()
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }
    dd_phpobj_reg_funcs(functions);
}
