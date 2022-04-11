// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "commands_helpers.h"
#include "ddtrace.h"
#include "msgpack_helpers.h"
#include "tags.h"

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
static inline dd_result _omsg_send_cred(
    dd_conn *nonnull conn, dd_omsg *nonnull omsg);

typedef struct _dd_imsg {
    char *unspecnull _data;
    size_t _size;
    mpack_tree_t _tree;
    mpack_node_t root;
} dd_imsg;

// iif these two return success, _imsg_destroy must be called
static inline dd_result _imsg_recv(
    dd_imsg *nonnull imsg, dd_conn *nonnull conn);
static inline ATTR_WARN_UNUSED dd_result _imsg_recv_cred(
    dd_imsg *nonnull imsg, dd_conn *nonnull conn);

static inline ATTR_WARN_UNUSED mpack_error_t _imsg_destroy(
    dd_imsg *nonnull imsg);

static dd_result _dd_command_exec(dd_conn *nonnull conn, bool check_cred,
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
            return dd_error;
        }

        if (check_cred) {
            res = _omsg_send_cred(conn, &omsg);
        } else {
            res = _omsg_send(conn, &omsg);
        }
        _omsg_destroy(&omsg);
        if (res) {
            mlog(dd_log_warning, "Error sending message for command %.*s: %s",
                NAME_L, dd_result_to_string(res));
            return res;
        }
    }

    // in
    bool should_block;
    {
        dd_imsg imsg = {0};
        dd_result res;
        if (check_cred) {
            res = _imsg_recv_cred(&imsg, conn);
        } else {
            res = _imsg_recv(&imsg, conn);
        }
        if (res) {
            mlog(dd_log_warning, "Error receiving reply for command %.*s: %s",
                NAME_L, dd_result_to_string(res));
            return res;
        }

        res = spec->incoming_cb(imsg.root, ctx);
        mlog(dd_log_debug, "Processing for command %.*s returned %s", NAME_L,
            dd_result_to_string(res));
        mpack_error_t err = _imsg_destroy(&imsg);
        if (err != mpack_ok) {
            mlog(dd_log_warning,
                "Response message for %.*s does not "
                "have the expected form",
                NAME_L);
            return dd_error;
        }
        if (res != dd_success && res != dd_should_block) {
            mlog(dd_log_warning, "Processing for command %.*s failed: %s",
                NAME_L, dd_result_to_string(res));
            return res;
        }
        should_block = res == dd_should_block;
    }

    if (should_block) {
        mlog(dd_log_info, "request_init succeed and told to block");
        return dd_should_block;
    }

    mlog(dd_log_debug, "request_init succeed. Not blocking");
    return dd_success;
}

dd_result ATTR_WARN_UNUSED dd_command_exec(dd_conn *nonnull conn,
    const dd_command_spec *nonnull spec, void *unspecnull ctx)
{
    return _dd_command_exec(conn, false, spec, ctx);
}

dd_result ATTR_WARN_UNUSED dd_command_exec_cred(dd_conn *nonnull conn,
    const dd_command_spec *nonnull spec, void *unspecnull ctx)
{
    return _dd_command_exec(conn, true, spec, ctx);
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

static inline dd_result _omsg_send_cred(
    dd_conn *nonnull conn, dd_omsg *nonnull omsg)
{
    return dd_conn_sendv_cred(conn, &omsg->iovecs);
}

// incoming
static inline dd_result _dd_imsg_recv(
    dd_imsg *nonnull imsg, dd_conn *nonnull conn, bool check_cred)
{
    mlog(dd_log_debug, "Will receive response from helper");

    dd_result res;
    if (check_cred) {
        res = dd_conn_recv_cred(conn, &imsg->_data, &imsg->_size);
    } else {
        res = dd_conn_recv(conn, &imsg->_data, &imsg->_size);
    }
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
        UNUSED(_imsg_destroy(imsg));
        return dd_error;
    }

    return dd_success;
}

ATTR_WARN_UNUSED dd_result _imsg_recv(
    dd_imsg *nonnull imsg, dd_conn *nonnull conn)
{
    return _dd_imsg_recv(imsg, conn, false);
}
ATTR_WARN_UNUSED dd_result _imsg_recv_cred(
    dd_imsg *nonnull imsg, dd_conn *nonnull conn)
{
    return _dd_imsg_recv(imsg, conn, true);
}

static inline ATTR_WARN_UNUSED mpack_error_t _imsg_destroy(
    dd_imsg *nonnull imsg)
{
    free(imsg->_data);
    imsg->_data = NULL;
    imsg->_size = 0;
    return mpack_tree_destroy(&imsg->_tree);
}

/* Baked response */

static void _add_appsec_span_data_frag(mpack_node_t node);
static void _set_appsec_span_data(mpack_node_t node);

dd_result dd_command_proc_resp_verd_span_data(
    mpack_node_t root, ATTR_UNUSED void *unspecnull ctx)
{
    // expected: ['ok' / 'record' / 'block']
    mpack_node_t verdict = mpack_node_array_at(root, 0);
    if (mlog_should_log(dd_log_debug)) {
        const char *verd_str = mpack_node_str(verdict);
        size_t verd_len = mpack_node_strlen(verdict);
        if (verd_len > INT_MAX) {
            verd_len = INT_MAX;
        }
        mlog(dd_log_debug, "Verdict of request_init was '%.*s'", (int)verd_len,
            verd_str);
    }

    bool should_block = dd_mpack_node_lstr_eq(verdict, "block");
    if (should_block || dd_mpack_node_lstr_eq(verdict, "record")) {
        _set_appsec_span_data(mpack_node_array_at(root, 1));
    }

    if (mpack_node_array_length(root) >= 4) {
        mpack_node_t meta = mpack_node_array_at(root, 2);
        dd_command_process_meta(meta);

        mpack_node_t metrics = mpack_node_array_at(root, 3);
        dd_command_process_metrics(metrics);
    }

    return should_block ? dd_should_block : dd_success;
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

bool dd_command_process_meta(mpack_node_t root)
{
    size_t count = mpack_node_map_count(root);
    for (size_t i = 0; i < count; i++) {
        mpack_node_t key = mpack_node_map_key_at(root, i);
        mpack_node_t value = mpack_node_map_value_at(root, i);

        if (mpack_node_type(key) != mpack_type_str) {
            mlog(dd_log_warning, "Failed to add tags: invalid type for key");
            return false;
        }
        if (mpack_node_type(value) != mpack_type_str) {
            mlog(dd_log_warning, "Failed to add tags: invalid type for value");
            return false;
        }

        const char *key_str = mpack_node_str(key);
        size_t key_len = mpack_node_strlen(key);
        if (key_len > INT_MAX) {
            key_len = INT_MAX;
        }

        bool res = dd_trace_root_span_add_tag_str(
            key_str, key_len, mpack_node_str(value), mpack_node_strlen(value));

        if (!res) {
            mlog(dd_log_warning, "Failed to add tag %.*s", (int)key_len,
                key_str);
            return false;
        }
    }

    return true;
}

bool dd_command_process_metrics(mpack_node_t root)
{
    zval *metrics_zv = dd_trace_root_span_get_metrics();
    if (metrics_zv == NULL) {
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
