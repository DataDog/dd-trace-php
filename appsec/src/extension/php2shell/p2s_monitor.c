// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// Monitor state (the WAF's decision is monitor): nothing is armed, so a
// crossing of memory_limit, or an allocation the operating system refuses,
// fails natively, inside the allocation. Our zend_error_cb wrapper sees the
// error as zend_mm_safe_error() raises it, an E_ERROR whose message starts with
// "Allowed memory size of " (memory_limit) or "Out of memory" (out_of_memory),
// and reports it on the span with its phase (see p2s_report.c) before chaining
// to the handler it replaced, which records and displays the error and bails
// out. A zend_error_cb wrapper rather than an error observer, which PHP 7.x
// lacks. It is installed from the zend_extension startup hook, after ddtrace
// wrapped zend_error_cb in its MINIT, so the tags are on the span before
// ddtrace records the error. On PHP 8.0 only, it is installed from
// zend_post_startup_cb instead: opcache's post-startup callback
// (accel_post_startup, 8.0 only) puts its own zend_error_cb wrapper
// (persistent_error_cb, which records compile warnings) on top for good, which
// would leave ours displaced in every process with opcache enabled. Ours
// registers in the zend_extension startup hook, after opcache's (opcache is
// registered from php.ini, ddappsec in its MINIT), so it is called first and
// installs the wrapper after opcache's returns. Accepted consequence: the
// opcache.preload script, which runs inside opcache's callback, is not
// monitored on PHP 8.0. Outside the monitor state, and for any other
// error type, it only forwards. The phase: startup before the module RINITs
// (PG(modules_activated) is still 0; the startup parse runs then), where no
// span exists, so the error is only logged (see Coverage: Monitor mode);
// request_parse_body inside a call to request_parse_body() (PHP 8.4+, its frame
// is the current one: it calls no user code); runtime otherwise.

#include "p2s_monitor.h"

#include <php.h>
#include <stdatomic.h>
#include <string.h>

#include "../ddappsec.h" // TSRMLS_CACHE as local-dynamic: no STATIC_TLS
#include "../logging.h"
#include "../php_compat.h" // zif_handler (PHP 7.0), used by p2s_hook.h
#include "p2s_hook.h"
#include "p2s_oom_guard.h"
#include "p2s_policy.h"
#include "p2s_report.h"

#if PHP_VERSION_ID < 80000
#    define DD_P2S_ERROR_CB_PARAMS                                             \
        int type, const char *error_filename, const uint32_t error_lineno,     \
            const char *format, va_list args
#    define DD_P2S_ERROR_CB_PASSTHRU                                           \
        type, error_filename, error_lineno, format, args
// zend_mm passes a literal format, so the arguments need not be read.
#    define DD_P2S_ERROR_CB_MESSAGE format
#elif PHP_VERSION_ID < 80100
#    define DD_P2S_ERROR_CB_PARAMS                                             \
        int type, const char *error_filename, const uint32_t error_lineno,     \
            zend_string *message
#    define DD_P2S_ERROR_CB_PASSTHRU type, error_filename, error_lineno, message
#    define DD_P2S_ERROR_CB_MESSAGE ZSTR_VAL(message)
#else
#    define DD_P2S_ERROR_CB_PARAMS                                             \
        int type, zend_string *error_filename, const uint32_t error_lineno,    \
            zend_string *message
#    define DD_P2S_ERROR_CB_PASSTHRU type, error_filename, error_lineno, message
#    define DD_P2S_ERROR_CB_MESSAGE ZSTR_VAL(message)
#endif

// The zend_error_cb we chain to (php_error_cb, or another extension's wrapper,
// ddtrace's among them).
static void (*_error_cb_orig)(DD_P2S_ERROR_CB_PARAMS);
// Whether dd_p2s_monitor_install() ran. Written during startup and in
// MSHUTDOWN only.
static bool _error_cb_captured;
// Whether the displacement of the slot was logged; process-wide.
static atomic_bool _displaced_logged_error_cb;

#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100
#    define DD_P2S_POST_STARTUP_INSTALL
// The zend_post_startup_cb we chain to (opcache's, or NULL). Written during
// startup only.
static zend_result (*_post_startup_orig)(void);
static zend_result _post_startup(void);
#endif

static void _install_error_cb(void);
static void _error_cb(DD_P2S_ERROR_CB_PARAMS);
static ZEND_COLD void _detect(const char *nonnull message);
static dd_deferred_phase _phase(void);

// Called once, from the zend_extension startup hook (dd_php2shell_startup(),
// after every module MINIT), when the policy allows hooking. On PHP 8.0 it
// only registers the post-startup callback that installs the wrapper.
void dd_p2s_monitor_install(void)
{
#ifdef DD_P2S_POST_STARTUP_INSTALL
    if (zend_post_startup_cb != _post_startup) {
        _post_startup_orig = zend_post_startup_cb;
        zend_post_startup_cb = _post_startup;
    }
#else
    _install_error_cb();
#endif
}

// Called from MSHUTDOWN (dd_php2shell_mshutdown()), before ddtrace's MSHUTDOWN
// puts back the handler it replaced.
void dd_p2s_monitor_uninstall(void)
{
#ifdef DD_P2S_POST_STARTUP_INSTALL
    // Only if startup failed before zend_post_startup() called it.
    DD_P2S_SLOT_RESTORE(
        zend_post_startup_cb, _post_startup, _post_startup_orig);
    _post_startup_orig = NULL;
#endif
    DD_P2S_SLOT_RESTORE(zend_error_cb, _error_cb, _error_cb_orig);
    _error_cb_orig = NULL;
    _error_cb_captured = false;
    atomic_store(&_displaced_logged_error_cb, false);
}

void dd_p2s_monitor_check_error_cb(void)
{
    dd_p2s_hook_check_displaced(_error_cb_captured,
        DD_P2S_SLOT_HOLDS(zend_error_cb, _error_cb),
        &_displaced_logged_error_cb, "zend_error_cb",
        "native memory errors are not reported in monitor mode");
}

void dd_p2s_monitor_minfo_error_cb(void)
{
    dd_p2s_hook_minfo_row("PHP2Shell zend_error_cb hook", _error_cb_captured,
        DD_P2S_SLOT_HOLDS(zend_error_cb, _error_cb));
}

#ifdef DD_P2S_POST_STARTUP_INSTALL
// Single-threaded, from zend_post_startup(), which clears zend_post_startup_cb
// before calling it.
static zend_result _post_startup(void)
{
    zend_result (*orig)(void) = _post_startup_orig;
    _post_startup_orig = NULL;
    if (orig != NULL && orig() != SUCCESS) {
        return FAILURE;
    }
    _install_error_cb();
    return SUCCESS;
}
#endif

static void _install_error_cb(void)
{
    _error_cb_captured = true;
    if (zend_error_cb != _error_cb) {
        _error_cb_orig = zend_error_cb;
        zend_error_cb = _error_cb;
    }
}

// Only a type comparison for any error, and a policy check for E_ERROR, outside
// the monitor state.
static void _error_cb(DD_P2S_ERROR_CB_PARAMS)
{
    if (UNEXPECTED(type == E_ERROR) && dd_p2s_policy_monitoring()) {
        _detect(DD_P2S_ERROR_CB_MESSAGE);
    }
    _error_cb_orig(DD_P2S_ERROR_CB_PASSTHRU);
}

static ZEND_COLD void _detect(const char *nonnull message)
{
    dd_p2s_memory_error kind;
    // Not ZEND_STRL: glibc 2.17 defines strncmp as a 3-parameter macro at -O2.
    if (strncmp(message, "Allowed memory size of ",
            sizeof("Allowed memory size of ") - 1) == 0) {
        kind = DD_P2S_MEMORY_LIMIT;
    } else if (strncmp(message, "Out of memory", sizeof("Out of memory") - 1) ==
               0) {
        kind = DD_P2S_OUT_OF_MEMORY;
    } else {
        return;
    }
    dd_deferred_phase phase = _phase();
    dd_p2s_report_memory_error(kind, phase);
    mlog(dd_log_info,
        "PHP2Shell mitigation (monitor): native %s error (phase:%s)",
        kind == DD_P2S_MEMORY_LIMIT ? "memory_limit" : "out_of_memory",
        dd_p2s_phase_name(phase));
}

static dd_deferred_phase _phase(void)
{
    if (!PG(modules_activated)) {
        return DD_PHASE_STARTUP;
    }
#if PHP_VERSION_ID >= 80400
    zend_execute_data *ex = EG(current_execute_data);
    if (ex != NULL && ex->func != NULL &&
        ex->func->type == ZEND_INTERNAL_FUNCTION &&
        ex->func->common.function_name != NULL &&
        zend_string_equals_literal(
            ex->func->common.function_name, "request_parse_body")) {
        return DD_PHASE_REQUEST_PARSE_BODY;
    }
#endif
    return DD_PHASE_RUNTIME;
}
