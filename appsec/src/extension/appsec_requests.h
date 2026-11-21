// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// The requests to the AppSec helper, which the sidecar hands to the helper
// client of this thread's connection, and the processing of their verdicts.

#include "attributes.h"
#include "commands_ctx.h"
#include "dddefs.h"
#include "msgpack_helpers.h"
#include <stdbool.h>
#include <stdint.h>

void dd_appsec_requests_startup(void);

// Whether the helper started an AppSec request for the current request:
// request_exec and request_shutdown are only sent then.
bool dd_appsec_request_started(void);

// The helper's request is lost, e.g. in a fork child, whose connection has no
// request on its helper client, or after a bailout.
void dd_appsec_request_forget(void);

// Testing: consider an AppSec request started.
void dd_appsec_requests_mark_started(void);

// The addresses of a request: a MessagePack map, written into a growable
// buffer. Fixed in place once initialized, as the writer points into it.
typedef struct dd_appsec_addresses {
    mpack_writer_t writer;
    char *nullable data;
    size_t size;
} dd_appsec_addresses;

void dd_appsec_addresses_init(dd_appsec_addresses *nonnull addresses);

// These finish and release the addresses. The verdict is applied to ctx (block
// parameters) and the root span; the result tells the action to take.
dd_result dd_appsec_send_request_init(dd_appsec_addresses *nonnull addresses,
    bool keep_bound_application, struct req_info *nonnull ctx);
dd_result dd_appsec_send_request_exec(dd_appsec_addresses *nonnull addresses,
    zend_string *nullable rasp_rule, zend_string *nullable rule_variant,
    struct req_info *nonnull ctx);
dd_result dd_appsec_send_request_shutdown(
    dd_appsec_addresses *nonnull addresses, uint64_t api_sec_samp_key,
    bool input_truncated, struct req_info *nonnull ctx);
