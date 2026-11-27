// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <php.h>
#include <stdbool.h>

#include "../attributes.h"

// Replacements for ext/curl's functions that track options and drive the
// observation of transfers (curl_observe.h). Each calls the handler it
// replaced, which may be the tracer's own replacement.

// zend extension startup, after the tracer's startup and
// dd_curl_handle_startup(). Does nothing unless downstream-request analysis
// is available in this process.
void dd_curl_hooks_startup(void);
// whether the last dd_curl_hooks_startup() installed the hooks
bool dd_curl_hooks_installed(void);
