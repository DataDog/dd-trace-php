// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// PHP-agnostic core of the libcurl linkage detection. It neither includes
// Zend headers nor libcurl headers, so it can be compiled into a standalone
// test program (see appsec/tests/extension/curl_linkage/).
//
// ddtrace.so must not depend on libcurl (no DT_NEEDED, no headers at build
// time), so the few libcurl types used are declared here. Their definitions
// are part of libcurl's stable public ABI (include/curl/curl.h):
//  - CURL is "typedef void CURL" for applications;
//  - CURLcode, CURLoption, CURLINFO and CURLversion are C enums (int-sized);
//  - curl_version_info_data starts with the fields age, version and
//    version_num in every libcurl release since 7.10.

#include <stdbool.h>
#include <stddef.h>

typedef void dd_curl_easy;   // CURL
typedef int dd_curl_code;    // CURLcode
typedef int dd_curl_option;  // CURLoption
typedef int dd_curl_info;    // CURLINFO
typedef int dd_curl_version; // CURLversion

// CURLVERSION_FIRST (curl.h: "CURLVERSION_FIRST, ... 7.10"). The argument of
// curl_version_info() is the struct age the caller understands; we only read
// the fields present since the first age.
#define DD_CURLVERSION_FIRST 0

// Prefix of curl_version_info_data (curl.h); never allocate or copy it.
typedef struct {
    dd_curl_version age;      // age of the returned struct
    const char *version;      // LIBCURL_VERSION
    unsigned int version_num; // LIBCURL_VERSION_NUM
    const char *host;         // OS/host/model libcurl was built for
    int features;             // bitmask of CURL_VERSION_*
} dd_curl_version_info_data;

// curl.h CURL_VERSION_CURLDEBUG (7.19.6): built with --enable-curldebug, which
// allocates every handle behind a struct memdebug header
#define DD_CURL_VERSION_CURLDEBUG (1 << 13)

// The first libcurl with CURLOPT_PREREQFUNCTION (7.80.0). Below it the
// callback is not installed, no response data is analysed and only the read
// slots are calibrated (G3.4, G5.1, G7.5)
enum { DD_CURL_PREREQ_MIN_VERSION = 0x075000 };

// libcurl functions resolved from the libcurl instance ext/curl is bound to.
// To resolve another function, add a line here: X(name, return type, params).
// The name is looked up with dlsym and the pointer stored in the field of the
// same name of struct dd_curl_functions.
// NOLINTBEGIN(bugprone-macro-parentheses)
#define DD_CURL_FUNCTIONS(X)                                                   \
    X(curl_easy_setopt, dd_curl_code, (dd_curl_easy *, dd_curl_option, ...))   \
    X(curl_easy_getinfo, dd_curl_code, (dd_curl_easy *, dd_curl_info, ...))    \
    X(curl_version_info, dd_curl_version_info_data *, (dd_curl_version))       \
    X(curl_easy_init, dd_curl_easy *, (void))                                  \
    X(curl_easy_cleanup, void, (dd_curl_easy *))
// NOLINTEND(bugprone-macro-parentheses)

struct dd_curl_functions {
#define DD_CURL_FN_FIELD(name, ret, params) ret(*name) params;
    DD_CURL_FUNCTIONS(DD_CURL_FN_FIELD)
#undef DD_CURL_FN_FIELD
};

#define DD_CURL_LINKAGE_REASON_SIZE 256
#define DD_CURL_LINKAGE_PATH_SIZE 512
#define DD_CURL_LINKAGE_VERSION_SIZE 64

struct dd_curl_linkage {
    bool enabled;
    // why the feature is off, or a confirmation of the binding when on
    char reason[DD_CURL_LINKAGE_REASON_SIZE];
    // path of the libcurl object (dl_iterate_phdr's dlpi_name); empty if off
    char libcurl_path[DD_CURL_LINKAGE_PATH_SIZE];
    // curl_version_info()->version; empty if off
    char libcurl_version[DD_CURL_LINKAGE_VERSION_SIZE];
    // curl_version_info()->version_num (0xXXYYZZ); 0 if off
    unsigned int libcurl_version_num;
    // curl_version_info()->features (CURL_VERSION_*); 0 if off
    int libcurl_features;
    // all non-NULL if on, all NULL if off
    struct dd_curl_functions funcs;
    // malloc_usable_size of the allocator libcurl's handles come from; NULL
    // if off or if the allocator has none (then calibration refuses, G3.3)
    size_t (*malloc_usable_size)(void *);
};

// Determines whether ext/curl, the object containing `anchor` (the address of
// a function in ext/curl's own code, not a handler ddtrace may have replaced),
// calls a dynamically linked libcurl, and if so resolves DD_CURL_FUNCTIONS
// from that very libcurl instance. Must run after ext/curl's MINIT, which
// calls curl_global_init. On Linux x86_64/aarch64 only; elsewhere the verdict
// is always off.
void dd_curl_linkage_detect(const void *anchor, struct dd_curl_linkage *out);
