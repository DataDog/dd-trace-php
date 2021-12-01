// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#ifndef DD_NETWORK_H
#define DD_NETWORK_H

#include <stdbool.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <sys/un.h>
#include <unistd.h>
#include <zend.h>

#include "attributes.h"
#include "dddefs.h"

struct _dd_conn {
    struct sockaddr_un addr;
    int socket;
};
enum comm_type {
    comm_type_recv,
    comm_type_send,
};

typedef struct _dd_conn dd_conn;

dd_result dd_conn_sendv(dd_conn *nonnull conn, zend_llist *nonnull iovecs);
dd_result dd_conn_recv(dd_conn *nonnull conn, char *nullable *nonnull data, size_t *nonnull data_len);
dd_result dd_conn_recv_cred(dd_conn *nonnull conn, char *nullable *nonnull data, size_t *nonnull data_len);

// for helper_process
#ifdef HELPER_PROCESS_C_INCLUDES
ATTR_ALWAYS_INLINE bool dd_conn_connected(dd_conn *nonnull conn)
{
    return conn->socket > 0;
}

int dd_conn_init(
    dd_conn *nonnull conn, const char *nonnull path, size_t path_len);

int dd_conn_destroy(dd_conn *nonnull conn);
dd_result dd_conn_set_timeout(
    dd_conn *nonnull conn, enum comm_type comm_type, int milliseconds);

#endif


#endif // DD_NETWORK_H
