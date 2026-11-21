// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

// AppSec's part of the datadog module globals (see ext/datadog.h).

#include <stdbool.h>
#include <stddef.h>

struct _zend_string;

typedef enum _enabled_configuration {
    APPSEC_UNSET_STATE = 0,
    APPSEC_ENABLED_VIA_REMCFG,
    APPSEC_FULLY_ENABLED,
    APPSEC_FULLY_DISABLED
} enabled_configuration;

typedef struct _ddappsec_globals {
    // the logic value of the appsec.enabled configuration directive, fixed with
    // forced disabling in some cirumstances (e.g. tracing disabled)
    enabled_configuration enabled : 2;

    // if we're fully enabled or we've been enabled via remote config
    bool active : 1;

    // if we're supposed to be enabled via remote config, but no ASM_FEATURES
    // config set it yet. For MINFO purposes
    bool to_be_configured : 1;

    bool skip_rshutdown : 1;
    // used to avoid a bailout during request shutdown
    bool during_request_shutdown : 1;

    // Persistent, so that GSHUTDOWN can release them from any thread.
    struct _zend_string *resp_body_buf;
    size_t resp_body_buf_size;
    struct _zend_string *cached_waf_version;
    struct _zend_string *cached_event_rules_version;
} ddappsec_globals;
