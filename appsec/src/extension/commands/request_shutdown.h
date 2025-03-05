// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include "../network.h"
#include "../attributes.h"
#include "../commands_ctx.h"
#include <Zend/zend_llist.h>

struct req_shutdown_info {
    struct req_info req_info;
    int status_code;
    enum {
        RESP_HEADERS_LLIST,
        RESP_HEADERS_MAP_STRING_LIST,
    } resp_headers_fmt;
    union {
        zend_llist *nonnull resp_headers_llist;
        const zend_array *nonnull resp_headers_arr;
    };
    zend_string *nullable entity;
    uint64_t api_sec_samp_key;
};

dd_result dd_request_shutdown(
    dd_conn *nonnull conn, struct req_shutdown_info *nonnull req_info);
