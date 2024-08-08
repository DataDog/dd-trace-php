// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef DD_MSGPACK_HELPERS_H
#define DD_MSGPACK_HELPERS_H

#include "attributes.h"
#include "string_helpers.h"
#include <mpack.h>
#include <php.h>

// safe against null returning from mpack_node_str because length is checked 1st
#define dd_mpack_node_lstr_eq(node, lstr)                                      \
    (mpack_node_strlen(node) == LSTRLEN(lstr) &&                               \
        memcmp(mpack_node_str(node), "" lstr, LSTRLEN(lstr)) == 0)

#define dd_mpack_node_str_eq(node, str, len)                                   \
    (mpack_node_strlen(node) == len &&                                         \
        memcmp(mpack_node_str(node), str, len) == 0)

#define dd_mpack_write_lstr(w, str) mpack_write_str(w, str, LSTRLEN(str))

void dd_mpack_write_nullable_cstr(
    mpack_writer_t *nonnull w, const char *nullable cstr);
void dd_mpack_write_nullable_str(
    mpack_writer_t *nonnull w, const char *nullable str, size_t len);

void dd_mpack_write_zstr(
    mpack_writer_t *nonnull w, const zend_string *nonnull zstr);
void dd_mpack_write_nullable_zstr(
    mpack_writer_t *nonnull w, const zend_string *nullable zstr);

void dd_mpack_write_array(
    mpack_writer_t *nonnull w, const zend_array *nullable arr);

void dd_mpack_write_zval(mpack_writer_t *nonnull w, zval *nullable zv);

void dd_mpack_writer_init_iov(
    mpack_writer_t *nonnull writer, zend_llist *nonnull iovec_list);

void dd_msgpack_helpers_startup();

#endif // DD_MSGPACK_HELPERS_H
