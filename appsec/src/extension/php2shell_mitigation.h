// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// Mitigation for the "PHP2Shell" vulnerabilities on an UNPATCHED PHP, split
// across the two CVEs the upstream commit (PHP 8.2.35 / 8.3.36 / 8.4.27 /
// 8.5.12) fixed together:
//
//   * CVE-2026-109618 -- a request body field whose key allocation crosses
//     memory_limit makes the engine bail out (longjmp) in the middle of a
//     hash-table insertion, leaving a counted-but-uninitialised bucket that
//     request shutdown later walks as a zval. The treat_data wrapper (startup
//     request data) and, on PHP 8.4+, the request_parse_body() wrapper emulate
//     upstream's soft/hard-limit design so that such an allocation no longer
//     bails mid-insertion: it succeeds, the insertion completes, and the
//     out-of-memory error is deferred to a safe point (the input_filter
//     wrapper drops later variables, the php_rfc1867_callback wrapper skips
//     later file parts, the sapi_module.read_post wrapper ends multipart body
//     reads, the error is raised when the parse ends).
//
//   * CVE-2026-109619 -- "state corruption via bailout". Two extension-level
//     sub-mitigations: (B) resetting the request-owned engine globals upstream
//     clears so a later access cannot read stale/freed state, done at the
//     post_deactivate hook (the last-error state from the
//     sapi_module.deactivate wrapper); and (C) turning session upload progress
//     off for the startup POST parse, so attacker-controlled session data is
//     never unserialized (no __wakeup / __unserialize / autoload) before the
//     application's request init runs.
//
// See php2shell_mitigation.c for the full rationale.

#ifndef DD_PHP2SHELL_MITIGATION_H
#define DD_PHP2SHELL_MITIGATION_H

#include <php.h>
#include <stdbool.h>

#include "attributes.h"

// Resolve whether this PHP is patched and the initial appsec gate. Installs no
// hook. Called from PHP_MINIT, after configuration is available.
void dd_php2shell_minit(void);

// Capture the hook slots once every module MINIT has run, and put our wrappers
// in them. Called from the zend_extension startup hook.
void dd_php2shell_startup(void);

// Take our wrappers out of the process-global slots they still occupy and
// forget the saved handlers; first, take our Zend MM handlers off the heap if
// a failed request startup left them there. Called first in PHP_MSHUTDOWN.
void dd_php2shell_mshutdown(void);

// Set whether appsec's state lets the mitigation run. Process-wide; writes
// only when the value changes.
void dd_php2shell_set_allowed(bool allowed);

// Apply the WAF's decision from the request_init response (none, monitor or
// block). Process-wide and sticky; ignored unless the mode is auto. An unknown
// value is logged and changes nothing.
void dd_php2shell_set_waf_decision(const char *nonnull value, size_t len);

// The value of php.php2shell.vulnerable to send in request_init ("full" or
// "partial"), or NULL when the address is not to be sent.
const char *nullable dd_php2shell_vulnerable_value(void);

// On PHP 7.1+, if the mitigation is active, install the Zend MM handlers for
// the request and arm it until dd_php2shell_rshutdown(). Called at the end of
// PHP_RINIT, after appsec's enabled state is resolved and remote config
// applied (and dd_php2shell_set_allowed() called for them).
void dd_php2shell_rinit(void);

// Disarm the request and remove the handlers dd_php2shell_rinit() installed,
// if the slot still holds them. Called at the start of PHP_RSHUTDOWN.
void dd_php2shell_rshutdown(void);

// Settle the request-level Zend MM handlers from the slot (reset the heap to
// plain zend_mm if a bailout skipped dd_php2shell_rshutdown()), reset the
// request-owned engine globals that an OOM bailout can leave stale
// (CVE-2026-109619 sub-mitigation B), and check whether a hook was displaced.
// Must be called from the module's post_deactivate hook: that runs after
// zend_deactivate()/shutdown_executor has created the stale state, while the
// memory manager is still live, and before the next request's init_executor
// would re-initialise them. The reset runs only on an unpatched runtime with
// the mitigation active (the mode allows it and appsec is not known to be
// disabled).
void dd_php2shell_post_deactivate(void);

// Emit the mitigation state into `php --ri ddappsec` (MINFO).
void dd_php2shell_minfo(void);

#endif // DD_PHP2SHELL_MITIGATION_H
