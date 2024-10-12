// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <SAPI.h>
#include <main/php_streams.h>
#include <php.h>
#include <stdio.h>
#include <stdlib.h>

#include "attributes.h"
#include "configuration.h"
#include "ddappsec.h"
#include "dddefs.h"
#include "ddtrace.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "request_abort.h"
#include "string_helpers.h"

#define HTML_CONTENT_TYPE "text/html"
#define JSON_CONTENT_TYPE "application/json"

static const char static_error_html[] =
    "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><meta "
    "name=\"viewport\" "
    "content=\"width=device-width,initial-scale=1\"><title>You've been "
    "blocked</"
    "title><style>a,body,div,html,span{margin:0;padding:0;border:0;font-size:"
    "100%;font:inherit;vertical-align:baseline}body{background:-webkit-radial-"
    "gradient(26% 19%,circle,#fff,#f4f7f9);background:radial-gradient(circle "
    "at 26% "
    "19%,#fff,#f4f7f9);display:-webkit-box;display:-ms-flexbox;display:flex;-"
    "webkit-box-pack:center;-ms-flex-pack:center;justify-content:center;-"
    "webkit-box-align:center;-ms-flex-align:center;align-items:center;-ms-flex-"
    "line-pack:center;align-content:center;width:100%;min-height:100vh;line-"
    "height:1;flex-direction:column}p{display:block}main{text-align:center;"
    "flex:1;display:-webkit-box;display:-ms-flexbox;display:flex;-webkit-box-"
    "pack:center;-ms-flex-pack:center;justify-content:center;-webkit-box-align:"
    "center;-ms-flex-align:center;align-items:center;-ms-flex-line-pack:center;"
    "align-content:center;flex-direction:column}p{font-size:18px;line-height:"
    "normal;color:#646464;font-family:sans-serif;font-weight:400}a{color:#"
    "4842b7}footer{width:100%;text-align:center}footer "
    "p{font-size:16px}</style></head><body><main><p>Sorry, you cannot access "
    "this page. Please contact the customer service "
    "team.</p></main><footer><p>Security provided by <a "
    "href=\"https://www.datadoghq.com/product/security-platform/"
    "application-security-monitoring/\" "
    "target=\"_blank\">Datadog</a></p></footer></body></html>";

static const char static_error_json[] =
    "{\"errors\": [{\"title\": \"You've been blocked\", \"detail\": \"Sorry, yo"
    "u cannot access this page. Please contact the customer service team. Secur"
    "ity provided by Datadog.\"}]}";

static zend_string *_initial_cwd;
static zend_string *_body_error_html_def;
static zend_string *_body_error_json_def;
static zend_string *_status_zstr;
static zend_string *_headers_zstr;
static zend_string *_body_zstr;
static zend_string *_content_type_zstr;
static zend_string *_content_length_zstr;
static zend_string *_location_zstr;
static zend_string *_content_type_html_zstr;
static zend_string *_content_type_json_zstr;
static THREAD_LOCAL_ON_ZTS int _response_code = DEFAULT_BLOCKING_RESPONSE_CODE;
static THREAD_LOCAL_ON_ZTS dd_response_type _response_type =
    DEFAULT_RESPONSE_TYPE;
static THREAD_LOCAL_ON_ZTS int _redirection_response_code =
    DEFAULT_REDIRECTION_RESPONSE_CODE;
static THREAD_LOCAL_ON_ZTS zend_string *_redirection_location = NULL;

static bool _abort_prelude(void);
void _request_abort_static_page(int response_code, int type);
ATTR_FORMAT(1, 2)
static void _emit_error(const char *format, ...);
static zend_string *nonnull _get_json_blocking_template(void);
static zend_string *nonnull _get_html_blocking_template(void);

static zend_string *nullable _read_file_contents(const char *nonnull path)
{
    php_stream *fs;
    if (ZSTR_LEN(_initial_cwd) > 0 && path[0] != '/') {
        char *full_path;
        spprintf(&full_path, 0, "%s/%s", ZSTR_VAL(_initial_cwd), path);
        mlog(dd_log_debug, "Reading blocking template from %s", full_path);
        fs = php_stream_open_wrapper_ex(
            full_path, "rb", REPORT_ERRORS, NULL, NULL);
        efree(full_path);
    } else {
        mlog(dd_log_debug, "Reading blocking template from %s", path);
        fs = php_stream_open_wrapper_ex(path, "rb", REPORT_ERRORS, NULL, NULL);
    }

    if (fs == NULL) {
        return NULL;
    }

    zend_string *contents;
    contents = php_stream_copy_to_mem(fs, PHP_STREAM_COPY_ALL, 0);

    php_stream_close(fs);

    if (!contents) {
        return zend_empty_string;
    }
    return contents;
}

static void _set_content_type(const char *nonnull content_type)
{
    char *ct_header; // NOLINT
    uint ct_header_len =
        (uint)spprintf(&ct_header, 0, "Content-type: %s", content_type);
    sapi_header_line line = {.line = ct_header, .line_len = ct_header_len};
    int res = sapi_header_op(SAPI_HEADER_REPLACE, &line);
    efree(ct_header);
    if (res == FAILURE) {
        mlog(dd_log_warning, "could not set content-type header");
    }
}

static void _set_output(const char *nonnull output, size_t length)
{
    size_t written = php_output_write(output, length);
    mlog_g(dd_log_debug, "php_output_write() returned %zu", written);
    if (written != length) {
        mlog(dd_log_info, "could not write full response (written: %zu)",
            written);
    }
}

static void _set_output_zstr(const zend_string *str)
{
    _set_output(ZSTR_VAL(str), ZSTR_LEN(str));
}

static dd_response_type _get_response_type_from_accept_header(
    const zend_array *nonnull _server)
{
    const zend_string *accept_zstr =
        dd_php_get_string_elem_cstr(_server, LSTRARG("HTTP_ACCEPT"));
    if (!accept_zstr) {
        mlog(dd_log_info,
            "Could not find Accept header, using default content-type (json)");
        return response_type_json;
    }

    const char *accept_end = ZSTR_VAL(accept_zstr) + ZSTR_LEN(accept_zstr);

    const char *accept_json = memmem(ZSTR_VAL(accept_zstr),
        ZSTR_LEN(accept_zstr), LSTRARG(JSON_CONTENT_TYPE));
    const char *accept_json_end = accept_json + LSTRLEN(JSON_CONTENT_TYPE);

    if (accept_json != NULL && accept_json_end <= accept_end &&
        (*accept_json_end == ',' || *accept_json_end == '\0' ||
            *accept_json_end == ';')) {
        return response_type_json;
    }

    const char *accept_html = memmem(ZSTR_VAL(accept_zstr),
        ZSTR_LEN(accept_zstr), LSTRARG(HTML_CONTENT_TYPE));
    const char *accept_html_end = accept_html + LSTRLEN(HTML_CONTENT_TYPE);

    if (accept_html != NULL && accept_html_end <= accept_end &&
        (*accept_html_end == ',' || *accept_html_end == '\0' ||
            *accept_html_end == ';')) {
        return response_type_html;
    }

    mlog_g(dd_log_debug, "No recognized accept header, defaulting to json");
    return response_type_json;
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
void dd_set_block_code_and_type(int code, dd_response_type type)
{
    _response_code = code;

    // Account for lack of enum type safety
    switch (type) {
    case response_type_auto:
    case response_type_html:
    case response_type_json:
        _response_type = type;
        break;
    default:
        _response_type = response_type_auto;
        break;
    }
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
void dd_set_redirect_code_and_location(int code, zend_string *nullable location)
{
    _redirection_response_code = DEFAULT_REDIRECTION_RESPONSE_CODE;
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    if (code >= 300 && code < 400) {
        _redirection_response_code = code;
    }
    _redirection_location = location;
}

void dd_request_abort_redirect()
{
    if (_redirection_location == NULL || ZSTR_LEN(_redirection_location) == 0) {
        _request_abort_static_page(
            DEFAULT_BLOCKING_RESPONSE_CODE, DEFAULT_RESPONSE_TYPE);
        return;
    }

    if (!_abort_prelude()) {
        mlog(dd_log_debug, "_abort_prelude has failed");
        return;
    }

    char *line;
    uint line_len = (uint)spprintf(
        &line, 0, "Location: %s", ZSTR_VAL(_redirection_location));

    mlog_g(dd_log_debug, "Will forward to %s with status %d",
        ZSTR_VAL(_redirection_location), _redirection_response_code);

    SG(sapi_headers).http_response_code = _redirection_response_code;
    int res = sapi_header_op(SAPI_HEADER_REPLACE,
        &(sapi_header_line){.line = line, .line_len = line_len});
    if (res == FAILURE) {
        mlog(dd_log_warning, "Could not forward to %s",
            ZSTR_VAL(_redirection_location));
    }

    efree(line);

    if (sapi_flush() == SUCCESS) {
        mlog_g(dd_log_debug, "Successful call to sapi_flush()");
    } else {
        mlog(dd_log_warning, "Call to sapi_flush() failed");
    }

    if (DDAPPSEC_G(during_request_shutdown)) {
        mlog(dd_log_info,
            "Datadog blocked the request and attempted a redirection to %s",
            ZSTR_VAL(_redirection_location));
    } else {
        _emit_error(
            "Datadog blocked the request and attempted a redirection to %s",
            ZSTR_VAL(_redirection_location));
    }
}

zend_array *nonnull dd_request_abort_redirect_spec()
{
    zend_array *arr = zend_new_array(2);

    zval status;
    ZVAL_LONG(&status, _redirection_response_code);
    zend_hash_add_new(arr, _status_zstr, &status);

    zend_array *headers = zend_new_array(1);
    zval location;
    ZVAL_STR(&location, _redirection_location);
    zend_hash_add_new(headers, _location_zstr, &location);
    zval headers_zv;
    ZVAL_ARR(&headers_zv, headers);
    zend_hash_add_new(arr, _headers_zstr, &headers_zv);

    return arr;
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
void _request_abort_static_page(int response_code, int type)
{
    SG(sapi_headers).http_response_code = response_code;

    dd_response_type response_type = type;
    if (response_type == response_type_auto) {
        zval *server =
            dd_php_get_autoglobal(TRACK_VARS_SERVER, LSTRARG("_SERVER"));
        if (!server) {
            mlog(dd_log_info, "Could not find _SERVER");
            response_type = response_type_json;
        } else {
            response_type =
                _get_response_type_from_accept_header(Z_ARRVAL_P(server));
        }
    }

    zend_string *body = NULL;
    const char *content_type;
    if (response_type == response_type_html) {
        content_type = HTML_CONTENT_TYPE;
        body = _get_html_blocking_template();
    } else if (response_type == response_type_json) {
        content_type = JSON_CONTENT_TYPE;
        body = _get_json_blocking_template();
    } else {
        mlog(dd_log_error, "unknown response type (bug) %d", response_type);
        return;
    }

    if (!_abort_prelude()) {
        mlog(dd_log_debug, "_abort_prelude has failed");
        zend_string_release(body);
        return;
    }

    _set_content_type(content_type);
    _set_output_zstr(body);
    zend_string_release(body);

    if (sapi_flush() != SUCCESS) {
        mlog(dd_log_info, "call to sapi_flush() failed");
    }

    if (DDAPPSEC_G(during_request_shutdown)) {
        mlog(dd_log_info,
            "Datadog blocked the request and presented a static error page");
    } else {
        _emit_error(
            "Datadog blocked the request and presented a static error page");
    }
}

void dd_request_abort_static_page()
{
    _request_abort_static_page(_response_code, _response_type);
}

zend_array *nonnull dd_request_abort_static_page_spec(
    const zend_array *nonnull _server)
{
    zend_array *arr = zend_new_array(3);

    zval status;
    ZVAL_LONG(&status, _response_code);
    zend_hash_add_new(arr, _status_zstr, &status);

    zend_array *headers = zend_new_array(2);
    dd_response_type response_type = _response_type;
    if (response_type == response_type_auto) {
        response_type = _get_response_type_from_accept_header(_server);
    }

    zval content_type;
    zval body;
    size_t body_len;
    if (response_type == response_type_html) {
        ZVAL_STR(&content_type, _content_type_html_zstr);
        zend_hash_add_new(headers, _content_type_zstr, &content_type);

        zend_string *content = _get_html_blocking_template();
        body_len = content->len;
        ZVAL_STR(&body, content);
        zend_hash_add_new(arr, _body_zstr, &body);
    } else {
        ZVAL_STR(&content_type, _content_type_json_zstr);
        zend_hash_add_new(headers, _content_type_zstr, &content_type);

        zend_string *content = _get_json_blocking_template();
        body_len = content->len;
        ZVAL_STR(&body, content);
        zend_hash_add_new(arr, _body_zstr, &body);
    }

    {
        char buf[sizeof("18446744073709551615") - 1];
        size_t len = sprintf(buf, "%zu", body_len);
        zend_string *s = zend_string_init(buf, len, 0);
        zval cont_len_zv;
        ZVAL_STR(&cont_len_zv, s);
        zend_hash_add_new(headers, _content_length_zstr, &cont_len_zv);
    }

    zval headers_zv;
    ZVAL_ARR(&headers_zv, headers);
    zend_hash_add_new(arr, _headers_zstr, &headers_zv);

    return arr;
}

static void _force_destroy_output_handlers(void);
static bool _abort_prelude()
{
    if (OG(running)) {
        /* we were told to block from inside an output handler. In this case,
         * we cannot use any output functions until we do some cleanup, as php
         * calls php_output_deactivate and issues an error in that case */
        _force_destroy_output_handlers();
    }

    if (SG(headers_sent)) {
        mlog(dd_log_info, "Headers already sent; response code was %d",
            SG(sapi_headers).http_response_code);
        if (DDAPPSEC_G(during_request_shutdown)) {
            mlog(dd_log_info,
                "Datadog blocked the request, but the response has already "
                "been partially committed");
        } else {
            _emit_error(
                "Datadog blocked the request, but the response has already "
                "been partially committed");
        }
        return false;
    }

    int res = sapi_header_op(SAPI_HEADER_DELETE_ALL, NULL);
    if (res == SUCCESS) {
        mlog_g(dd_log_debug, "Cleared any current headers");
    } else {
        mlog_g(dd_log_warning, "Failed clearing current headers");
    }

    php_output_discard_all();
    mlog_g(dd_log_debug, "Output buffers have been discarded");
    return true;
}

static void _force_destroy_output_handlers()
{
    OG(active) = NULL;
    OG(running) = NULL;

    if (OG(handlers).elements) {
        php_output_handler **handler;
        while ((handler = zend_stack_top(&OG(handlers)))) {
            php_output_handler_free(handler);
            zend_stack_del_top(&OG(handlers));
        }
    }
}

static void _run_rshutdowns(void);
static void _suppress_error_reporting(void);

ATTR_FORMAT(1, 2)
static void _emit_error(const char *format, ...)
{
    mlog_g(dd_log_debug, "_emit_error() called: during_request_startup: %d",
        PG(during_request_startup));

    va_list args;
    va_start(args, format);
    if (PG(during_request_startup)) {
        /* if emitting error during startup, RSHUTDOWN will not run (except fpm)
         * so we need to run the same logic from here */
        if (!get_global_DD_APPSEC_TESTING()) {
            mlog_g(
                dd_log_debug, "Running our RSHUTDOWN before aborting request");
            dd_appsec_rshutdown(true);
            DDAPPSEC_G(skip_rshutdown) = true;
        }

        dd_trace_close_all_spans_and_flush();

        if (strcmp(sapi_module.name, "fpm-fcgi") == 0) {
            /* fpm children exit if we throw an error at this point. So emit
             * only warning and use other means to prevent the script from
             * executing */
            php_verror(NULL, "", E_WARNING, format, args);
            va_end(args);
            // fpm doesn't try to run the script if it sees this null
            SG(request_info).request_method = NULL;
            return;
        }

        _run_rshutdowns();
    } else {
        if (Z_TYPE(EG(user_error_handler)) != IS_UNDEF) {
            zval_ptr_dtor(&EG(user_error_handler));
            ZVAL_UNDEF(&EG(user_error_handler));
        }

        if (Z_TYPE(EG(user_exception_handler)) != IS_UNDEF) {
            zval_ptr_dtor(&EG(user_exception_handler));
            ZVAL_UNDEF(&EG(user_exception_handler));
        }
    }

    /* Avoid logging the error message on error level. This is done by first
     * emitting it at E_COMPILE_WARNING level, supressing error reporting and
     * then re-emitting at error level, which does the bailout */

    /* hacky: use E_COMPILE_WARNING to avoid the possibility of it being handled
     * by a user error handler (as with E_WARNING). E_CORE_WARNING would also
     * be a possibility, but it bypasses the value of error_reporting and is
     * always logged */
    {
        va_list args2;
        va_copy(args2, args);
        php_verror(NULL, "", E_COMPILE_WARNING, format, args2);
        va_end(args2);
    }

    // not enough: EG(error_handling) = EH_SUPPRESS;
    _suppress_error_reporting();
    php_verror(NULL, "", E_ERROR, format, args);

    va_end(args);
    __builtin_unreachable();
}

/* work around bugs in extensions that expect their request_shutdown to be
 * called once their request_init has been called */
static void _run_rshutdowns()
{
    HashPosition pos;
    zend_module_entry *module;
    bool found_ddappsec = false;

    mlog_g(dd_log_debug, "Running remaining extensions' RSHUTDOWN");
    for (zend_hash_internal_pointer_end_ex(&module_registry, &pos);
         (module = zend_hash_get_current_data_ptr_ex(&module_registry, &pos)) !=
         NULL;
         zend_hash_move_backwards_ex(&module_registry, &pos)) {
        if (!found_ddappsec && strcmp("ddappsec", module->name) == 0) {
            found_ddappsec = true;
            continue;
        }

        if (!module->request_shutdown_func) {
            continue;
        }

        if (found_ddappsec) {
            mlog_g(dd_log_debug, "Running RSHUTDOWN function for module %s",
                module->name);
            module->request_shutdown_func(module->type, module->module_number);
        }
    }
}

static void _suppress_error_reporting()
{
    /* do this through zend_alter_init_entry_ex rather than changing
     * EG(error_reporting) directly so the value is restored
     * on the deactivate phase (zend_ini_deactivate) */

    zend_string *name = zend_string_init(ZEND_STRL("error_reporting"), 0);
    zend_string *value = zend_string_init(ZEND_STRL("0"), 0);

    zend_alter_ini_entry_ex(
        name, value, PHP_INI_SYSTEM, PHP_INI_STAGE_RUNTIME, 1);

    zend_string_release(name);
    zend_string_release(value);
}

static PHP_FUNCTION(datadog_appsec_testing_abort_static_page)
{
    UNUSED(return_value);
    if (zend_parse_parameters_none() == FAILURE) {
        return;
    }
    dd_request_abort_static_page();
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(no_params_void_ret, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "abort_static_page", PHP_FN(datadog_appsec_testing_abort_static_page), no_params_void_ret, 0)
    PHP_FE_END
};
// clang-format on

void dd_request_abort_startup()
{
    {
        char buf[PATH_MAX];
        char *cwd = getcwd(buf, sizeof buf);
        if (cwd == NULL) {
            mlog(dd_log_warning, "Could not get current working directory");
            _initial_cwd = zend_string_init_interned(ZEND_STRL("/"), 1);
        } else {
            _initial_cwd = zend_string_init_interned(cwd, strlen(cwd), 1);
        }
    }

    _body_error_json_def =
        zend_string_init_interned(ZEND_STRL(static_error_json), 1);

    _body_error_html_def =
        zend_string_init_interned(ZEND_STRL(static_error_html), 1);

    _status_zstr = zend_string_init_interned(ZEND_STRL("status"), 1);
    _headers_zstr = zend_string_init_interned(ZEND_STRL("headers"), 1);
    _body_zstr = zend_string_init_interned(ZEND_STRL("body"), 1);
    _content_type_zstr =
        zend_string_init_interned(ZEND_STRL("Content-Type"), 1);
    _content_length_zstr =
        zend_string_init_interned(ZEND_STRL("Content-Length"), 1);
    _location_zstr = zend_string_init_interned(ZEND_STRL("Location"), 1);
    _content_type_html_zstr =
        zend_string_init_interned(ZEND_STRL(HTML_CONTENT_TYPE), 1);
    _content_type_json_zstr =
        zend_string_init_interned(ZEND_STRL(JSON_CONTENT_TYPE), 1);

    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }

    dd_phpobj_reg_funcs(functions);
}

static zend_string *nonnull _get_json_blocking_template()
{
    zend_string *json_template_file =
        get_DD_APPSEC_HTTP_BLOCKED_TEMPLATE_JSON();
    if (json_template_file != NULL && ZSTR_LEN(json_template_file) > 0) {
        zend_string *nullable body_error_json =
            _read_file_contents(ZSTR_VAL(json_template_file));
        // the very odd logic here is:
        // * if the template file is not found, return an empty template
        // * if the template file is empty, return the default
        if (!body_error_json) {
            return zend_empty_string;
        }
        if (ZSTR_LEN(body_error_json) == 0) {
            zend_string_release(body_error_json);
            return _body_error_json_def;
        }

        return body_error_json;
    }

    return _body_error_json_def;
}

static zend_string *nonnull _get_html_blocking_template()
{
    zend_string *html_template_file =
        get_DD_APPSEC_HTTP_BLOCKED_TEMPLATE_HTML();
    if (html_template_file != NULL && ZSTR_LEN(html_template_file) > 0) {
        zend_string *nullable body_error_html =
            _read_file_contents(ZSTR_VAL(html_template_file));
        if (!body_error_html) {
            return zend_empty_string;
        }
        if (ZSTR_LEN(body_error_html) == 0) {
            zend_string_release(body_error_html);
            return _body_error_html_def;
        }

        return body_error_html;
    }

    return _body_error_html_def;
}
