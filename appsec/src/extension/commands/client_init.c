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
#include "../php_compat.h"
#include "../version.h"
#include "client_init.h"

static dd_result _pack_command(mpack_writer_t *nonnull w, void *nullable ctx);
static dd_result _process_response(mpack_node_t root, void *nullable ctx);
static void _process_meta_and_metrics(
    mpack_node_t root, struct req_info *nonnull ctx);

static const dd_command_spec _spec = {
    .name = "client_init",
    .name_len = sizeof("client_init") - 1,
    .num_args = 8,
    .outgoing_cb = _pack_command,
    .incoming_cb = _process_response,
    .config_features_cb = dd_command_process_config_features_unexpected,
};

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

    // Engine settings
    // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
    mpack_start_map(w, 6);
    {
        dd_mpack_write_lstr(w, "rules_file");
        const char *rules_file = ZSTR_VAL(get_global_DD_APPSEC_RULES());
        bool has_rules_file = rules_file && *rules_file;

        if (!has_rules_file) {
            mlog(dd_log_debug,
                "datadog.appsec.rules was not provided. The helper "
                "will atttempt to use the default file/remote config");
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

    dd_mpack_write_lstr(w, "sampling_period");
    double delay = get_global_DD_API_SECURITY_SAMPLE_DELAY();
    if (delay < 0) {
        mlog_g(dd_log_debug,
            "Negative value for DD_API_SECURITY_SAMPLE_DELAY; setting to 0");
        delay = 0;
    }
    mpack_write(w, delay);

    mpack_finish_map(w); // schema_extraction

    mpack_finish_map(w); // engine settings

    struct telemetry_rc_info tel_rc_info = dd_trace_get_telemetry_rc_info();

    // Remote config settings
    mpack_start_map(w, 2);

    dd_mpack_write_lstr(w, "enabled");
    mpack_write_bool(w, get_DD_REMOTE_CONFIG_ENABLED());

    dd_mpack_write_lstr(w, "shmem_path");
    dd_mpack_write_nullable_cstr(w, tel_rc_info.rc_path);

    mpack_finish_map(w); // remote config settings

    // Telemetry settings
    mpack_start_map(w, 2);

    dd_mpack_write_lstr(w, "service_name");
    dd_mpack_write_nullable_zstr(w, tel_rc_info.service_name);

    dd_mpack_write_lstr(w, "env_name");
    dd_mpack_write_nullable_zstr(w, tel_rc_info.env_name);

    mpack_finish_map(w); // telemetry settings

    // Sidecar settings
    mpack_start_map(w, 2);
    {
        dd_mpack_write_lstr(w, "session_id");
        const uint8_t *session_id = dd_trace_get_formatted_session_id();
#define SESSION_ID_LENGTH 36
        if (session_id) {
            mpack_write_str(w, (const char *)session_id, SESSION_ID_LENGTH);
        } else {
            mpack_write_str(w, "", 0);
        }
    }
    {
        dd_mpack_write_lstr(w, "runtime_id");
        zend_string *runtime_id_zstr = dd_trace_get_formatted_runtime_id(false);
        dd_mpack_write_nullable_zstr(w, runtime_id_zstr);
        if (runtime_id_zstr) {
            zend_string_release(runtime_id_zstr);
        }
    }
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
