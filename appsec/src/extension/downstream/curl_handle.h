// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// Getting the libcurl easy handle (CURL *) out of a PHP curl handle (PHP 8:
// CurlHandle object; PHP 7: "curl" resource), without ext/curl's headers.
//
// There is no process-wide state: each transfer's handle is validated on its
// own (see curl_handle_validate.h). One that fails validation, or that cannot
// be checked right now (the probe is refused), is skipped for that transfer,
// with a debug line in the AppSec log (never through PHP: it would be a
// warning in the app, possibly from inside libcurl). The next handle is
// validated again.

#include <php.h>
#include <stdbool.h>

#include "../attributes.h"
#include "curl_linkage_detect.h"

typedef enum {
    // *out is the validated CURL *
    DD_CURL_HANDLE_OK = 0,
    // not a curl handle (or, on PHP 7, a closed one)
    DD_CURL_HANDLE_NOT_CURL,
    // a live curl handle without a CURL * (e.g. a denied server push): skip it
    DD_CURL_HANDLE_NULL_CP,
    // the feature is off (linkage, or ext/curl's handle type not found)
    DD_CURL_HANDLE_DISABLED,
    // validation failed: skip this transfer
    DD_CURL_HANDLE_INVALID,
    // the CURL * could not be checked now (the probe is refused): skip it
    DD_CURL_HANDLE_UNVERIFIED,
} dd_curl_handle_status;

// MINIT: registers the testing functions
void dd_curl_handle_minit(void);
// zend extension startup, after dd_curl_linkage_startup()
void dd_curl_handle_startup(void);

// Whether the native downstream-request analysis is available: libcurl
// linkage is usable and ext/curl's handle type was found at startup.
bool dd_curl_downstream_enabled(void);

// Whether `zv` is a live PHP curl handle (CurlHandle object, or "curl"
// resource that was not closed); its CURL * is not looked at. False if
// ext/curl's handle type was not found at startup.
bool dd_curl_handle_is_curl(zval *nonnull zv);

// Gets the CURL * of the PHP curl handle `zv`. *out is set to NULL unless
// DD_CURL_HANDLE_OK is returned.
dd_curl_handle_status dd_curl_handle_get(
    zval *nonnull zv, dd_curl_easy * nullable * nonnull out);
// The same, and also the handle's php_curl * (ext/curl's struct, whose
// address ext/curl gives libcurl as the data of its callback slots) when
// DD_CURL_HANDLE_OK; log false: a failed validation writes no debug line
// (the transfer is skipped for another reason already)
dd_curl_handle_status dd_curl_handle_get_ex(zval *nonnull zv,
    dd_curl_easy * nullable * nonnull out, void *nullable *nullable php_curl,
    bool log);
