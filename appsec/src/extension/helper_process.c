// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// The AppSec settings of the sidecar connections. The sidecar starts a helper
// client for each connection with them, and drops it with the connection.

// NOLINTNEXTLINE(misc-header-include-cycle)
#include <components-rs/datadog.h>
#include <php.h>

#include "appsec_requests.h"
#include "configuration.h"
#include "ddappsec.h"
#include "ddappsec_api.h"
#include "helper_process.h"
#include "logging.h"
#include "php_objects.h"
#include <ext/compatibility.h>

static inline ddog_CharSlice to_char_slice(zend_string *zs)
{
    return (ddog_CharSlice){.len = ZSTR_LEN(zs), .ptr = ZSTR_VAL(zs)};
}

static bool _uses_helper(void)
{
    dd_appsec_rinit_once();
    return DDAPPSEC_G(enabled) != APPSEC_FULLY_DISABLED &&
           !get_global_DD_APPSEC_TESTING();
}

bool dd_appsec_maybe_enable_helper(
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    bool *nonnull appsec_activation, bool *nonnull appsec_conf)
{
    if (!_uses_helper()) {
        *appsec_activation = false;
        *appsec_conf = false;
        return false;
    }

    // The helper's log in a subprocess sidecar.
    ddog_sidecar_enable_appsec(
        to_char_slice(get_global_DD_APPSEC_HELPER_LOG_FILE()),
        to_char_slice(get_global_DD_APPSEC_HELPER_LOG_LEVEL()));

    *appsec_activation = DDAPPSEC_G(enabled) == APPSEC_ENABLED_VIA_REMCFG;
    // only enable ASM / ASM_DD / ASM_DATA if no rules file is specified
    *appsec_conf = get_global_DD_APPSEC_RULES()->len == 0;

    return true;
}

static ddog_AppSecSessionConfigFfi _session_config(void)
{
    double delay = get_global_DD_API_SECURITY_SAMPLE_DELAY();
    if (delay < 0) {
        mlog_g(dd_log_debug,
            "Negative value for DD_API_SECURITY_SAMPLE_DELAY; setting to 0");
        delay = 0;
    }
    zend_long waf_timeout = get_global_DD_APPSEC_WAF_TIMEOUT();
    zend_long trace_rate_limit = get_global_DD_APPSEC_TRACE_RATE_LIMIT();
    return (ddog_AppSecSessionConfigFfi){
        .rules_file = to_char_slice(get_global_DD_APPSEC_RULES()),
        .waf_timeout_us = waf_timeout > 0 ? (uint64_t)waf_timeout : 0,
        .trace_rate_limit = trace_rate_limit > 0
                                ? (uint32_t)MIN(trace_rate_limit, UINT32_MAX)
                                : 0,
        .obfuscator_key_regex = to_char_slice(
            get_global_DD_APPSEC_OBFUSCATION_PARAMETER_KEY_REGEXP()),
        .obfuscator_value_regex = to_char_slice(
            get_global_DD_APPSEC_OBFUSCATION_PARAMETER_VALUE_REGEXP()),
        .schema_extraction_enabled = get_global_DD_API_SECURITY_ENABLED(),
        .schema_extraction_sampling_period = delay,
        .helper_log_file =
            to_char_slice(get_global_DD_APPSEC_HELPER_LOG_FILE()),
        .helper_log_level =
            to_char_slice(get_global_DD_APPSEC_HELPER_LOG_LEVEL()),
    };
}

void dd_appsec_set_session_config(
    ddog_SidecarTransport *nullable *nonnull transport)
{
    if (!*transport) {
        return;
    }
    if (!_uses_helper()) {
        ddog_sidecar_set_appsec_session_config(transport, NULL);
        return;
    }

    ddog_AppSecSessionConfigFfi config = _session_config();
    ddog_sidecar_set_appsec_session_config(transport, &config);
}

#ifdef TESTING
static void _add_slice(
    zval *nonnull arr, const char *nonnull key, ddog_CharSlice value)
{
    add_assoc_stringl(arr, key, (char *)value.ptr, value.len);
}

// The AppSec settings the sidecar connections get, NULL if AppSec is fully
// disabled. Testing mode itself does not send them: requests go to the mock.
static PHP_FUNCTION(datadog_appsec_testing_appsec_session_config) // NOLINT
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    dd_appsec_rinit_once();
    if (DDAPPSEC_G(enabled) == APPSEC_FULLY_DISABLED) {
        RETURN_NULL();
    }

    ddog_AppSecSessionConfigFfi config = _session_config();
    array_init(return_value);
    _add_slice(return_value, "rules_file", config.rules_file);
    add_assoc_long(
        return_value, "waf_timeout_us", (zend_long)config.waf_timeout_us);
    add_assoc_long(
        return_value, "trace_rate_limit", (zend_long)config.trace_rate_limit);
    _add_slice(
        return_value, "obfuscator_key_regex", config.obfuscator_key_regex);
    _add_slice(
        return_value, "obfuscator_value_regex", config.obfuscator_value_regex);
    zval schema_extraction;
    array_init(&schema_extraction);
    add_assoc_bool(
        &schema_extraction, "enabled", config.schema_extraction_enabled);
    add_assoc_double(&schema_extraction, "sampling_period",
        config.schema_extraction_sampling_period);
    add_assoc_zval(return_value, "schema_extraction", &schema_extraction);
    _add_slice(return_value, "helper_log_file", config.helper_log_file);
    _add_slice(return_value, "helper_log_level", config.helper_log_level);
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(void_ret_nullable_array_arginfo, 0, 0, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

static const zend_function_entry _functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "appsec_session_config", PHP_FN(datadog_appsec_testing_appsec_session_config), void_ret_nullable_array_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on
#endif

void dd_helper_process_startup(void)
{
#ifdef TESTING
    if (get_global_DD_APPSEC_TESTING()) {
        dd_phpobj_reg_funcs(_functions);
    }
#endif
}

void dd_appsec_fork_child(void) { dd_appsec_request_forget(); }
