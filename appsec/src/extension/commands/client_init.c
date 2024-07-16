// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <SAPI.h>
#include <ext/standard/url.h>
#include <php.h>

#include "../commands_helpers.h"
#include "../configuration.h"
#include "../ddappsec.h"
#include "../ddtrace.h"
#include "../logging.h"
#include "../msgpack_helpers.h"
#include "../tags.h"
#include "../version.h"
#include "client_init.h"

static const unsigned int DEFAULT_AGENT_PORT = 8126;
static const char *DEFAULT_AGENT_HOST = "127.0.0.1";
static const unsigned int MAX_TCP_PORT_ALLOWED = UINT16_MAX;

static dd_result _pack_command(mpack_writer_t *nonnull w, void *nullable ctx);
static dd_result _process_response(mpack_node_t root, void *nullable ctx);
static void _process_meta_and_metrics(
    mpack_node_t root, struct req_info *nonnull ctx);
static void _pack_agent_details(mpack_writer_t *nonnull w);

static const dd_command_spec _spec = {
    .name = "client_init",
    .name_len = sizeof("client_init") - 1,
    .num_args = 7,
    .outgoing_cb = _pack_command,
    .incoming_cb = _process_response,
    .config_features_cb = dd_command_process_config_features_unexpected,
};

static void _pack_agent_details(mpack_writer_t *nonnull w)
{
    zend_string *agent_host = get_global_DD_AGENT_HOST();
    zend_string *agent_url = get_global_DD_TRACE_AGENT_URL();
    unsigned int port = get_global_DD_TRACE_AGENT_PORT();
    char *host = NULL;
    php_url *parsed_url = NULL;

    if (agent_host && ZSTR_LEN(agent_host) > 0) {
        host = ZSTR_VAL(agent_host);
    } else if (agent_url && ZSTR_LEN(agent_url) > 0) {
        parsed_url = php_url_parse(ZSTR_VAL(agent_url));
        if (parsed_url) {
#if PHP_VERSION_ID < 70300
            if (parsed_url->host && strlen(parsed_url->host) > 0) {
                host = parsed_url->host;
            }
#else
            if (parsed_url->host && ZSTR_LEN(parsed_url->host) > 0) {
                host = ZSTR_VAL(parsed_url->host);
            }
#endif
            port = parsed_url->port;
        }
    }

    if (!host) {
        host = (char *)DEFAULT_AGENT_HOST;
    }
    if (port <= 0 || port > MAX_TCP_PORT_ALLOWED) {
        port = DEFAULT_AGENT_PORT;
    }

    dd_mpack_write_lstr(w, "host");
    dd_mpack_write_nullable_cstr(w, host);
    dd_mpack_write_lstr(w, "port");
    mpack_write_uint(w, port);

    if (parsed_url) {
        php_url_free(parsed_url);
    }
}

dd_result dd_client_init(dd_conn *nonnull conn, struct req_info *nonnull ctx)
{
    return dd_command_exec_cred(conn, &_spec, ctx);
}

static dd_result _pack_command(
    mpack_writer_t *nonnull w, ATTR_UNUSED void *nullable ctx)
{
    mpack_write(w, (uint32_t)getpid());
    dd_mpack_write_lstr(w, PHP_DDAPPSEC_VERSION);
    dd_mpack_write_lstr(w, PHP_VERSION);

    if (DDAPPSEC_G(enabled) == APPSEC_ENABLED_VIA_REMCFG) {
        mpack_write_nil(w);
    } else {
        mpack_write_bool(w, DDAPPSEC_G(active));
    }

    // Service details
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    mpack_start_map(w, 6);

    dd_mpack_write_lstr(w, "service");
    dd_mpack_write_nullable_cstr(w, ZSTR_VAL(get_DD_SERVICE()));

    dd_mpack_write_lstr(w, "extra_services");
    zval extra_services;
    ZVAL_ARR(&extra_services, get_global_DD_EXTRA_SERVICES());
    dd_mpack_write_zval(w, &extra_services);

    dd_mpack_write_lstr(w, "env");
    dd_mpack_write_nullable_cstr(w, ZSTR_VAL(get_DD_ENV()));

    dd_mpack_write_lstr(w, "tracer_version");
    dd_mpack_write_nullable_cstr(w, dd_trace_version());

    dd_mpack_write_lstr(w, "app_version");
    dd_mpack_write_nullable_cstr(w, ZSTR_VAL(get_DD_VERSION()));

    // We send this empty for now. The helper will check for empty and if so it
    // will generate it
    dd_mpack_write_lstr(w, "runtime_id");
    zend_string *runtime_id = dd_trace_get_formatted_runtime_id(false);
    if (runtime_id == NULL) {
        dd_mpack_write_nullable_cstr(w, "");
    } else {
        dd_mpack_write_nullable_zstr(w, runtime_id);
        zend_string_free(runtime_id);
    }
    mpack_finish_map(w);

    // Engine settings
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    mpack_start_map(w, 6);
    {
        dd_mpack_write_lstr(w, "rules_file");
        const char *rules_file = ZSTR_VAL(get_global_DD_APPSEC_RULES());
        bool has_rules_file = rules_file && *rules_file;

        if (!has_rules_file) {
            mlog(dd_log_info,
                "datadog.appsec.rules was not provided. The helper "
                "will atttempt to use the default file");
        }
        dd_mpack_write_nullable_cstr(w, rules_file);
    }
    dd_mpack_write_lstr(w, "waf_timeout_us");
    mpack_write(w, get_global_DD_APPSEC_WAF_TIMEOUT());

    dd_mpack_write_lstr(w, "trace_rate_limit");
    mpack_write(w, get_global_DD_APPSEC_TRACE_RATE_LIMIT());

    dd_mpack_write_lstr(w, "obfuscator_key_regex");
    dd_mpack_write_nullable_cstr(
        w, ZSTR_VAL(get_global_DD_APPSEC_OBFUSCATION_PARAMETER_KEY_REGEXP()));

    dd_mpack_write_lstr(w, "obfuscator_value_regex");
    dd_mpack_write_nullable_cstr(
        w, ZSTR_VAL(get_global_DD_APPSEC_OBFUSCATION_PARAMETER_VALUE_REGEXP()));

    dd_mpack_write_lstr(w, "schema_extraction");
    mpack_start_map(w, 2);

    dd_mpack_write_lstr(w, "enabled");
    mpack_write_bool(w, get_global_DD_API_SECURITY_ENABLED());

    dd_mpack_write_lstr(w, "sample_rate");
#define MIN_SE_SAMPLE_RATE 0.0001
    double se_sample_rate = get_global_DD_API_SECURITY_REQUEST_SAMPLE_RATE();
    if (se_sample_rate >= MIN_SE_SAMPLE_RATE) {
        mpack_write(w, se_sample_rate);
    } else {
        mpack_write(w, 0.0);
    }

    mpack_finish_map(w);

    mpack_finish_map(w);

    // Remote config settings
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    mpack_start_map(w, 4);

    dd_mpack_write_lstr(w, "enabled");
    mpack_write_bool(w, get_DD_REMOTE_CONFIG_ENABLED());

    _pack_agent_details(w);

    dd_mpack_write_lstr(w, "poll_interval");
    mpack_write_u32(w, get_DD_REMOTE_CONFIG_POLL_INTERVAL());

    mpack_finish_map(w);

    return dd_success;
}

static dd_result _check_helper_version(mpack_node_t root);
static dd_result _process_response(
    mpack_node_t root, ATTR_UNUSED void *nullable ctx)
{
    // Add any tags and metrics provided by the helper
    _process_meta_and_metrics(root, ctx);

    // check verdict
    mpack_node_t verdict = mpack_node_array_at(root, 0);
    bool is_ok = dd_mpack_node_lstr_eq(verdict, "ok");
    if (is_ok) {
        mlog(dd_log_debug, "Response to client_init is ok");

        return _check_helper_version(root);
    }

    // not ok, in which case expect at least one error message

    const char *ver = mpack_node_str(verdict);
    size_t verlen = mpack_node_strlen(verdict);

    mpack_node_t errors = mpack_node_array_at(root, 2);
    mpack_node_t first_error_node = mpack_node_array_at(errors, 0);
    const char *first_error = mpack_node_str(first_error_node);
    size_t first_error_len = mpack_node_strlen(first_error_node);

    mpack_error_t err = mpack_node_error(verdict);
    if (err != mpack_ok) {
        mlog(dd_log_warning, "Unexpected client_init response: %s",
            mpack_error_to_string(err));
    } else {
        if (verlen > INT_MAX) {
            verlen = INT_MAX;
        }
        if (first_error_len > INT_MAX) {
            first_error_len = INT_MAX;
        }

        mlog(dd_log_warning, "Response to client_init is not ok: %.*s: %.*s",
            (int)verlen, ver, (int)first_error_len, first_error);
    }

    return dd_error;
}

static void _process_meta_and_metrics(
    mpack_node_t root, struct req_info *nonnull ctx)
{
    zend_object *span = ctx->root_span;
    if (!span) {
        mlog(
            dd_log_debug, "Meta/metrics in client_init ignored (no root span)");
        return;
    }

    mpack_node_t meta = mpack_node_array_at(root, 3);
    if (mpack_node_map_count(meta) > 0) {
        dd_command_process_meta(meta, span);
    }

    mpack_node_t metrics = mpack_node_array_at(root, 4);
    dd_command_process_metrics(metrics, span);

    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    if (mpack_node_array_length(root) >= 6) {
        // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
        mpack_node_t tel_metrics = mpack_node_array_at(root, 5);
        dd_command_process_telemetry_metrics(tel_metrics);
    }
}

static dd_result _check_helper_version(mpack_node_t root)
{
    mpack_node_t version_node = mpack_node_array_at(root, 1);
    const char *version = mpack_node_str(version_node);
    size_t version_len = mpack_node_strlen(version_node);
    int version_len_int = version_len > INT_MAX ? INT_MAX : (int)version_len;
    mlog(
        dd_log_debug, "Helper reported version %.*s", version_len_int, version);

    if (!version) {
        mlog(dd_log_warning, "Malformed client_init response when "
                             "reading helper version");
        return dd_error;
    }
    if (!STR_CONS_EQ(version, version_len, PHP_DDAPPSEC_VERSION)) {
        mlog(dd_log_warning,
            "Mismatch of helper and extension version. "
            "helper %.*s and extension %s",
            version_len_int, version, PHP_DDAPPSEC_VERSION);
        return dd_error;
    }
    return dd_success;
}
