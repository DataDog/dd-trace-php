// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "curl_linkage.h"

#include <php.h>

#include "../configuration.h"
#include "../logging.h"
#include "../php_objects.h"

static struct dd_curl_linkage _linkage = {
    .reason = "detection did not run",
};

#ifdef TESTING
static void _register_testing_objects(void);
#endif

void dd_curl_linkage_minit(void)
{
#ifdef TESTING
    _register_testing_objects();
#endif
}

void dd_curl_linkage_startup(void)
{
    zend_module_entry *curl_me =
        zend_hash_str_find_ptr(&module_registry, ZEND_STRL("curl"));
    if (!curl_me || !curl_me->module_startup_func) {
        memset(&_linkage, 0, sizeof(_linkage));
        (void)snprintf(
            _linkage.reason, sizeof(_linkage.reason), "ext/curl not loaded");
    } else {
        // Not the zend_function handlers: ddtrace replaces some of them, and
        // they'd point into ddtrace.so. The module entry in module_registry is
        // a copy, but its function pointers point into ext/curl.
        dd_curl_linkage_detect(
            (const void *)curl_me->module_startup_func, &_linkage);
    }

    if (!_linkage.enabled) {
        mlog(dd_log_debug,
            "Downstream request analysis for ext/curl is unavailable: %s",
            _linkage.reason);
        return;
    }

    mlog(dd_log_debug,
        "Downstream request analysis for ext/curl is available: %s "
        "(libcurl %s at %s)",
        _linkage.reason, _linkage.libcurl_version, _linkage.libcurl_path);
}

const struct dd_curl_linkage *nonnull dd_curl_linkage_get(void)
{
    return &_linkage;
}

const struct dd_curl_functions *nullable dd_curl_linkage_funcs(void)
{
    return dd_curl_linkage_enabled() ? &_linkage.funcs : NULL;
}

#ifdef TESTING
static PHP_FUNCTION(datadog_appsec_testing_curl_linkage_info)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    array_init(return_value);
    add_assoc_bool(return_value, "enabled", _linkage.enabled);
    add_assoc_string(return_value, "reason", _linkage.reason);
    add_assoc_string(return_value, "libcurl_version", _linkage.libcurl_version);
    add_assoc_long(return_value, "libcurl_version_num",
        (zend_long)_linkage.libcurl_version_num);
    add_assoc_string(return_value, "libcurl_path", _linkage.libcurl_path);
}

// Overrides libcurl's version number (0xXXYYZZ) as the options recording
// sees it, to reach the paths of older libcurls; returns the previous one.
// Used by curl_eval_method_unsure.phpt for the method left unsure by
// CURLOPT_UPLOAD before libcurl 8.1.0 (G6.3), on any libcurl.
static PHP_FUNCTION(datadog_appsec_testing_curl_linkage_fake_version)
{
    zend_long version;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &version) == FAILURE) {
        RETURN_FALSE;
    }
    RETVAL_LONG((zend_long)_linkage.libcurl_version_num);
    _linkage.libcurl_version_num = (unsigned int)version;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    void_ret_array_arginfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(fake_version_arginfo, 0, 1, IS_LONG, 0)
ZEND_ARG_TYPE_INFO(0, version, IS_LONG, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_linkage_info", PHP_FN(datadog_appsec_testing_curl_linkage_info), void_ret_array_arginfo, 0, NULL, NULL)
    ZEND_RAW_FENTRY(DD_TESTING_NS "curl_linkage_fake_version", PHP_FN(datadog_appsec_testing_curl_linkage_fake_version), fake_version_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

static void _register_testing_objects(void)
{
    if (!get_global_DD_APPSEC_TESTING()) {
        return;
    }
    dd_phpobj_reg_funcs(testing_functions);
}
#endif
