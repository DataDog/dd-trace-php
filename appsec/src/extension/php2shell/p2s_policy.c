// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// Gate. Active when the mode allows it and appsec is not known to be disabled.
// DD_APPSEC_PHP2SHELL_MITIGATION is off, on (also on a patched runtime), auto
// (the default: only on an unpatched runtime, i.e. when the _femalloc symbol
// of the fix does not resolve). The appsec flag is per thread (see the
// per-thread state note below): it starts true unless datadog.appsec.enabled
// is explicitly false; RINIT clears it when appsec resolves to fully disabled,
// and under remote config it follows the helper's latest answer.
//
// Armed (parse windows and request level) when active and, under mode auto,
// the WAF's sticky decision is pending or block; mode on arms whatever the WAF
// says, and asks nothing. Under mode auto, an unpatched runtime is "full", or
// "partial" when it carries only the minimal fix of the EOL branches (the
// zend_mm_safe_error_ex symbol resolves, _femalloc does not); either is sent to
// the WAF as php.php2shell.vulnerable in every request_init
// (dd_p2s_policy_vulnerable_value). Each explicit answer in the response
// (none, monitor or block) replaces the decision for the thread's later
// reads; no answer (no WAF call, an error, an older helper) leaves it
// unchanged. The decision starts pending, so a thread is armed until its first
// answer.
// monitor disarms but has native memory errors reported
// (dd_p2s_policy_monitoring). The appsec flag wins over any decision.

#include "p2s_policy.h"

#include <SAPI.h>
#include <ext/standard/info.h>
#include <php.h>
#include <pthread.h> // thread-safe lazy config
#include <string.h>

#include <alloc.h> // zend_abstract_interface/alloc.h: runtime patch detection

#include "../compatibility.h"
#include "../configuration.h"
#include "../ddappsec.h" // dd_appsec_config_first_rinit_once
#include "../ddtrace.h"
#include "../logging.h"
#include "../php_compat.h" // NOLINT (must come before any *_arginfo.h)
#include "../php_objects.h"
#include "../string_helpers.h"

typedef enum {
    DD_PHP2SHELL_AUTO = 0,
    DD_PHP2SHELL_ON,
    DD_PHP2SHELL_OFF,
} dd_php2shell_mode;

// ---- process-wide state ----------------------------------------------------
//
// _patched is fixed in MINIT (single-threaded, before any worker thread serves
// a request), so its request-time reads need no synchronisation. Whether the
// hooks are installed is never cached: it is read from the live slots. _mode is
// resolved LAZILY, not in
// MINIT: under php-fpm the pool `env[...]` directives are applied in the worker
// child AFTER MINIT (which runs in the master), so a MINIT read would miss
// them. _resolve_config_once() forces the per-worker zai config refresh and
// reads the effective value the first time it is needed (which, on request #1,
// is before module RINIT -- see _resolve_config_once).
//
// On ZTS the first request may run concurrently on several threads, so _mode is
// written exactly once under a pthread_once (in _resolve_config_once). Every
// reader of _mode calls _resolve_config_once() first (directly or via
// dd_p2s_policy_active()), and pthread_once establishes a happens-before edge
// from that single write to every later return, so the reads are race-free
// without any further barrier.

static dd_php2shell_mode _mode = DD_PHP2SHELL_AUTO;
static bool _patched;
// Unpatched, but carrying the minimal fix of the EOL branches. Fixed in MINIT,
// with _patched.
static bool _partial;
// Bumped by each MINIT; a thread whose state is from an earlier generation (or
// none) starts again from the initial state (see _thread_state). Statics, and
// the thread-local state of a thread that survives it, outlive a module
// restart while the DSO stays mapped. Written in MINIT only, before any thread
// serves a request.
static unsigned _generation;
// Whether dd_php2shell_startup() installed the hooks. Without them the
// mitigation is off for the process (see dd_p2s_policy_active). Written during
// startup and in MSHUTDOWN only.
static bool _hooks_installed;

// ---- per-thread state ------------------------------------------------------
//
// The WAF's sticky decision and the appsec flag are thread-local on ZTS (plain
// statics on NTS). Their sources are per thread: DDAPPSEC_G(active) is a
// per-thread module global, and each thread has its own helper connection,
// whose answers the helper caches per connection. So they change only at this
// thread's own RINIT and request_init, between its requests, and every read of
// one request sees the same values. Each thread starts from the initial state
// (see _thread_state) and converges separately: armed until its first answer.

// The generation of this thread's state; 0 (none) until _thread_state().
ZEND_TLS unsigned _thread_generation;
// The WAF's sticky decision, consulted under mode auto only. Pending, or the
// testing knob's, initially; then set by dd_p2s_policy_set_decision().
ZEND_TLS dd_p2s_decision _decision = DD_P2S_DECISION_PENDING;
// Whether appsec's state lets the mitigation run: false once appsec is known
// to be disabled. Initially true unless datadog.appsec.enabled is explicitly
// false; then changed by dd_php2shell_set_allowed().
ZEND_TLS bool _allowed = true;

static void _thread_state(void);
static void _resolve_config_once(void);
static void _resolve_config(void);
static bool _sapi_excluded(void);
static bool _mode_allows_activation(void);
static const char *_state_name(void);
static const char *_decision_name(void);
static dd_php2shell_mode _parse_mode(const zend_string *nullable value);
static void _apply_testing_runtime(void);
static void _apply_testing_decision(void);
#ifdef TESTING
static PHP_FUNCTION(datadog_appsec_testing_php2shell_set_allowed);
#endif

void dd_p2s_policy_minit(void)
{
    // Version detection is process-constant, so it is safe to do in MINIT. The
    // configuration mode is resolved lazily (see _resolve_config_once). No hook
    // is installed here: dd_php2shell_startup() installs them after every
    // MINIT.
#if PHP_VERSION_ID >= 80600
    // PHP 8.6 counts as patched: its first release ships the fix, and only its
    // pre-releases lack it.
    _patched = true;
#else
    _patched = zai_resolve__femalloc() != NULL;
#endif
    _partial = !_patched && zai_has_zend_mm_safe_error_ex();
    if (get_global_DD_APPSEC_TESTING()) {
        _apply_testing_runtime();
    }
    // Rather than relying on the initialisers: statics, and a surviving
    // thread's state, outlive a module restart while the DSO stays mapped.
    _generation++;
}

void dd_p2s_policy_hooks_installed(void) { _hooks_installed = true; }

void dd_p2s_policy_mshutdown(void) { _hooks_installed = false; }

void dd_p2s_policy_set_allowed(bool allowed)
{
    // The initial value, set once per thread after the config refresh, must
    // not overwrite this one: set it first. Usually done already by the
    // startup parse, but not when it called no dd_p2s_policy_active() (e.g.
    // variables_order without G, P and C).
    _thread_state();
    _allowed = allowed;
}

void dd_p2s_policy_set_decision(dd_p2s_decision decision)
{
    // As dd_p2s_policy_set_allowed(): the testing knob, applied once per
    // thread after the config refresh, must not overwrite this value.
    _resolve_config_once();
    _thread_state();
    if (_mode != DD_PHP2SHELL_AUTO) {
        return;
    }
    if (_decision != decision) {
        _decision = decision;
        mlog(dd_log_debug, "PHP2Shell mitigation: WAF decision is now %s",
            _decision_name());
    }
}

bool dd_p2s_policy_parse_decision(
    const char *nonnull value, size_t len, dd_p2s_decision *nonnull decision)
{
    if (dd_string_equals_lc(value, len, ZEND_STRL("none"))) {
        *decision = DD_P2S_DECISION_OFF;
    } else if (dd_string_equals_lc(value, len, ZEND_STRL("monitor"))) {
        *decision = DD_P2S_DECISION_MONITOR;
    } else if (dd_string_equals_lc(value, len, ZEND_STRL("block"))) {
        *decision = DD_P2S_DECISION_BLOCK;
    } else {
        return false;
    }
    return true;
}

bool dd_p2s_policy_patched(void) { return _patched; }

const char *nullable dd_p2s_policy_vulnerable_value(void)
{
    _resolve_config_once(); // once-guarded read of _mode
    if (!_hooks_installed || _mode != DD_PHP2SHELL_AUTO || _patched) {
        return NULL;
    }
    return _partial ? "partial" : "full";
}

void dd_p2s_policy_minfo(void)
{
    _resolve_config_once();
    _thread_state();
    php_info_print_table_row(
        2, "PHP2Shell mitigation (CVE-2026-109618/109619)", _state_name());
    php_info_print_table_row(
        2, "PHP2Shell mitigation mode", dd_p2s_policy_mode_name());
    php_info_print_table_row(
        2, "PHP2Shell patched runtime", _patched ? "Yes" : "No");
    php_info_print_table_row(2, "PHP2Shell WAF decision",
        _mode == DD_PHP2SHELL_AUTO ? _decision_name() : "n/a");
}

// Whether the mitigation is active for request data now: the mode allows it
// for this runtime, and appsec is not known to be disabled. _allowed starts
// true unless datadog.appsec.enabled is explicitly false (per thread, after
// the per-process config refresh, see _thread_state); RINIT clears it
// when appsec resolves to fully disabled; under remote config, it follows the
// latest config_features answer. The treat_data slot is not consulted;
// whether our wrapper occupies it is reported separately
// (_treat_data_hook_active).
bool dd_p2s_policy_active(void)
{
    if (!_hooks_installed || !_mode_allows_activation()) {
        return false;
    }
    _thread_state();
    return _allowed;
}

// Whether to arm the parse windows and the request level. Mode on arms
// whatever the WAF says; under mode auto, the WAF's last decision, pending
// (no answer yet, "start enabled") included, decides.
bool dd_p2s_policy_armed(void)
{
    if (!dd_p2s_policy_active()) {
        return false;
    }
    if (_mode == DD_PHP2SHELL_ON) { // resolved by dd_p2s_policy_active()
        return true;
    }
    // The thread's state is set by dd_p2s_policy_active().
    return _decision == DD_P2S_DECISION_PENDING ||
           _decision == DD_P2S_DECISION_BLOCK;
}

bool dd_p2s_policy_monitoring(void)
{
    return dd_p2s_policy_active() && _mode == DD_PHP2SHELL_AUTO &&
           _decision == DD_P2S_DECISION_MONITOR;
}

// Whether to install the hooks at all, from the configuration visible at the
// zend_extension startup hook (under php-fpm, the master's: a pool's env[]
// cannot re-enable what is disabled here). Hooks are skipped when the
// mitigation can never activate in this process: the mode is off; the mode is
// auto on a patched runtime (on only overrides the patch check, never
// appsec's state); appsec is explicitly disabled; or, as appsec's own
// _compute_enabled() decides outside testing, the tracer is explicitly
// disabled or not loaded; or the SAPI is cli, phpdbg or embed, whatever the
// mode (see Coverage: CLI SAPIs). Otherwise appsec may still be enabled later
// (remote config), so the hooks are installed and the runtime gate decides.
// The mode is parsed here without _resolve_config_once(), which must stay
// unresolved until the workers' config refresh.
bool dd_p2s_policy_startup_may_hook(void)
{
    if (_sapi_excluded()) {
        return false;
    }
    dd_php2shell_mode mode =
        _parse_mode(get_global_DD_APPSEC_PHP2SHELL_MITIGATION());
    if (mode == DD_PHP2SHELL_OFF || (mode == DD_PHP2SHELL_AUTO && _patched)) {
        return false;
    }
    if (dd_cfg_appsec_explicitly_disabled()) {
        return false;
    }
    if (!get_global_DD_APPSEC_TESTING() &&
        (!dd_trace_loaded() || dd_cfg_trace_explicitly_disabled())) {
        return false;
    }
    return true;
}

// Whether the SAPI is one the mitigation never covers: cli (cron jobs, queue
// workers), phpdbg and embed run no untrusted request data through the
// startup parse, and whole-request arming would only turn their ordinary
// out-of-memory errors into deferred ones. cli-server (php -S) and frankenphp
// stay covered.
static bool _sapi_excluded(void)
{
    const char *name = sapi_module.name;
    return name != NULL &&
           (strcmp(name, "cli") == 0 || strcmp(name, "phpdbg") == 0 ||
               strcmp(name, "embed") == 0);
}

// The process-constant part of dd_p2s_policy_active(): whether the configured
// mode, given the runtime's patch state, lets the mitigation be active at all
// in this process.

static bool _mode_allows_activation(void)
{
    _resolve_config_once(); // once-guarded read of _mode
    switch (_mode) {
    case DD_PHP2SHELL_OFF:
        return false;
    case DD_PHP2SHELL_ON:
        return true;
    case DD_PHP2SHELL_AUTO:
    default:
        return !_patched;
    }
}

const char *nonnull dd_p2s_policy_mode_name(void)
{
    _resolve_config_once(); // once-guarded read of _mode
    switch (_mode) {
    case DD_PHP2SHELL_OFF:
        return "off";
    case DD_PHP2SHELL_ON:
        return "on";
    case DD_PHP2SHELL_AUTO:
    default:
        return "auto";
    }
}

// The headline state reported by MINFO: the gate (dd_p2s_policy_active()) only.
// The state of each hook has its own MINFO row.
static const char *_state_name(void)
{
    return dd_p2s_policy_active() ? "Active" : "Inactive";
}

static const char *_decision_name(void)
{
    switch (_decision) {
    case DD_P2S_DECISION_OFF:
        return "none";
    case DD_P2S_DECISION_MONITOR:
        return "monitor";
    case DD_P2S_DECISION_BLOCK:
        return "block";
    case DD_P2S_DECISION_PENDING:
    default:
        return "pending";
    }
}

// Resolve the effective mode (and build the telemetry tag) exactly once per
// worker, honoring php.ini, stable-config and -- crucially for php-fpm -- pool
// env[...] directives. Those are applied in the worker child after MINIT, so a
// MINIT read misses them. dd_appsec_config_first_rinit_once()
// (pthread_once-guarded, cheap to pull earlier) forces zai's per-worker config
// refresh, which re-reads the process environment. On request #1 our
// treat_data hook runs during php_hash_environment, before module RINIT would
// otherwise trigger that refresh, so we trigger it here; the later RINIT call
// is then a no-op. Only the config refresh is pulled earlier, not
// dd_appsec_rinit_once(), which would also resolve appsec's own enabled state.
//
// The single write to _mode runs under _config_once (see the process-wide
// state note above); dd_appsec_config_first_rinit_once() runs first, outside
// it, because it populates the memoized config that _resolve_config() reads.
//
// The per-thread initial state (see _thread_state) is set only after this
// refresh: MINIT (in the php-fpm master) cannot see the pool env[] directives,
// nor php-cgi the FastCGI params, and either may enable or disable appsec.
static void _resolve_config(void)
{
    _mode = _parse_mode(get_global_DD_APPSEC_PHP2SHELL_MITIGATION());
}

// Set this thread's initial state, the first time it is needed in this module
// generation: the appsec flag from the configuration (after the config refresh
// of _resolve_config_once), and the decision pending, or the testing knob's.
// Every reader and writer of _decision and _allowed calls it first, so the
// writers' values (dd_php2shell_set_allowed(), dd_php2shell_set_decision())
// are never overwritten by it.
static void _thread_state(void)
{
    if (EXPECTED(_thread_generation == _generation)) {
        return;
    }
    _thread_generation = _generation;
    _resolve_config_once();
    _allowed = !dd_cfg_appsec_explicitly_disabled();
    _decision = DD_P2S_DECISION_PENDING;
    if (get_global_DD_APPSEC_TESTING()) {
        _apply_testing_decision();
    }
}

static void _resolve_config_once(void)
{
    static pthread_once_t _config_once = PTHREAD_ONCE_INIT;
    dd_appsec_config_first_rinit_once();
    pthread_once(&_config_once, _resolve_config);
}

static dd_php2shell_mode _parse_mode(const zend_string *nullable value)
{
    // NULL means the entry was never materialised; fall back to the default.
    if (value == NULL) {
        return DD_PHP2SHELL_AUTO;
    }
    // php.ini coerces boolean-looking tokens before this STRING entry sees
    // them: "on"/"true"/"yes" -> "1" and "off"/"false"/"no" -> "". Accept both
    // the coerced and the raw spellings so the toggle behaves the same whether
    // it is set via datadog.appsec.php2shell_mitigation (INI) or
    // DD_APPSEC_PHP2SHELL_MITIGATION (env). The default value is "auto", so an
    // empty string only ever means an explicit off/false.
    if (ZSTR_LEN(value) == 0) {
        return DD_PHP2SHELL_OFF;
    }
    if (zend_string_equals_literal_ci(value, "auto")) {
        return DD_PHP2SHELL_AUTO;
    }
    if (zend_string_equals_literal_ci(value, "on") ||
        zend_string_equals_literal_ci(value, "1") ||
        zend_string_equals_literal_ci(value, "true") ||
        zend_string_equals_literal_ci(value, "yes")) {
        return DD_PHP2SHELL_ON;
    }
    if (zend_string_equals_literal_ci(value, "off") ||
        zend_string_equals_literal_ci(value, "0") ||
        zend_string_equals_literal_ci(value, "false") ||
        zend_string_equals_literal_ci(value, "no")) {
        return DD_PHP2SHELL_OFF;
    }
    mlog(dd_log_warning,
        "PHP2Shell mitigation: unknown mode '%s', defaulting to 'auto'",
        ZSTR_VAL(value));
    return DD_PHP2SHELL_AUTO;
}

// Testing only (datadog.appsec.testing on): DD_APPSEC_TESTING_PHP2SHELL_RUNTIME
// = full | partial | patched replaces the detected runtime, which a test image
// may not provide (a partial runtime cannot be produced otherwise). Read in
// MINIT and at the zend_extension startup hook, so under php-fpm it must be
// set in php.ini or the master's environment, not in a pool env[].
static void _apply_testing_runtime(void)
{
    const zend_string *nullable value =
        get_global_DD_APPSEC_TESTING_PHP2SHELL_RUNTIME();
    if (value == NULL || ZSTR_LEN(value) == 0) {
        return;
    }
    if (zend_string_equals_literal(value, "full")) {
        _patched = false;
        _partial = false;
    } else if (zend_string_equals_literal(value, "partial")) {
        _patched = false;
        _partial = true;
    } else if (zend_string_equals_literal(value, "patched")) {
        _patched = true;
        _partial = false;
    } else {
        mlog(dd_log_warning,
            "PHP2Shell mitigation: unknown testing runtime '%s', ignored",
            ZSTR_VAL(value));
    }
}

// Testing only (datadog.appsec.testing on):
// DD_APPSEC_TESTING_PHP2SHELL_DECISION = none | monitor | block is the initial
// decision, in place of pending, for the tests that need it at the startup
// parse or at RINIT. Ignored unless the mode is auto. Runs from _thread_state
// (_mode is already resolved).
static void _apply_testing_decision(void)
{
    const zend_string *nullable value =
        get_global_DD_APPSEC_TESTING_PHP2SHELL_DECISION();
    if (value == NULL || ZSTR_LEN(value) == 0 || _mode != DD_PHP2SHELL_AUTO) {
        return;
    }
    dd_p2s_decision decision;
    if (!dd_p2s_policy_parse_decision(
            ZSTR_VAL(value), ZSTR_LEN(value), &decision)) {
        mlog(dd_log_warning,
            "PHP2Shell mitigation: unknown testing decision '%s', ignored",
            ZSTR_VAL(value));
        return;
    }
    _decision = decision;
}

#ifdef TESTING
// Test-only, registered only with datadog.appsec.testing on: set_allowed()
// changes the appsec flag as RINIT and remote config do, where no per-request
// setting can (php -S, see
// php2shell_mitigation_upload_progress_startup_off.phpt).
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    _php2shell_set_allowed_arginfo, 0, 1, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, allowed, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry _php2shell_policy_testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "php2shell_mitigation_set_allowed", PHP_FN(datadog_appsec_testing_php2shell_set_allowed), _php2shell_set_allowed_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

void dd_p2s_policy_register_testing(void)
{
    dd_phpobj_reg_funcs(_php2shell_policy_testing_functions);
}

// Write the appsec flag through the same writer as RINIT and remote config
// (dd_php2shell_set_allowed), for later requests of this thread.
static PHP_FUNCTION(datadog_appsec_testing_php2shell_set_allowed)
{
    zend_bool allowed;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "b", &allowed) == FAILURE) {
        RETURN_FALSE;
    }
    dd_p2s_policy_set_allowed(allowed);
    RETURN_TRUE;
}
#endif // TESTING
