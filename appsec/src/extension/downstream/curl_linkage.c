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
#ifdef TESTING
#    include "curl_handle.h"
#    include "curl_hooks.h"
#endif

static struct dd_curl_linkage _linkage = {
    .reason = "detection did not run",
};
static struct dd_curl_calibration _calibration = {
    .reason = "calibration did not run",
};

static void _calibrate(void);

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
        memset(&_calibration, 0, sizeof(_calibration));
        (void)snprintf(_calibration.reason, sizeof(_calibration.reason),
            "linkage detection is off");
        mlog(dd_log_debug,
            "Downstream request analysis for ext/curl is unavailable: %s",
            _linkage.reason);
        return;
    }

    // right after the detection, before any hook is installed, so that a
    // refusal leaves the layer off (G5.1, G3.8)
    _calibrate();
    if (!_calibration.accepted) {
        mlog(dd_log_debug,
            "Downstream request analysis for ext/curl is unavailable: "
            "calibration of libcurl %s at %s refused: %s",
            _linkage.libcurl_version, _linkage.libcurl_path,
            _calibration.reason);
        return;
    }
    mlog(dd_log_debug,
        "Downstream request analysis for ext/curl is available: %s "
        "(libcurl %s at %s; %s)",
        _linkage.reason, _linkage.libcurl_version, _linkage.libcurl_path,
        _calibration.reason);
}

// Zend extension startup runs once, before any request thread exists, and
// ext/curl's MINIT has called curl_global_init.
static void _calibrate(void)
{
    struct dd_curl_calibrate_api api = {
        .easy_init = _linkage.funcs.curl_easy_init,
        .easy_cleanup = _linkage.funcs.curl_easy_cleanup,
        .easy_setopt = _linkage.funcs.curl_easy_setopt,
        .usable_size = _linkage.malloc_usable_size,
        .version_num = _linkage.libcurl_version_num,
        .features = _linkage.libcurl_features,
    };
#ifdef TESTING
    // forces a refusal (G3.8: no hook is installed then); read here, at
    // startup, since the calibration runs only once
    // NOLINTNEXTLINE(concurrency-mt-unsafe)
    const char *refuse = getenv("_DD_APPSEC_TESTING_CURL_CALIBRATION_REFUSE");
    if (get_global_DD_APPSEC_TESTING() && refuse && *refuse &&
        strcmp(refuse, "0") != 0) {
        memset(&_calibration, 0, sizeof(_calibration));
        (void)snprintf(_calibration.reason, sizeof(_calibration.reason),
            "refused for testing "
            "(_DD_APPSEC_TESTING_CURL_CALIBRATION_REFUSE)");
        return;
    }
#endif
    dd_curl_calibrate(&api, &_calibration);
}

const struct dd_curl_linkage *nonnull dd_curl_linkage_get(void)
{
    return &_linkage;
}

const struct dd_curl_calibration *nonnull dd_curl_calibration_get(void)
{
    return &_calibration;
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

    add_assoc_bool(return_value, "calibration_accepted", _calibration.accepted);
    add_assoc_string(return_value, "calibration_reason", _calibration.reason);
    zval offsets;
    array_init(&offsets);
    for (int i = 0; i < DD_CURL_SLOT_COUNT; i++) {
        add_assoc_long(&offsets, dd_curl_slot_name((enum dd_curl_slot)i),
            (zend_long)_calibration.offsets[i]);
    }
    add_assoc_zval(return_value, "calibration_offsets", &offsets);

    // the final verdict: linkage, calibration, curl handle type and hooks
    // (no hooks with RASP off)
    add_assoc_bool(return_value, "layer_enabled",
        dd_curl_downstream_enabled() && dd_curl_hooks_installed());
}

// Overrides libcurl's version number (0xXXYYZZ) as the options recording
// sees it, to reach the paths of older libcurls; returns the previous one.
// Used by curl_eval_method_unsure.phpt for the method left unsure by
// CURLOPT_UPLOAD before libcurl 8.1.0 (G6.3), on any libcurl. Never changes
// the calibration, which ran at startup with the real version.
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
