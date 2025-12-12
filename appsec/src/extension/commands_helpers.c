// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "commands_helpers.h"
#include "backtrace.h"
#include "commands_ctx.h"
#include "ddappsec.h"
#include "ddtrace.h"
#include "logging.h"
#include "msgpack_helpers.h"
#include "request_abort.h"
#include "tags.h"
#include "telemetry.h"
#include "user_tracking.h"
#include <ext/standard/base64.h>
#include <mpack.h>
#include <stdatomic.h>

typedef struct _dd_omsg {
    zend_llist iovecs;
    mpack_writer_t writer;
} dd_omsg;

static inline void _omsg_init(dd_omsg *nonnull omsg, const char *nonnull cmd,
    size_t cmd_len, size_t num_args);
static inline ATTR_WARN_UNUSED mpack_error_t _omsg_finish(
    dd_omsg *nonnull omsg);
static inline void _omsg_destroy(dd_omsg *nonnull omsg);
static inline dd_result _omsg_send(
    dd_conn *nonnull conn, dd_omsg *nonnull omsg);
static void _dump_in_msg(
    dd_log_level_t lvl, const char *nonnull data, size_t data_len);
static void _dump_out_msg(dd_log_level_t lvl, zend_llist *iovecs);

typedef struct _dd_imsg {
    char *unspecnull _data;
    size_t _size;
    mpack_tree_t _tree;
    mpack_node_t root;
} dd_imsg;

// if and only if this returns success, _imsg_destroy must be called
static dd_result ATTR_WARN_UNUSED _imsg_recv(
    dd_imsg *nonnull imsg, dd_conn *nonnull conn);

static inline ATTR_WARN_UNUSED mpack_error_t _imsg_destroy(
    dd_imsg *nonnull imsg);
static void _imsg_cleanup(dd_imsg *nullable *imsg);

static void _set_redirect_code_and_location(
    struct block_params *nonnull block_params,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    int code, zend_string *nullable location,
    zend_string *nullable security_response_id);

static void _set_block_code_and_type(struct block_params *nonnull block_params,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    int code, dd_response_type type,
    zend_string *nullable security_response_id);

static dd_result _dd_command_exec(dd_conn *nonnull conn,
    const dd_command_spec *nonnull spec, void *unspecnull ctx)
{
#define NAME_L (int)spec->name_len, spec->name
    mlog(dd_log_debug, "Will start command %.*s with helper", NAME_L);

    // out
    {
        dd_omsg omsg;
        _omsg_init(&omsg, spec->name, spec->name_len, spec->num_args);
        dd_result res = spec->outgoing_cb(&omsg.writer, ctx);
        if (res) {
            mlog(dd_log_warning, "Error creating message for command %.*s: %s",
                NAME_L, dd_result_to_string(res));
            _omsg_destroy(&omsg);
            return res;
        }

        mpack_error_t err = _omsg_finish(&omsg);
        if (err != mpack_ok) {
            mlog(dd_log_warning,
                "Error serializing message for command %.*s: %s", NAME_L,
                mpack_error_to_string(err));
            _omsg_destroy(&omsg);
            return dd_network;
        }

        res = _omsg_send(conn, &omsg);
        _dump_out_msg(dd_log_trace, &omsg.iovecs);
        _omsg_destroy(&omsg);
        if (res) {
            mlog(dd_log_warning, "Error sending message for command %.*s: %s",
                NAME_L, dd_result_to_string(res));
            return res;
        }
    }

    // in
    dd_result res;
    {
        dd_imsg imsg = {0};
        res = _imsg_recv(&imsg, conn);
        if (res) {
            mlog(dd_log_warning, "Error receiving reply for command %.*s: %s",
                NAME_L, dd_result_to_string(res));
            return res;
        }

        // automatic cleanup of imsg on error branches
        // set to NULL before calling _imsg_destroy
        // NOLINTNEXTLINE(clang-analyzer-deadcode.DeadStores)
        __attribute__((cleanup(_imsg_cleanup))) dd_imsg *nullable destroy_imsg =
            &imsg;

        // TODO: it seems we only look at the first response? Why even support
        //       several responses then?
        mpack_node_t first_response = mpack_node_array_at(imsg.root, 0);
        mpack_error_t err = mpack_node_error(first_response);
        if (err != mpack_ok) {
            mlog(dd_log_error, "Array of responses could not be retrieved - %s",
                mpack_error_to_string(err));
            return dd_error;
        }
        if (mpack_node_type(first_response) != mpack_type_array) {
            mlog(dd_log_error, "Invalid response. Expected array but got %s",
                mpack_type_to_string(mpack_node_type(first_response)));
            return dd_error;
        }
        mpack_node_t first_message = mpack_node_array_at(first_response, 1);
        err = mpack_node_error(first_message);
        if (err != mpack_ok) {
            mlog(dd_log_error,
                "Message on first response could not be retrieved - %s",
                mpack_error_to_string(err));
        }

        mpack_node_t type = mpack_node_array_at(first_response, 0);
        err = mpack_node_error(type);
        if (err != mpack_ok) {
            mlog(dd_log_error, "Response type could not be retrieved - %s",
                mpack_error_to_string(err));
            return dd_error;
        }
        if (mpack_node_type(type) != mpack_type_str) {
            mlog(dd_log_error,
                "Unexpected type field. Expected string but got %s",
                mpack_type_to_string(mpack_node_type(type)));
            return dd_error;
        }
        if (dd_mpack_node_lstr_eq(type, "config_features")) {
            res = spec->config_features_cb(first_message, ctx);
        } else if (dd_mpack_node_str_eq(type, spec->name, spec->name_len)) {
            res = spec->incoming_cb(first_message, ctx);
        } else if (dd_mpack_node_lstr_eq(type, "error")) {
            mlog(dd_log_info,
                "Helper responded with an error. Check helper logs");
            return dd_helper_error;
        } else {
            mlog(dd_log_debug,
                "Received message for command %.*s unexpected: \"%.*s\". "
                "Expected \"config_features\", \"error\" or \"%.*s\"",
                NAME_L, (int)mpack_node_strlen(type), mpack_node_str(type),
                NAME_L);
            return dd_error;
        }

        mlog(dd_log_debug, "Processing for command %.*s returned %s", NAME_L,
            dd_result_to_string(res));
        err = imsg.root.tree->error;
        _dump_in_msg(err == mpack_ok ? dd_log_trace : dd_log_debug, imsg._data,
            imsg._size);
        destroy_imsg = NULL;
        err = _imsg_destroy(&imsg);
        if (err != mpack_ok) {
            mlog(dd_log_warning,
                "Response message for %.*s does not have the expected form",
                NAME_L);

            return dd_error;
        }
        if (res != dd_success && res != dd_should_block &&
            res != dd_should_redirect && res != dd_should_record) {
            mlog(dd_log_warning, "Processing for command %.*s failed: %s",
                NAME_L, dd_result_to_string(res));
            return res;
        }
    }

    mlog(dd_log_info, "%.*s succeed and told to %s", NAME_L,
        dd_result_to_string(res));

    return res;
}

dd_result ATTR_WARN_UNUSED dd_command_exec(dd_conn *nonnull conn,
    const dd_command_spec *nonnull spec, void *unspecnull ctx)
{
    return _dd_command_exec(conn, spec, ctx);
}

dd_result ATTR_WARN_UNUSED dd_command_exec_req_info(dd_conn *nonnull conn,
    const dd_command_spec *nonnull spec, struct req_info *nonnull ctx)
{
    ctx->command_name = spec->name;
    return _dd_command_exec(conn, spec, ctx);
}

dd_result ATTR_WARN_UNUSED dd_command_exec_cred(dd_conn *nonnull conn,
    const dd_command_spec *nonnull spec, void *unspecnull ctx)
{
    dd_result res = dd_conn_check_credentials(conn);
    if (res) {
        return res;
    }

    return _dd_command_exec(conn, spec, ctx);
}

// outgoing
static inline void _omsg_init(dd_omsg *nonnull omsg, const char *nonnull cmd,
    size_t cmd_len, // NOLINT(bugprone-easily-swappable-parameters)
    size_t num_args)
{
    mlog(dd_log_debug, "Creating message of type %.*s", (int)cmd_len, cmd);

    dd_mpack_writer_init_iov(&omsg->writer, &omsg->iovecs);

    // [ cmd, [arguments...] ]
    mpack_start_array(&omsg->writer, 2);
    mpack_write_str(&omsg->writer, cmd, cmd_len);
    mpack_start_array(&omsg->writer, num_args);
}

static inline ATTR_WARN_UNUSED mpack_error_t _omsg_finish(dd_omsg *nonnull omsg)
{
    mpack_finish_array(&omsg->writer);
    mpack_finish_array(&omsg->writer);
    return mpack_writer_destroy(&omsg->writer);
}

static inline void _omsg_destroy(dd_omsg *nonnull omsg)
{
    if (omsg->writer.buffer) {
        // mpack_writer_destroy not called yet
        omsg->writer.flush = NULL; // no point flushing
        UNUSED(mpack_writer_destroy(&omsg->writer));
    }
    zend_llist_destroy(&omsg->iovecs);
}

static inline dd_result _omsg_send(dd_conn *nonnull conn, dd_omsg *nonnull omsg)
{
    return dd_conn_sendv(conn, &omsg->iovecs);
}

// incoming
static ATTR_WARN_UNUSED dd_result _imsg_recv(
    dd_imsg *nonnull imsg, dd_conn *nonnull conn)
{
    mlog(dd_log_debug, "Will receive response from helper");

    dd_result res = dd_conn_recv(conn, &imsg->_data, &imsg->_size);
    if (res) {
        return res;
    }

    mpack_tree_init(&imsg->_tree, imsg->_data, imsg->_size);
    mpack_tree_parse(&imsg->_tree);
    imsg->root = mpack_tree_root(&imsg->_tree);
    mpack_error_t err = mpack_tree_error(&imsg->_tree);
    if (err != mpack_ok) {
        mlog(dd_log_warning, "Error parsing msgpack message: %s",
            mpack_error_to_string(err));
        _dump_in_msg(dd_log_debug, imsg->_data, imsg->_size);
        UNUSED(_imsg_destroy(imsg));
        return dd_error;
    }

    return dd_success;
}

static inline ATTR_WARN_UNUSED mpack_error_t _imsg_destroy(
    dd_imsg *nonnull imsg)
{
    efree(imsg->_data);
    imsg->_data = NULL;
    imsg->_size = 0;
    return mpack_tree_destroy(&imsg->_tree);
}

static void _imsg_cleanup(dd_imsg *nullable *imsg)
{
    dd_imsg **imsg_c = (dd_imsg * nullable * nonnull) imsg;
    if (*imsg_c) {
        UNUSED(_imsg_destroy(*imsg_c));
    }
}

/* Baked response */

static void _add_appsec_span_data_frag(mpack_node_t node);
static void _set_appsec_span_data(mpack_node_t node);

static void _command_process_block_parameters(
    struct block_params *nonnull block_params, mpack_node_t root)
{
    int status_code = DEFAULT_BLOCKING_RESPONSE_CODE;
    dd_response_type type = DEFAULT_RESPONSE_TYPE;
    zend_string *security_response_id = NULL;

    int expected_nodes = 3;
    size_t count = mpack_node_map_count(root);
    for (size_t i = 0; i < count && expected_nodes > 0; i++) {
        mpack_node_t key = mpack_node_map_key_at(root, i);
        mpack_node_t value = mpack_node_map_value_at(root, i);

        if (mpack_node_type(key) != mpack_type_str) {
            mlog(dd_log_warning,
                "Failed to add response parameter: invalid type for key");
            continue;
        }
        if (mpack_node_type(value) != mpack_type_str) {
            mlog(dd_log_warning,
                "Failed to add response parameter: invalid type for value");
            continue;
        }

        if (dd_mpack_node_lstr_eq(key, "status_code")) {
            size_t code_len = mpack_node_strlen(value);
            if (code_len != 3) {
                mlog(dd_log_warning, "Invalid http status code received %.*s",
                    (int)code_len, mpack_node_str(value));
                continue;
            }

            char code_str[4] = {0};
            memcpy(code_str, mpack_node_str(value), 3);

            const int base = 10;
            long parsed_value = strtol(code_str, NULL, base);
            // NOLINTNEXTLINE(cppcoreguidelines-avoid-magic-numbers,readability-magic-numbers)
            if (parsed_value > 99 && parsed_value < 1000) {
                status_code = (int)parsed_value;
            }
            --expected_nodes;
        } else if (dd_mpack_node_lstr_eq(key, "type")) {
            if (dd_mpack_node_lstr_eq(value, "json")) {
                type = response_type_json;
            } else if (dd_mpack_node_lstr_eq(value, "html")) {
                type = response_type_html;
            } else if (dd_mpack_node_lstr_eq(value, "auto")) {
                type = response_type_auto;
            } else {
                mlog(dd_log_warning, "Invalid http content-type received %.*s",
                    (int)mpack_node_strlen(value), mpack_node_str(value));
                continue;
            }
            --expected_nodes;
        } else if (dd_mpack_node_lstr_eq(key, "security_response_id")) {
            size_t security_response_id_len = mpack_node_strlen(value);
            security_response_id = zend_string_init(
                mpack_node_str(value), security_response_id_len, 0);
            --expected_nodes;
        }
    }

    mlog(dd_log_debug,
        "Blocking parameters: status_code=%d, type=%d, security_response_id=%s",
        status_code, type,
        security_response_id ? ZSTR_VAL(security_response_id) : "NULL");
    _set_block_code_and_type(
        block_params, status_code, type, security_response_id);
}

static void _command_process_redirect_parameters(
    struct block_params *nonnull block_params, mpack_node_t root)
{
    int status_code = 0;
    zend_string *location = NULL;
    zend_string *security_response_id = NULL;

    int expected_nodes = 3;
    size_t count = mpack_node_map_count(root);
    for (size_t i = 0; i < count && expected_nodes > 0; i++) {
        mpack_node_t key = mpack_node_map_key_at(root, i);
        mpack_node_t value = mpack_node_map_value_at(root, i);

        if (mpack_node_type(key) != mpack_type_str) {
            mlog(dd_log_warning,
                "Failed to add response parameter: invalid type for key");
            continue;
        }
        if (mpack_node_type(value) != mpack_type_str) {
            mlog(dd_log_warning,
                "Failed to add response parameter: invalid type for value");
            continue;
        }

        if (dd_mpack_node_lstr_eq(key, "status_code")) {
            size_t code_len = mpack_node_strlen(value);
            if (code_len != 3) {
                mlog(dd_log_warning, "Invalid http status code received %.*s",
                    (int)code_len, mpack_node_str(value));
                continue;
            }

            char code_str[4] = {0};
            memcpy(code_str, mpack_node_str(value), 3);

            const int base = 10;
            long parsed_value = strtol(code_str, NULL, base);
            status_code = (int)parsed_value;
            --expected_nodes;
        } else if (dd_mpack_node_lstr_eq(key, "location")) {
            size_t location_len = mpack_node_strlen(value);
            location = zend_string_init(mpack_node_str(value), location_len, 0);
            --expected_nodes;
        } else if (dd_mpack_node_lstr_eq(key, "security_response_id")) {
            size_t security_response_id_len = mpack_node_strlen(value);
            security_response_id = zend_string_init(
                mpack_node_str(value), security_response_id_len, 0);
            --expected_nodes;
        }
    }

    mlog(dd_log_debug,
        "Redirect parameters: status_code=%d, location=%s, "
        "security_response_id=%s",
        status_code, location ? ZSTR_VAL(location) : "NULL",
        security_response_id ? ZSTR_VAL(security_response_id) : "NULL");

    _set_redirect_code_and_location(
        block_params, status_code, location, security_response_id);
}

static void _set_block_code_and_type(struct block_params *nonnull block_params,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    int code, dd_response_type type, zend_string *nullable security_response_id)
{
    dd_response_type _type = type;
    // Account for lack of enum type safety
    switch (type) {
    case response_type_auto:
    case response_type_html:
    case response_type_json:
        _type = type;
        break;
    default:
        _type = response_type_auto;
        break;
    }

    block_params->security_response_id = security_response_id;
    block_params->response_type = _type;
    block_params->response_code = code;
    block_params->redirection_location = NULL;
}

static void _set_redirect_code_and_location(
    struct block_params *nonnull block_params,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    int code, zend_string *nullable location,
    zend_string *nullable security_response_id)
{
    block_params->security_response_id = security_response_id;
    block_params->response_type = response_type_auto;
    block_params->response_code = code;
    block_params->redirection_location = location;
}

static void _command_process_stack_trace_parameters(mpack_node_t root)
{
    size_t count = mpack_node_map_count(root);
    for (size_t i = 0; i < count; i++) {
        mpack_node_t key = mpack_node_map_key_at(root, i);
        mpack_node_t value = mpack_node_map_value_at(root, i);
        if (dd_mpack_node_lstr_eq(key, "stack_id")) {
            zend_string *id = NULL;
            size_t id_len = mpack_node_strlen(value);
            id = zend_string_init(mpack_node_str(value), id_len, 0);
            dd_report_exploit_backtrace(id);
            zend_string_release(id);
            break;
        }
    }
}

static dd_result _command_process_actions(
    mpack_node_t root, struct req_info *ctx);

static void dd_command_process_settings(mpack_node_t root);

/*
 * array(
 *    0: [<"ok" / "record" / "block" / "redirect">,
 *         [if block/redirect parameters: (map)]]
 *    1: [if block/redirect/record: appsec span data (array of strings: json
 *        fragments)],
 *    2: [force keep: bool]
 *    3: [meta: map]
 *    4: [metrics: map]
 * )
 */
#define RESP_INDEX_ACTION_PARAMS 0
#define RESP_INDEX_APPSEC_SPAN_DATA 1
#define RESP_INDEX_FORCE_KEEP 2
#define RESP_INDEX_SETTINGS 3
#define RESP_INDEX_SPAN_META 4
#define RESP_INDEX_SPAN_METRICS 5

dd_result dd_command_proc_resp_verd_span_data(
    mpack_node_t root, void *unspecnull _ctx)
{
    struct req_info *ctx = _ctx;
    assert(ctx != NULL);

    mpack_node_t actions = mpack_node_array_at(root, RESP_INDEX_ACTION_PARAMS);
    dd_result res = _command_process_actions(actions, ctx);

    if (res == dd_should_block || res == dd_should_redirect ||
        res == dd_should_record) {
        _set_appsec_span_data(
            mpack_node_array_at(root, RESP_INDEX_APPSEC_SPAN_DATA));
    }

    mpack_node_t force_keep = mpack_node_array_at(root, RESP_INDEX_FORCE_KEEP);
    if (mpack_node_type(force_keep) == mpack_type_bool &&
        mpack_node_bool(force_keep)) {
        dd_trace_emit_asm_event();
    }

    if (mpack_node_array_length(root) >= RESP_INDEX_SETTINGS + 1) {
        mpack_node_t settings = mpack_node_array_at(root, RESP_INDEX_SETTINGS);
        dd_command_process_settings(settings);
    }

    if (mpack_node_array_length(root) >= RESP_INDEX_SPAN_METRICS + 1 &&
        ctx->root_span) {
        zend_object *span = ctx->root_span;

        mpack_node_t meta = mpack_node_array_at(root, RESP_INDEX_SPAN_META);
        dd_command_process_meta(meta, span);
        mpack_node_t metrics =
            mpack_node_array_at(root, RESP_INDEX_SPAN_METRICS);
        dd_command_process_metrics(metrics, span);
    }

    return res;
}

static dd_result _command_process_actions(
    mpack_node_t root, struct req_info *ctx)
{
    size_t actions = mpack_node_array_length(root);
    dd_result res = dd_success;

    for (size_t i = 0; i < actions; i++) {
        mpack_node_t action = mpack_node_array_at(root, i);

        // expected: ['ok' / 'record' / 'block' / 'redirect']
        mpack_node_t verdict = mpack_node_array_at(action, 0);
        if (mlog_should_log(dd_log_debug)) {
            const char *verd_str = mpack_node_str(verdict);
            size_t verd_len = mpack_node_strlen(verdict);
            if (verd_len > INT_MAX) {
                verd_len = INT_MAX;
            }
            mlog(dd_log_debug, "Verdict of %s was '%.*s'",
                ctx->command_name ? ctx->command_name : "(unknown)",
                (int)verd_len, verd_str);
        }

        // Parse parameters
        if (dd_mpack_node_lstr_eq(verdict, "block") && res != dd_should_block &&
            res != dd_should_redirect) { // Redirect take over block
            res = dd_should_block;
            _command_process_block_parameters(
                &ctx->block_params, mpack_node_array_at(action, 1));
            dd_tags_add_blocked();
        } else if (dd_mpack_node_lstr_eq(verdict, "redirect") &&
                   res != dd_should_redirect) {
            res = dd_should_redirect;
            _command_process_redirect_parameters(
                &ctx->block_params, mpack_node_array_at(action, 1));
            dd_tags_add_blocked();
        } else if (dd_mpack_node_lstr_eq(verdict, "record") &&
                   res == dd_success) {
            res = dd_should_record;
        } else if (dd_mpack_node_lstr_eq(verdict, "stack_trace")) {
            _command_process_stack_trace_parameters(
                mpack_node_array_at(action, 1));
        }
    }

    return res;
}

static void _add_appsec_span_data_frag(mpack_node_t node)
{
    const char *data = mpack_node_data(node);
    size_t len = mpack_node_data_len(node);
    if (data == NULL || data[0] == '\0' || len == 0) {
        mlog(dd_log_warning, "Empty appsec event data. Bug");
        return;
    }
    if (len >= DD_TAG_DATA_MAX_LEN) {
        mlog(dd_log_warning,
            "Appsec event data has size %zu, which exceed the maximum %zu", len,
            DD_TAG_DATA_MAX_LEN);
        return;
    }

    zend_string *data_zstr = zend_string_init(data, len, 0);
    dd_tags_add_appsec_json_frag(data_zstr);
}

static void _set_appsec_span_data(mpack_node_t node)
{
    for (size_t i = 0; i < mpack_node_array_length(node); i++) {
        mpack_node_t frag = mpack_node_array_at(node, i);
        _add_appsec_span_data_frag(frag);
    }
}

static void dd_command_process_settings(mpack_node_t root)
{
    if (mpack_node_type(root) != mpack_type_map) {
        return;
    }

    size_t count = mpack_node_map_count(root);

    for (size_t i = 0; i < count; i++) {
        mpack_node_t key = mpack_node_map_key_at(root, i);
        mpack_node_t value = mpack_node_map_value_at(root, i);

        if (mpack_node_type(key) != mpack_type_str) {
            mlog(dd_log_warning, "Failed to process unknown setting: "
                                 "invalid type for key");
            continue;
        }
        if (mpack_node_type(value) != mpack_type_str) {
            mlog(dd_log_warning, "Failed to process unknown setting: "
                                 "invalid type for value");
            continue;
        }

        const char *key_str = mpack_node_str(key);
        const char *value_str = mpack_node_str(value);
        size_t key_len = mpack_node_strlen(key);
        size_t value_len = mpack_node_strlen(value);

        if (dd_string_equals_lc(
                key_str, key_len, ZEND_STRL("auto_user_instrum"))) {
            dd_parse_user_collection_mode_rc(value_str, value_len);
        } else {
            if (!get_global_DD_APPSEC_TESTING()) {
                mlog(dd_log_warning,
                    "Failed to process user collection setting: "
                    "unknown key %.*s",
                    (int)key_len, key_str);
            }
        }
    }
}

void dd_command_process_meta(mpack_node_t root, zend_object *nonnull span)
{
    if (mpack_node_type(root) != mpack_type_map) {
        return;
    }

    size_t count = mpack_node_map_count(root);
    bool has_schemas = false;

    for (size_t i = 0; i < count; i++) {
        mpack_node_t key = mpack_node_map_key_at(root, i);
        mpack_node_t value = mpack_node_map_value_at(root, i);

        if (mpack_node_type(key) != mpack_type_str) {
            mlog(dd_log_warning, "Failed to add tags: invalid type for key");
            return;
        }
        if (mpack_node_type(value) != mpack_type_str) {
            mlog(dd_log_warning, "Failed to add tags: invalid type for value");
            return;
        }

        const char *key_str = mpack_node_str(key);
        size_t key_len = mpack_node_strlen(key);
        if (key_len > INT_MAX) {
            key_len = INT_MAX;
        }

        if (!has_schemas && dd_string_starts_with_lc(
                                key_str, key_len, ZEND_STRL("_dd.appsec.s."))) {
            // There is schemas extrated
            has_schemas = true;
        }

        bool res = dd_trace_span_add_tag_str(span, key_str, key_len,
            mpack_node_str(value), mpack_node_strlen(value));

        if (!res) {
            mlog(dd_log_warning, "Failed to add tag %.*s", (int)key_len,
                key_str);
            return;
        }
    }

    if (has_schemas && !get_DD_APM_TRACING_ENABLED()) {
        dd_trace_emit_asm_event();
    }
}

bool dd_command_process_metrics(mpack_node_t root, zend_object *nonnull span)
{
    zval *metrics_zv = dd_trace_span_get_metrics(span);
    if (metrics_zv == NULL) {
        return false;
    }

    if (mpack_node_type(root) != mpack_type_map) {
        return false;
    }

    for (size_t i = 0; i < mpack_node_map_count(root); i++) {
        mpack_node_t key = mpack_node_map_key_at(root, i);
        mpack_node_t value = mpack_node_map_value_at(root, i);

        if (mpack_node_type(key) != mpack_type_str) {
            mlog(dd_log_warning, "Failed to add metric: invalid type for key");
            return false;
        }

        zval zv;
        switch (mpack_node_type(value)) {
        case mpack_type_float:
            ZVAL_DOUBLE(&zv, mpack_node_float(value));
            break;
        case mpack_type_double:
            ZVAL_DOUBLE(&zv, mpack_node_double(value));
            break;
        case mpack_type_int:
            ZVAL_LONG(&zv, mpack_node_int(value));
            break;
        case mpack_type_uint:
            ZVAL_LONG(&zv, mpack_node_uint(value));
            break;
        default:
            mlog(
                dd_log_warning, "Failed to add metric: invalid type for value");
            return false;
        }

        const char *key_str = mpack_node_str(key);
        size_t key_len = mpack_node_strlen(key);
        if (key_len > INT_MAX) {
            key_len = INT_MAX;
        }

        zend_string *ztag = zend_string_init(key_str, key_len, 0);

        mlog(dd_log_debug, "Adding to root span the metric '%.*s'",
            (int)key_len, key_str);

        zval *res = zend_hash_add(Z_ARRVAL_P(metrics_zv), ztag, &zv);
        zend_string_release(ztag);

        if (res == NULL) {
            mlog(dd_log_warning, "Failed to add metric %.*s", (int)key_len,
                key_str);
            zval_ptr_dtor(&zv);
            return false;
        }
    }

    return true;
}

static void _dump_in_msg(
    dd_log_level_t lvl, const char *nonnull data, size_t data_len)
{
    if (!mlog_should_log(lvl)) {
        return;
    }
    zend_string *zstr =
        php_base64_encode((const unsigned char *)data, data_len);
    if (ZSTR_LEN(zstr) > INT_MAX) {
        return;
    }
    mlog(lvl, "Contents of message (base64 encoded): %.*s", (int)ZSTR_LEN(zstr),
        ZSTR_VAL(zstr));
    zend_string_release(zstr);
}

static void _dump_out_msg(dd_log_level_t lvl, zend_llist *iovecs)
{
    if (!mlog_should_log(lvl)) {
        return;
    }
    zend_llist_position pos;
    int i = 1;
    for (struct iovec *iov = zend_llist_get_first_ex(iovecs, &pos); iov;
         iov = zend_llist_get_next_ex(iovecs, &pos), i++) {
        zend_string *zstr = php_base64_encode(iov->iov_base, iov->iov_len);
        if (ZSTR_LEN(zstr) > INT_MAX) {
            return;
        }
        mlog(lvl, "Contents of message (base64 encoded) (part %d): %.*s", i,
            (int)ZSTR_LEN(zstr), ZSTR_VAL(zstr));
        zend_string_release(zstr);
    }
}

dd_result dd_command_process_config_features(
    mpack_node_t root, ATTR_UNUSED void *nullable ctx)
{
    UNUSED(ctx);
    mpack_node_t first_element = mpack_node_array_at(root, 0);
    bool new_status = mpack_node_bool(first_element);

    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_ENABLED && !new_status) {
        mlog(dd_log_debug, "Remote config is trying to disable extension but "
                           "it is enabled by config");
    } else {
        DDAPPSEC_G(to_be_configured) = false;

        if (DDAPPSEC_G(active) == new_status) {
            mlog(dd_log_debug,
                "Remote config has not changed extension status: still %s",
                new_status ? "enabled" : "disabled");
        } else {
            mlog(dd_log_info,
                "Remote config has changed extension status from %s to %s",
                DDAPPSEC_G(active) ? "enabled" : "disabled",
                new_status ? "enabled" : "disabled");
            DDAPPSEC_G(active) = new_status;
        }
    }
    return dd_success;
}

dd_result dd_command_process_config_features_unexpected(
    mpack_node_t root, ATTR_UNUSED void *nullable ctx)
{
    UNUSED(root);
    UNUSED(ctx);
    mlog(dd_log_debug, "Unexpected config_features response to request");

    return dd_error;
}
