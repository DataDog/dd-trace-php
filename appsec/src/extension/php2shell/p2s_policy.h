// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// The PHP2Shell gate: the configured mode, whether the runtime is patched,
// whether appsec lets the mitigation run, and whether the hooks are installed.

#ifndef DD_P2S_POLICY_H
#define DD_P2S_POLICY_H

#include <stdbool.h>

#include "../attributes.h"

// Detect whether the runtime is patched and store the initial appsec gate.
void dd_p2s_policy_minit(void);
// Whether to install the hooks at all, from the configuration visible at the
// zend_extension startup hook.
bool dd_p2s_policy_startup_may_hook(void);
// Record that the hooks were installed (the mitigation can then be active).
void dd_p2s_policy_hooks_installed(void);
// Record that the hooks were removed.
void dd_p2s_policy_mshutdown(void);
// Set whether appsec's state lets the mitigation run.
void dd_p2s_policy_set_allowed(bool allowed);
// Whether the mitigation is active for request data now.
bool dd_p2s_policy_active(void);
// Whether the runtime carries the upstream fix. Fixed in MINIT.
bool dd_p2s_policy_patched(void);
// The configured mode, as reported by MINFO and telemetry.
const char *nonnull dd_p2s_policy_mode_name(void);
// The state, mode and patched MINFO rows.
void dd_p2s_policy_minfo(void);
#ifdef TESTING
void dd_p2s_policy_register_testing(void);
#endif

#endif // DD_P2S_POLICY_H
