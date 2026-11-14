// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// The PHP2Shell gate: the configured mode, whether the runtime is patched,
// whether appsec lets the mitigation run, whether the hooks are installed, and
// the WAF's sticky decision.

#ifndef DD_P2S_POLICY_H
#define DD_P2S_POLICY_H

#include <stdbool.h>
#include <stddef.h>

#include "../attributes.h"

// The WAF's sticky decision (per thread), consulted under mode auto only.
// PENDING (no answer yet) behaves like BLOCK; the two are kept apart so that
// MINFO and tests can tell them apart.
typedef enum {
    DD_P2S_DECISION_PENDING = 0,
    DD_P2S_DECISION_OFF,
    DD_P2S_DECISION_MONITOR,
    DD_P2S_DECISION_BLOCK,
} dd_p2s_decision;

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
// Whether the mitigation is active for request data now: hooks installed, the
// mode allows it and appsec allows it. The decision is not consulted.
bool dd_p2s_policy_active(void);
// Whether to arm (parse windows and request level): active, and the mode is on
// or the WAF's decision is pending or block.
bool dd_p2s_policy_armed(void);
// Whether native memory errors are to be detected and reported: active, mode
// auto and the WAF's decision is monitor.
bool dd_p2s_policy_monitoring(void);
// Store the WAF's decision (per thread, sticky). Ignored unless the mode is
// auto.
void dd_p2s_policy_set_decision(dd_p2s_decision decision);
// Parse a decision name (none, monitor or block, case-insensitive). False,
// leaving *decision untouched, for any other value.
bool dd_p2s_policy_parse_decision(
    const char *nonnull value, size_t len, dd_p2s_decision *nonnull decision);
// The value of php.php2shell.vulnerable ("full" or "partial") to send to the
// WAF, or NULL when it is not to be sent: the hooks are not installed, the mode
// is not auto, or the runtime is patched.
const char *nullable dd_p2s_policy_vulnerable_value(void);
// Whether the runtime carries the upstream fix. Fixed in MINIT.
bool dd_p2s_policy_patched(void);
// The configured mode, as reported by MINFO and telemetry.
const char *nonnull dd_p2s_policy_mode_name(void);
// The state, mode, patched and WAF decision MINFO rows.
void dd_p2s_policy_minfo(void);
#ifdef TESTING
void dd_p2s_policy_register_testing(void);
#endif

#endif // DD_P2S_POLICY_H
