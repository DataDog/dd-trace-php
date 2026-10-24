// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.
#include "body_parser.h"
#include "ddappsec.h"
#include "logging.h"
#include "tracer_bridge.h"
#include <alloc.h>
#include <ext/compatibility.h>
#include <stdint.h>
#include <string.h>

static zval _tape_to_zval(ddog_AppsecBodyTape tape);
static void _log_result(const char *nonnull kind, ddog_AppsecBodyStatus status,
    zval *nonnull result);

zval dd_parse_json_truncated(
    const char *nonnull json_data, size_t json_len, int max_depth)
{
    zval result;
    ZVAL_UNDEF(&result);
    if (json_len == 0) {
        return result;
    }

    ddog_AppsecBodyTape tape =
        dd_trace_parse_json_body(json_data, json_len, max_depth);
    ddog_AppsecBodyStatus status = tape.status;
    result = _tape_to_zval(tape);
    _log_result("JSON", status, &result);
    return result;
}

zval dd_parse_xml_truncated(
    const char *nonnull xml_data, size_t xml_len, int max_depth)
{
    zval result;
    ZVAL_NULL(&result);
    if (xml_len == 0) {
        return result;
    }

    ddog_AppsecBodyTape tape =
        dd_trace_parse_xml_body(xml_data, xml_len, max_depth);
    ddog_AppsecBodyStatus status = tape.status;
    result = _tape_to_zval(tape);
    _log_result("XML", status, &result);
    if (Z_TYPE(result) == IS_UNDEF) {
        ZVAL_NULL(&result);
    }
    return result;
}

static void _log_result(const char *nonnull kind, ddog_AppsecBodyStatus status,
    zval *nonnull result)
{
    if (status != DDOG_APPSEC_BODY_STATUS_EMPTY &&
        Z_TYPE_P(result) == IS_UNDEF) {
        mlog_g(dd_log_debug, "Could not allocate %s parsing result", kind);
        return;
    }

    // Log status only: an indented dump of a small nested body can allocate
    // hundreds of megabytes through zend_print_zval_r_to_str.
    switch (status) {
    case DDOG_APPSEC_BODY_STATUS_EMPTY:
        mlog_g(dd_log_debug, "Error parsing %s (no data)", kind);
        return;
    case DDOG_APPSEC_BODY_STATUS_PARTIAL:
        mlog_g(dd_log_debug, "Error parsing %s (with partial data)", kind);
        break;
    case DDOG_APPSEC_BODY_STATUS_COMPLETE:
        mlog_g(dd_log_debug, "Successfully parsed %s", kind);
        break;
    }
}

static size_t _read_size(const char *nonnull *nonnull pos)
{
    size_t value;
    memcpy(&value, *pos, sizeof(value));
    *pos += sizeof(value);
    return value;
}

static zval _build(const char *nonnull data, size_t len);

static zval _tape_to_zval(ddog_AppsecBodyTape tape)
{
    volatile zval result;
    ZVAL_UNDEF(&result);
    if (tape.status == DDOG_APPSEC_BODY_STATUS_EMPTY) {
        dd_trace_drop_body_tape(tape);
        return result;
    }

    // Rust has returned, so PHP may bail out here. Release the Rust buffer
    // before propagating a bailout, including one from the first allocation.
    zend_try { result = _build((const char *)tape.ptr, tape.len); }
    zend_catch
    {
        dd_trace_drop_body_tape(tape);
        zend_bailout();
    }
    zend_end_try();
    dd_trace_drop_body_tape(tape);
    return result;
}

static zval _build(const char *nonnull data, size_t len)
{
    zval result;
    ZVAL_UNDEF(&result);

    // Open containers. An entry is NULL if the container couldn't be inserted
    // into its parent, so that its content is dropped too.
    enum { INITIAL_STACK_CAPACITY = 16 };
    size_t capacity = INITIAL_STACK_CAPACITY;
    zval **stack = (zval **)zai_safe_femalloc(capacity, sizeof(*stack), 0);
    if (!stack) {
        return result;
    }
    size_t depth = 0;
    const char *key = NULL;
    size_t key_len = 0;

    const char *pos = data;
    const char *end = data + len;
    while (pos < end) {
        uint8_t record = (uint8_t)*pos++;
        zval value;
        switch (record) {
        case DDOG_APPSEC_BODY_RECORD_END:
            depth--;
            continue;
        case DDOG_APPSEC_BODY_RECORD_KEY:
            key_len = _read_size(&pos);
            key = pos;
            pos += key_len;
            continue;
        case DDOG_APPSEC_BODY_RECORD_MAP_START:
        case DDOG_APPSEC_BODY_RECORD_LIST_START:
            if (!zai_array_finit(&value)) {
                goto allocation_failed;
            }
            break;
        case DDOG_APPSEC_BODY_RECORD_STR: {
            size_t str_len = _read_size(&pos);
            zend_string *str = zai_string_finit(pos, str_len);
            if (!str) {
                goto allocation_failed;
            }
            ZVAL_STR(&value, str);
            pos += str_len;
            break;
        }
        case DDOG_APPSEC_BODY_RECORD_INT: {
            int64_t num;
            memcpy(&num, pos, sizeof(num));
            pos += sizeof(num);
            ZVAL_LONG(&value, (zend_long)num);
            break;
        }
        case DDOG_APPSEC_BODY_RECORD_FLOAT: {
            double num;
            memcpy(&num, pos, sizeof(num));
            pos += sizeof(num);
            ZVAL_DOUBLE(&value, num);
            break;
        }
        case DDOG_APPSEC_BODY_RECORD_TRUE:
            ZVAL_TRUE(&value);
            break;
        case DDOG_APPSEC_BODY_RECORD_FALSE:
            ZVAL_FALSE(&value);
            break;
        case DDOG_APPSEC_BODY_RECORD_NULL:
            ZVAL_NULL(&value);
            break;
        default:
            mlog(dd_log_warning, "Unknown body tape record %d", (int)record);
            pos = end;
            continue;
        }

        zval *slot;
        if (depth == 0) {
            result = value;
            slot = &result;
        } else if (stack[depth - 1] == NULL) {
            zval_ptr_dtor(&value);
            slot = NULL;
        } else {
            HashTable *container = Z_ARRVAL_P(stack[depth - 1]);
            if (key) {
                slot =
                    zai_symtable_str_fupdate(container, key, key_len, &value);
            } else {
                slot = zai_hash_next_index_finsert(container, &value);
            }
            if (slot == ZAI_HASH_NOMEM) {
                zval_ptr_dtor(&value);
                goto allocation_failed;
            }
            if (slot == NULL) {
                zval_ptr_dtor(&value);
            }
        }
        key = NULL;

        if (record == DDOG_APPSEC_BODY_RECORD_MAP_START ||
            record == DDOG_APPSEC_BODY_RECORD_LIST_START) {
            if (depth == capacity) {
                capacity *= 2;
                zval **new_stack = (zval **)zai_safe_ferealloc(
                    (void *)stack, capacity, sizeof(*stack), 0);
                if (!new_stack) {
                    goto allocation_failed;
                }
                stack = new_stack;
            }
            stack[depth++] = slot;
        }
    }

    efree(stack);
    return result;

allocation_failed:
    // A fallible allocation queues a soft OOM. Stop allocating and unwind so
    // PHP can report it at a safe boundary, after the Rust tape is released.
    zval_ptr_dtor(&result);
    ZVAL_UNDEF(&result);
    efree(stack);
    return result;
}
