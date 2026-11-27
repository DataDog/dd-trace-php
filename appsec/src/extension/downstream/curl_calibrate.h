// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// PHP-agnostic calibration of libcurl's callback slots (G5.1): the offsets in
// libcurl's private Curl_easy of the write, header and read functions and
// their data. ext/curl keeps its curl_write, curl_write_header and curl_read
// static and libcurl has no getter for them, so they can only be read (and
// later replaced) at offsets found by calibration. No Zend or libcurl
// headers: it is also built into a standalone test program
// (appsec/tests/curl_calibrate/).
//
// Procedure: on a probe handle that never performs a transfer (after one, the
// read slots are also copied into libcurl's transfer state and appear twice),
// set distinct sentinel functions and data pointers with curl_easy_setopt,
// scan the block bounded by malloc_usable_size with pointer stride, and
// require each sentinel exactly once; repeat with a second set of sentinels,
// which must be found at the same offsets. Anything else refuses. Below
// libcurl 7.80 only the read slots are used (G3.4): only they must be found,
// the other offsets stay 0. Refused up front: a libcurl built with CURLDEBUG
// (its handles are not allocator blocks) and a usable size that cannot be a
// handle's (the malloc_usable_size found is not libcurl's allocator's).

#include <stdbool.h>
#include <stddef.h>

#include "curl_linkage_detect.h"

enum dd_curl_slot {
    DD_CURL_SLOT_WRITE_FUNCTION,  // CURLOPT_WRITEFUNCTION
    DD_CURL_SLOT_WRITE_DATA,      // CURLOPT_WRITEDATA (CURLOPT_FILE)
    DD_CURL_SLOT_HEADER_FUNCTION, // CURLOPT_HEADERFUNCTION
    DD_CURL_SLOT_HEADER_DATA,     // CURLOPT_HEADERDATA (CURLOPT_WRITEHEADER)
    DD_CURL_SLOT_READ_FUNCTION,   // CURLOPT_READFUNCTION
    DD_CURL_SLOT_READ_DATA,       // CURLOPT_READDATA (CURLOPT_INFILE)
    DD_CURL_SLOT_COUNT
};

// What the calibration calls: functions of the libcurl ext/curl is bound to,
// and the malloc_usable_size of the allocator libcurl allocates its handles
// with (NULL if there is none: calibration refuses, G3.3); and what that
// libcurl's curl_version_info() reports.
struct dd_curl_calibrate_api {
    dd_curl_easy *(*easy_init)(void);
    void (*easy_cleanup)(dd_curl_easy *);
    dd_curl_code (*easy_setopt)(dd_curl_easy *, dd_curl_option, ...);
    size_t (*usable_size)(void *);
    unsigned int version_num; // LIBCURL_VERSION_NUM
    int features;             // CURL_VERSION_* bits
};

#define DD_CURL_CALIBRATION_REASON_SIZE 256

struct dd_curl_calibration {
    bool accepted;
    // why it refused, or a confirmation when accepted
    char reason[DD_CURL_CALIBRATION_REASON_SIZE];
    // byte offsets from the CURL * (Curl_easy); all 0 if refused
    size_t offsets[DD_CURL_SLOT_COUNT];
};

// Runs the calibration once. Must run after curl_global_init, while no other
// thread uses libcurl.
void dd_curl_calibrate(
    const struct dd_curl_calibrate_api *api, struct dd_curl_calibration *out);

// "write_function", "write_data", ... ("" for an invalid slot)
const char *dd_curl_slot_name(enum dd_curl_slot slot);
