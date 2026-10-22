// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// The startup parse windows: the treat_data wrapper, which arms the startup
// parse of POST, GET and COOKIE data, and the wrappers that give an armed
// parse its safe points (input_filter / input_filter_init,
// php_rfc1867_callback, sapi_module.read_post). The window itself is shared
// with request_parse_body() (p2s_request_parse_body.c).

#ifndef DD_P2S_STARTUP_PARSE_H
#define DD_P2S_STARTUP_PARSE_H

#include <php.h>
#include <stdbool.h>
#include <stddef.h>

#include "../attributes.h"
#include "p2s_oom_guard.h"

typedef void (*dd_parse_fn)(void *ctx);

// Capture the treat_data, input_filter / input_filter_init,
// php_rfc1867_callback and read_post slots and put our wrappers in them.
void dd_p2s_startup_parse_install(void);
// Restore the slots that still hold our wrappers, and forget the captured
// handlers.
void dd_p2s_startup_parse_uninstall(void);
// Log once per process if the slot was displaced.
void dd_p2s_startup_parse_check_treat_data(void);
void dd_p2s_startup_parse_check_input_filter(void);
void dd_p2s_startup_parse_check_rfc1867(void);
void dd_p2s_startup_parse_check_read_post(void);
// The MINFO hook rows (none for read_post on a SAPI without it).
void dd_p2s_startup_parse_minfo_treat_data(void);
void dd_p2s_startup_parse_minfo_input_filter(void);
void dd_p2s_startup_parse_minfo_rfc1867(void);
void dd_p2s_startup_parse_minfo_read_post(void);
// Run one request-data parse in a window, then raise the deferred
// out-of-memory error, if any.
void dd_p2s_startup_parse_run_window(dd_parse_fn parse, void *ctx,
    bool progress_off, size_t bound, bool multipart, dd_deferred_phase phase);
// The input bound of a POST body parse that applies `post_max_size`.
size_t dd_p2s_startup_parse_post_body_bound(zend_long post_max_size);
// Whether the request body is multipart/form-data.
bool dd_p2s_startup_parse_multipart_body(void);
#ifdef TESTING
void dd_p2s_startup_parse_register_testing(void);
#endif

#endif // DD_P2S_STARTUP_PARSE_H
