// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef DD_MSGPACK_HELPERS_H
#define DD_MSGPACK_HELPERS_H

#include "string_helpers.h"
#include "attributes.h"
#include <mpack.h>
#include <php.h>

typedef struct dd_mpack_limits {
    size_t max_string_length;
    size_t depth_remaining;
    size_t elements_remaining;
} dd_mpack_limits;

#define DD_MPACK_DEF_STRING_LIMIT 4096
#define dd_mpack_def_limits                                                    \
    ((dd_mpack_limits){                                                        \
        .max_string_length = DD_MPACK_DEF_STRING_LIMIT,                        \
        .depth_remaining = 21,                                                 \
        .elements_remaining = 2048,                                            \
    })

static inline bool dd_mpack_limits_reached(dd_mpack_limits *nonnull limits)
{
    return limits->depth_remaining == 0 || limits->elements_remaining == 0;
}

// safe against null returning from mpack_node_str because length is checked 1st
#define dd_mpack_node_lstr_eq(node, lstr)                                      \
    (mpack_node_strlen(node) == LSTRLEN(lstr) &&                               \
        memcmp(mpack_node_str(node), "" lstr, LSTRLEN(lstr)) == 0)

#define dd_mpack_node_str_eq(node, str, len)                                   \
    (mpack_node_strlen(node) == (len) &&                                         \
        memcmp(mpack_node_str(node), str, len) == 0)

#define dd_mpack_write_lstr(w, str) mpack_write_str(w, str, LSTRLEN(str))
#define dd_mpack_write_lstr_lim(w, str, plimits)                               \
    mpack_write_str(w, str, MIN(LSTRLEN(str), (plimits)->max_string_length))

void dd_mpack_write_nullable_cstr(
    mpack_writer_t *nonnull w, const char *nullable cstr);
void dd_mpack_write_nullable_cstr_lim(mpack_writer_t *nonnull w,
    const char *nullable cstr, size_t max_len);
void dd_mpack_write_nullable_str(
    mpack_writer_t *nonnull w, const char *nullable str, size_t len);
void dd_mpack_write_nullable_str_lim(mpack_writer_t *nonnull w,
    const char *nullable str, size_t len, size_t max_len);

void dd_mpack_write_zstr(
    mpack_writer_t *nonnull w, const zend_string *nonnull zstr);
void dd_mpack_write_zstr_lim(mpack_writer_t *nonnull w,
    const zend_string *nonnull zstr, size_t max_len);
void dd_mpack_write_nullable_zstr(
    mpack_writer_t *nonnull w, const zend_string *nullable zstr);
void dd_mpack_write_nullable_zstr_lim(mpack_writer_t *nonnull w,
    const zend_string *nullable zstr, size_t max_len);

void dd_mpack_write_array(
    mpack_writer_t *nonnull w, const zend_array *nullable arr);
void dd_mpack_write_array_lim(mpack_writer_t *nonnull w,
    const zend_array *nullable arr, dd_mpack_limits *nonnull limits);
void dd_mpack_write_zval(mpack_writer_t *nonnull w, zval *nullable zv);
void dd_mpack_write_zval_lim(mpack_writer_t *nonnull w, zval *nullable zv,
    dd_mpack_limits *nonnull limits);
void dd_mpack_writer_init_iov(
    mpack_writer_t *nonnull writer, zend_llist *nonnull iovec_list);

void dd_msgpack_helpers_startup(void);
void dd_msgpack_helpers_rinit(void);
bool dd_msgpack_helpers_is_data_truncated(void);
#endif // DD_MSGPACK_HELPERS_H
