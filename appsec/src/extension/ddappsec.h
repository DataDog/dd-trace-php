// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef DDAPPSEC_H
#define DDAPPSEC_H

// This header MUST be included in files that use EG/PG/OG/...
// See https://bugs.php.net/bug.php?id=81634

#include <php.h>
#include <stdbool.h>

#include <components-rs/common.h>
#include <ext/datadog.h>

#include "ddappsec_globals.h"

ZEND_EXTERN_MODULE_GLOBALS(datadog)
#define DDAPPSEC_G(v) (DATADOG_G(appsec).v)

void dd_appsec_rinit_once(void);
// Applies the ASM_FEATURES activation when AppSec is managed by remote config.
void dd_appsec_apply_remote_activation(ddog_AsmActivation activation);
int dd_appsec_rshutdown(bool ignore_verdict);

#endif // DDAPPSEC_H
