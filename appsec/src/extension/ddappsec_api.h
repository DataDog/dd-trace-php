// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.
#pragma once

// AppSec's lifecycle, driven by the datadog module (ext/datadog.c).

#include <stdbool.h>

#include "ddappsec_globals.h"

void dd_appsec_ginit(ddappsec_globals *globals);
void dd_appsec_gshutdown(ddappsec_globals *globals);
// Before the configuration is loaded, as config parsers may log.
void dd_appsec_minit_before_config(void);
// After the tracer's MINIT: the tracer's PHP functions must exist already.
void dd_appsec_minit(int module_number);
// Before the sidecar and the configuration are shut down.
void dd_appsec_mshutdown(void);
void dd_appsec_zend_startup(void);
// After the tracer's RINIT, as it needs the root span.
void dd_appsec_rinit(void);
// Before the tracer's RSHUTDOWN, which closes the root span.
void dd_appsec_rshutdown_hook(void);
void dd_appsec_minfo(void);

bool dd_appsec_maybe_enable_helper(bool *appsec_activation, bool *appsec_conf);
