// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef DDAPPSEC_H
#define DDAPPSEC_H

// This header MUST be included in files that use EG/PG/OG/...
// See https://bugs.php.net/bug.php?id=81634

#include "attributes.h"
#include "logging.h"
#include "version.h"
#include <php.h>
#include <stdbool.h>

typedef enum _enabled_configuration {
    NOT_CONFIGURED = 0,
    ENABLED,
    DISABLED
} enabled_configuration;

// define zend_ddappsec_globals type
// clang-format off
ZEND_BEGIN_MODULE_GLOBALS(ddappsec)
    //Defines if extension has been enabled/disabled by ini/env
    enabled_configuration enabled_by_configuration;
    //Defines enablement status computed taking into account enabled_by_configuration and remote config
    enabled_configuration enabled;
    bool skip_rshutdown;
    bool during_request_shutdown;
ZEND_END_MODULE_GLOBALS(ddappsec)
// clang-format on

// declare ts_rsrc_id ddappsec_globals_id (ZTS) or
// zend_appsec_globals ddappsec_globals (non-ZTS)
ZEND_EXTERN_MODULE_GLOBALS(ddappsec)

#ifdef ZTS
extern __thread void *unspecnull ATTR_TLS_LOCAL_DYNAMIC TSRMLS_CACHE;
#    define DDAPPSEC_G(v)                                                      \
        TSRMG_STATIC(ddappsec_globals_id, zend_ddappsec_globals *, v)
#else
#    define DDAPPSEC_G(v) (ddappsec_globals.v)
#endif

int dd_appsec_rshutdown(bool ignore_verdict);

// Add a NO_CACHE version.
// Use tsrm_get_ls_cache() instead of thread-local _tsrmls_ls_cache
#ifdef ZTS
#    define DDAPPSEC_NOCACHE_G(v)                                              \
        TSRMG(ddappsec_globals_id, zend_ddappsec_globals *, v)
#else
#    define DDAPPSEC_NOCACHE_G DDAPPSEC_G
#endif

#define PHP_DDAPPSEC_EXTNAME "ddappsec"

#endif // DDAPPSEC_H
