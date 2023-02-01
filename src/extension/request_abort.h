// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

typedef enum {
    response_type_auto = 0,
    response_type_html = 1,
    response_type_json = 2,
} dd_response_type;

// NOLINTNEXTLINE(modernize-macro-to-enum)
#define DEFAULT_RESPONSE_CODE 403
#define DEFAULT_RESPONSE_TYPE response_type_auto

void dd_set_response_code_and_type(int code, dd_response_type type);

void dd_request_abort_startup(void);
void dd_request_abort_rinit_once(void);
// noreturn unless called from rinit on fpm
void dd_request_abort_static_page(void);
