// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "ddtrace.h"

typedef enum {
    response_type_auto = 0,
    response_type_html = 1,
    response_type_json = 2,
} dd_response_type;

struct block_params {
    zend_string *nullable security_response_id;
    dd_response_type response_type;
    int response_code;
    zend_string *nullable redirection_location;
};

// NOLINTNEXTLINE(modernize-macro-to-enum)
#define DEFAULT_BLOCKING_RESPONSE_CODE 403
#define DEFAULT_REDIRECTION_RESPONSE_CODE 303
#define DEFAULT_RESPONSE_TYPE response_type_auto

void dd_request_abort_startup(void);
void dd_request_abort_zend_ext_startup(void);
void dd_request_abort_shutdown(void);

// You generally don't want to call these functions directly.
// See dd_req_lifecycle_abort instead.
// noreturn unless called from rinit on fpm
void dd_request_abort_static_page(struct block_params *nonnull block_params);
zend_array *nonnull dd_request_abort_static_page_spec(
    struct block_params *nonnull block_params,
    const zend_array *nonnull server);
void dd_request_abort_redirect(struct block_params *nonnull block_params);
zend_array *nonnull dd_request_abort_redirect_spec(
    struct block_params *nonnull block_params,
    const zend_array *nonnull server);

static inline void dd_request_abort_destroy_block_params(
    struct block_params *nonnull block_params)
{
    if (block_params->security_response_id) {
        zend_string_release(block_params->security_response_id);
        block_params->security_response_id = NULL;
    }
    if (block_params->redirection_location) {
        zend_string_release(block_params->redirection_location);
        block_params->redirection_location = NULL;
    }
}
