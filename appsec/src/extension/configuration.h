// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.
#pragma once

// Accessors for the configuration, including AppSec's settings from
// configuration_table.h.

#include <ext/configuration.h>
#include <tracer/configuration.h>

#define DD_CONFIGURATION DDAPPSEC_CONFIGURATION
#include <ext/configuration_helpers.h>

// Registers the configuration testing functions.
void dd_config_minit(void);
bool dd_cfg_enable_via_remcfg(void);
// Whether datadog.appsec.enabled / DD_APPSEC_ENABLED is explicitly set to
// false in the global configuration (env, php.ini or stable config).
bool dd_cfg_appsec_explicitly_disabled(void);
// Whether datadog.trace.enabled / DD_TRACE_ENABLED is explicitly set to false
// in the global configuration.
bool dd_cfg_trace_explicitly_disabled(void);
