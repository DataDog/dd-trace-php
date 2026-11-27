// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// Minimal PHP extension running dd_curl_linkage_detect() on the real ext/curl,
// for manual checks against PHP builds we cannot load ddtrace.so into
// (see manual_static_curl_so.sh). Provides curl_linkage_detect(): string.

#include "../../src/extension/downstream/curl_linkage_detect.h"

#include <php.h>

PHP_FUNCTION(curl_linkage_detect)
{
    ZEND_PARSE_PARAMETERS_NONE();
    zend_module_entry *me =
        zend_hash_str_find_ptr(&module_registry, ZEND_STRL("curl"));
    if (!me) {
        RETURN_STRING("ext/curl not loaded");
    }
    struct dd_curl_linkage res;
    dd_curl_linkage_detect((const void *)me->module_startup_func, &res);
    RETURN_STR(
        strpprintf(0, "enabled=%d reason=\"%s\" path=\"%s\" version=\"%s\"",
            res.enabled, res.reason, res.libcurl_path, res.libcurl_version));
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_none, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    PHP_FE(curl_linkage_detect, arginfo_none) PHP_FE_END};

zend_module_entry curl_linkage_harness_module_entry = {STANDARD_MODULE_HEADER,
    "curl_linkage_harness", functions, NULL, NULL, NULL, NULL, NULL, "0.1",
    STANDARD_MODULE_PROPERTIES};

ZEND_GET_MODULE(curl_linkage_harness)
