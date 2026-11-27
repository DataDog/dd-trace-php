// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// Whether ext/curl is bound to a dynamically linked libcurl that we can call
// into (native downstream-request analysis) and the libcurl functions
// resolved from that very instance. The detection runs once, at zend
// extension startup (after every module's MINIT); before that, and whenever
// it fails, the layer is off (G3.8).

#include "../attributes.h"
#include "curl_linkage_detect.h"

// MINIT: registers the testing functions
void dd_curl_linkage_minit(void);
// zend extension startup: runs the detection
void dd_curl_linkage_startup(void);

const struct dd_curl_linkage *nonnull dd_curl_linkage_get(void);
// the layer is available: the linkage detection is on
static inline bool dd_curl_linkage_enabled(void)
{
    return dd_curl_linkage_get()->enabled;
}
// NULL if off: the linkage detection is off (dd_curl_linkage_enabled())
const struct dd_curl_functions *nullable dd_curl_linkage_funcs(void);
// libcurl's LIBCURL_VERSION_NUM (0xXXYYZZ); 0 if off
static inline unsigned int dd_curl_linkage_version_num(void)
{
    return dd_curl_linkage_get()->libcurl_version_num;
}
