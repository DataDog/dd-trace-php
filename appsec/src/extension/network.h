// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef DD_NETWORK_H
#define DD_NETWORK_H

#include <stdbool.h>
#include <unistd.h>
#include <zend.h>

#include <components-rs/common.h>

#include "attributes.h"
#include "dddefs.h"

struct _dd_conn {
    bool connected;
    uint64_t client_id;
};
typedef struct _dd_conn dd_conn;

typedef struct _dd_helper_response {
    char *unspecnull data;
    size_t data_len;
    size_t _capacity; // private
} dd_helper_response;

dd_result dd_conn_roundtripv(dd_conn *nonnull conn, zend_llist *nonnull iovecs,
    dd_helper_response *nonnull response_out);
void dd_helper_response_destroy(dd_helper_response *nonnull response);

// for helper_process
#ifdef HELPER_PROCESS_C_INCLUDES
ATTR_ALWAYS_INLINE bool dd_conn_connected(dd_conn *nonnull conn)
{
    return conn->connected;
}

void dd_conn_init(dd_conn *nonnull conn);

void dd_conn_destroy(dd_conn *nonnull conn);

#endif

#endif // DD_NETWORK_H
