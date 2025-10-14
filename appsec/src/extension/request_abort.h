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

// NOLINTNEXTLINE(modernize-macro-to-enum)
#define DEFAULT_BLOCKING_RESPONSE_CODE 403
#define DEFAULT_REDIRECTION_RESPONSE_CODE 303
#define DEFAULT_RESPONSE_TYPE response_type_auto

void dd_set_block_code_and_type(int code, dd_response_type type, zend_string *nullable block_id);
void dd_set_redirect_code_and_location(
    int code, zend_string *nullable location, zend_string *nullable block_id);

void dd_request_abort_startup(void);
void dd_request_abort_rinit(void);
void dd_request_abort_zend_ext_startup(void);
void dd_request_abort_shutdown(void);
// noreturn unless called from rinit on fpm
void dd_request_abort_static_page(void);
zend_array *nonnull dd_request_abort_static_page_spec(
    const zend_array *nonnull);
void dd_request_abort_redirect(void);
zend_array *nonnull dd_request_abort_redirect_spec(void);
