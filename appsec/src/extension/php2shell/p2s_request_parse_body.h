// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// The request_parse_body() wrapper (PHP 8.4+), which runs the original in a
// window of its own (see dd_p2s_startup_parse_run_window). Below PHP 8.4 every
// function here does nothing.

#ifndef DD_P2S_REQUEST_PARSE_BODY_H
#define DD_P2S_REQUEST_PARSE_BODY_H

// Put our wrapper in the handler of request_parse_body().
void dd_p2s_request_parse_body_install(void);
// Restore the handler if it still holds our wrapper, and forget the captured
// one.
void dd_p2s_request_parse_body_uninstall(void);
// Log once per process if the handler was displaced.
void dd_p2s_request_parse_body_check(void);
// The request_parse_body() MINFO hook row.
void dd_p2s_request_parse_body_minfo(void);

#endif // DD_P2S_REQUEST_PARSE_BODY_H
