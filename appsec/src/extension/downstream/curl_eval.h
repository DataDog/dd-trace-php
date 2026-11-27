// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// A hook entered inside libcurl (an app callback of another
// transfer) does nothing at all (G4.3): no evaluation, nothing to defer.

#include <php.h>
#include <stdbool.h>

// RINIT
void dd_curl_eval_rinit(void);
void dd_curl_eval_rshutdown(void);

// Around the calls of the hooks into libcurl that run transfers (curl_exec,
// curl_multi_exec, curl_multi_remove_handle, curl_multi_close), through which
// ext/curl may run the app's code (e.g. a WRITEFUNCTION calling curl_exec()
// for another handle). While one is in progress, the curl hooks that observe
// do nothing (dd_curl_eval_in_libcurl).
void dd_curl_eval_libcurl_enter(void);
void dd_curl_eval_libcurl_leave(void);
bool dd_curl_eval_in_libcurl(void);
