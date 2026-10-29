// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// Mitigation for the "PHP2Shell" CVEs (CVE-2026-109618 and CVE-2026-109619) on
// UNPATCHED PHP runtimes. Upstream fixed both together in PHP 8.2.35 / 8.3.36 /
// 8.4.27 / 8.5.12; PHP 8.6 is treated as patched (only its pre-releases
// predate the fix).
//
// CVE-2026-109618. _zend_hash_str_add_or_update_i() bumps the hash table's
// entry counters before it allocates the key string. If that allocation
// crosses memory_limit, zend_mm_safe_error() bails out (a longjmp) in the
// middle of the insertion, and request shutdown later walks the counted but
// uninitialised bucket as a zval. Request data is parsed before any userland
// runs, so the request alone can trigger it. Upstream lets an allocation that
// crosses the soft limit (memory_limit) succeed, raises the out-of-memory error
// later at a safe point, and adds a hard limit that ends the process with
// _exit(137).
//
// Hooks. Installed once, from the zend_extension startup hook (after every
// module MINIT), and not written again until MSHUTDOWN: sapi_module.treat_data,
// the sapi_module.input_filter / input_filter_init pair, php_rfc1867_callback,
// sapi_module.deactivate, sapi_module.read_post, on PHP 8.4+ the handler of
// request_parse_body(), for the request level (p2s_runtime.c), on PHP 7.1+
// zend_interrupt_function and on PHP 7.1-8.3 the handler of gc_mem_caches(),
// and, for the monitor state (p2s_monitor.c), zend_error_cb (on PHP 8.0 from
// zend_post_startup_cb, over opcache's wrapper; see p2s_monitor.c). A
// slot that another extension replaces later is not repaired (see
// php2shell/p2s_hook.h); since another extension may also wrap ours and chain
// to it, "displaced" means only that ours is no longer on top. The Zend MM
// handlers are not among them: they are installed per request (see Request
// level in p2s_runtime.c). No hook is installed, and the mitigation stays off
// for the process, under the cli, phpdbg and embed SAPIs, or when the
// configuration seen at startup already rules it out (see
// php2shell/p2s_policy.c): appsec or the tracer explicitly disabled, the mode
// off, or the mode auto on a patched runtime.
//
// WAF decision. Under the mode auto, every request_init sends
// php.php2shell.vulnerable = full (unpatched) or partial (only the minimal fix
// of the EOL branches) to the WAF, whose ruleset decides through the response's
// php2shell_mitigation setting: none (disarmed), monitor (disarmed; native
// memory errors are detected as they are raised and reported on the span) or
// block (armed). The decision is process-wide and sticky: it starts pending,
// which arms as block does, and only an explicit answer changes it. The mode on
// arms whatever the WAF says and sends nothing; off, and auto on a patched
// runtime, send nothing (see php2shell/p2s_policy.c and Coverage: WAF
// decision). The helper evaluates the address in a WAF run of its own, without
// a timeout, and keeps the rule's match out of the request's appsec events.
//
// The mechanisms are described at the top of the unit, under php2shell/, that
// implements them:
//   - Windows, and the hard limit of a window: p2s_startup_parse.c (the
//     request_parse_body() wrapper, PHP 8.4+, is p2s_request_parse_body.c);
//   - Arming, our Zend MM handlers and the deferred raise: p2s_oom_guard.c;
//   - Request level (PHP 7.1+): p2s_runtime.c;
//   - CVE-2026-109619 sub-mitigations B and C: p2s_stale_state.c;
//   - Gate and WAF decision: p2s_policy.c;
//   - Monitor state, the detection of native memory errors: p2s_monitor.c;
//   - Span tags and trace keep: p2s_report.c.
//
// Observability: two metrics (DD_METRIC_* in p2s_oom_guard.c), flushed
// synchronously to the sidecar, tagged with the mode; the deferred one also
// with the phase where the error was raised (phase:startup,
// phase:request_parse_body or phase:runtime, see dd_deferred_phase), which its
// log line names too; span tags on the request's root span, best effort (none
// before RINIT, see p2s_report.c), each of which also keeps the trace (user
// keep, as an appsec event): _dd.appsec.php2shell.deferred = <phase> for a
// deferred error (block state, and the mode on), and, in the monitor state,
// _dd.appsec.php2shell.memory_error = memory_limit or out_of_memory with
// _dd.appsec.php2shell.phase = <phase> for a native one; one log line per
// displaced hook, and one for an expanding filter.default (see Coverage:
// filter.default); MINFO rows for the state, the mode, the patch state, the WAF
// decision and each hook (Installed / Displaced / Not installed).
//
// Goals:
//   A. On an unpatched runtime, a memory-limit error during the startup
//      request-variable parse or request_parse_body() never bails out in the
//      middle of an insertion. It is deferred to a safe point (per variable
//      via input_filter, or at the end of the parse), and the hard limit
//      bounds it. On PHP 7.1+, the same holds for insertions at runtime
//      (parse_str(), add_assoc_*_ex() builders), deferred to the next VM
//      interrupt.
//   B. The CVE-2026-109619 stale request state is reset in post_deactivate,
//      and session upload progress is off during the startup POST parse.
//   C. Gated on the unpatched runtime, the mode toggle, the appsec flag and
//      the SAPI; under the mode auto, armed only as the WAF decides.
//   D. Two metrics (soft-deferred and hard-exit), flushed synchronously, and
//      span tags for deferred errors and, in the monitor state, for native
//      memory errors, with the trace kept.
//   E. No hard exit for bodies within post_max_size, up to the constant cap.
//
// Coverage limits, grouped by consequence. Each entry has a label, cited
// elsewhere as "(see Coverage: <label>)". The functions an entry names are in
// the php2shell/ unit that implements the mechanism.
//
// 1. Not protected: the CVEs stay reachable.
//   - PHP 7.0, after RINIT: without EG(vm_interrupt) there is no request
//     level, so parse_str() and internal functions that build arrays with
//     add_assoc_*_ex() still use the vulnerable zend_hash_str_* insertion
//     unarmed, as do the superglobals FrankenPHP re-imports per HTTP request
//     in worker mode. A crossing there matters only if the array stays
//     reachable after the bailout and is walked: the global symbol table's
//     top-level entries (read by shutdown_destructors()) or what a shutdown
//     function reads. No destructor runs after a fatal error. Builders with
//     zend_string keys (unserialize, json_decode, array_*, VM writes) do not
//     have the bug.
//   - Outside RINIT..RSHUTDOWN: the request level spans our RINIT to our
//     RSHUTDOWN. RINITs that run before ours (ddtrace, the profiler) and
//     RSHUTDOWNs that run after ours are unarmed, notably session write (the
//     session module is built in, so its RSHUTDOWN runs after ours), as are
//     output handlers flushed after our RSHUTDOWN.
//   - After a deferred fatal: the request is disarmed, so shutdown functions
//     run unarmed.
//   - Monitor mode: nothing is armed, as under the decision none, so the CVEs
//     stay reachable; native memory errors are only reported. One raised
//     before every module RINIT has run (the startup parse) has the phase
//     startup, which is never tagged: it is only logged, at info level, and no
//     metric counts it. An "Out of memory" error is reported while zend_mm's
//     overflow flag is set, so if the operating system refuses again one of
//     the small allocations of the report (the tags), zend_mm ends the process
//     (exit(1)) instead of the fatal error. The trace keep, here as for a
//     deferred error, has no rate limit: an application that crosses
//     memory_limit on every request keeps every trace.
//   - CLI SAPIs: under cli, phpdbg and embed the mitigation is off in every
//     mode, as if appsec were fully disabled (see _sapi_excluded).
//   - USE_ZEND_ALLOC=0: nothing is armed. The plain stub enforces no limit;
//     with USE_TRACKED_ALLOC=1 (PHP 8.0+, test-only) a crossing bails as on
//     an unprotected runtime.
//   - OS allocation failures: under RLIMIT_AS or strict overcommit, including
//     moving reallocs.
//   - Late last errors: PG(last_error_*) set in sapi_deactivate_destroy() or
//     php_shutdown_stream_hashes() is not released by B.
//   - Handlers dropped from the chain: when another extension's handlers are
//     on top of ours at request end, ours are assumed to stay chained under
//     them and are only armed by later requests (see _settle_handlers,
//     _ensure_handlers). If that extension removed itself without restoring
//     ours while a third one's handlers are now in the slot, ours are not
//     called, yet arming still lifts the engine limit to the sentinel: those
//     requests run with no memory limit at all, until the slot shows ours or
//     plain zend_mm again.
//   - Displaced hooks: a hook displaced after our startup hook is detected,
//     logged and shown in MINFO, but not repaired. For the startup-window
//     hooks, its window is not armed (for input_filter, see Hard exit:
//     displaced input_filter). The request stays armed whatever happens to
//     zend_interrupt_function (see Coverage: Displaced interrupt hook) or to
//     gc_mem_caches(), whose replacement, if it does not chain to our wrapper,
//     may free nothing again.
//
// 2. The gate does not follow the expected configuration.
//   - Startup gating: no hook is installed when the configuration seen at
//     startup rules the mitigation out (see
//     dd_p2s_policy_startup_may_hook), so a php-fpm pool cannot re-enable
//     what the master disabled.
//   - opcache.preload: under php-fpm the preload request runs in the master,
//     which resolves the mode and the appsec gate there, so the workers do
//     not see the pool env[] values. appsec's own configuration behaves the
//     same way.
//   - WAF decision: it reaches the process from a request's RINIT
//     (request_init), so that request's startup parse follows the previous
//     decision, and a new process (each php-fpm worker) arms its first startup
//     parse whatever the ruleset says. No answer (appsec not active, the
//     helper unreachable, an error, an older helper) keeps the last decision.
//     A remote-config ruleset without the rule answers none and disarms.
//   - Per-directory datadog.appsec.enabled: follows appsec's own
//     once-per-process resolution. It reaches the flag only when set for the
//     first request (from that request's RINIT, after its startup parse), and
//     an off in a later request does not close the gate for that request.
//
// 3. Protected, but ending in _exit(137) instead of the deferred fatal.
//   - Displaced input_filter: a parse is still armed but has no per-variable
//     safe point, so a body within post_max_size can reach the hard limit.
//   - Constant cap: in extreme configurations the cap, not the body, sets the
//     hard limit.
//   - max_input_vars in the millions: hash-table growth can reach the hard
//     limit within post_max_size.
//   - filter.default: one that can expand values (anything but unset or
//     unsafe_raw) has no allowance in the hard limit, so a body within
//     post_max_size can reach it. One warning is logged per process (see
//     _warn_filter_default_once).
//   - Soft limit below usage (PHP 8.1.4+): user code can lower memory_limit
//     below its own usage (zend_set_memory_limit() can report success without
//     applying it while chunks are cached) and then call request_parse_body().
//     The hard limit computed from that soft limit can then be at or below the
//     usage.
//   - Request level, no safe point: growth inside one long internal call (one
//     parse_str() or array builder), or with no VM interrupt before the end
//     of the request, can reach max(L, S + 8 MiB) and end in _exit(137), as
//     can a single allocation that would cross it (upstream's hard limit
//     does the same). That is equal to upstream's L from 8 MiB, higher below
//     8 MiB (the 8 MiB term).
//   - Timeout at the same interrupt: when max_execution_time expires at the
//     interrupt that would deliver a pending error, the VM handles the
//     timeout (zend_timeout()) instead of calling zend_interrupt_function,
//     and no new interrupt is requested while the error stays pending. A
//     shutdown function that keeps allocating then runs under the hard limit
//     and can end in _exit(137) instead of a fatal error.
//   - Displaced interrupt hook: if zend_interrupt_function is replaced after
//     our startup hook without chaining to ours, a pending error is never
//     raised and no new interrupt is requested, so a request that keeps
//     allocating grows to the hard limit and ends in _exit(137), or the
//     error is dropped at our RSHUTDOWN (see Coverage: Displaced hooks).
//   - memory_limit lowered below usage (PHP 7.1-7.3, 7.4.0-7.4.20 and
//     8.0.0-8.0.7, whose ini_set() accepts such a value; later versions
//     refuse it): the new value becomes the soft limit as is, and the hard
//     limit computed from it can be at or below the usage, so the next
//     allocation ends in _exit(137) instead of the deferred fatal.
//   - Skipped RSHUTDOWN (PHP 7.x): a bailout in an RSHUTDOWN that runs before
//     ours skips ours, so the request stays armed through zend_deactivate().
//     zend_ini_deactivate() then restores a memory_limit raised at runtime
//     (7.1-7.3 and 7.4.0-7.4.20 write the engine limit unconditionally), and
//     our handlers adopt it as the soft limit (see _follow_limit). If usage,
//     held up by cached chunks, is above the resulting hard limit, the next
//     allocation ends in _exit(137), where stock PHP would fail only when the
//     heap grows. Accepted: it needs a bailout in another module's RSHUTDOWN,
//     a memory_limit raised at runtime and usage above that hard limit.
//
// 4. Protected, with observable differences from stock PHP.
//   - Part in progress: the variable or multipart file part being registered
//     at the crossing completes, overshooting within the hard limit. A file
//     part keeps its temp file and $_FILES entries, at up to about 11 copies
//     of its name (the multipart k).
//   - Skipped file parts: later file parts are skipped at
//     MULTIPART_EVENT_FILE_START, so no rfc1867 callback (session upload
//     progress, third-party ones) sees them. Session upload progress is also
//     off for the whole startup POST parse (but not inside
//     request_parse_body()).
//   - Unread body: after a crossing in a multipart parse, the read_post
//     wrapper reports the end of the body: the parser finishes only the data
//     already buffered (about 5 KiB) and the rest of the body is left unread.
//     The SAPI discards it (apache2handler) or closes or ignores the
//     connection (php-fpm, php-cgi). A keep-alive FastCGI connection to
//     php-fpm is not left with unread input: a deferred fatal from the startup
//     parse fails php_request_startup(), and fpm_main then force-closes the
//     connection (fcgi_finish_request(request, 1)) and exits the worker; one
//     from request_parse_body() leaves SG(post_read) at 0, so
//     sapi_deactivate_module() drains the rest of the body (see _read_post).
//   - Temp files and fds: handled as in stock PHP; the mitigation does not
//     change them.
//   - FrankenPHP last error: worker mode calls sapi_deactivate() at every
//     worker-request boundary with SG(sapi_started) set, so the
//     sapi_module.deactivate wrapper also clears PG(last_error_*) there, while
//     the worker script keeps running: error_get_last() in the worker script
//     does not see an error raised by the handled request. This is
//     memory-safe; recent FrankenPHP versions clear the last error at that
//     point themselves.
//   - Request level delivery: the error is raised at the next VM interrupt,
//     after the inner interrupt handlers (pcntl signal handlers, ddtrace);
//     code in between runs over memory_limit. A crossing in a module RINIT
//     that runs after ours is raised at the script's first interrupt
//     (EG(vm_interrupt) is reset only in init_executor(), before the RINITs),
//     where stock PHP would fail the request startup instead. An error pending
//     with no later interrupt (other modules' RSHUTDOWN, output handlers,
//     session write, the end of the script, after EG(active) = 0) is dropped:
//     the request succeeds within the hard limit. A timeout pending at the
//     same time wins (see Coverage: Timeout at the same interrupt).
//   - No limit after a dropped error: at our RSHUTDOWN the configured
//     memory_limit is restored with zend_set_memory_limit(), whose result is
//     not checked. With usage above it (mainly after a pending error was
//     dropped) it fails (PHP 7.4.21+, 8.1+) or, on 8.1.4+, may instead free
//     cached chunks without writing the limit; the engine then keeps the
//     sentinel and, with our handlers removed, nothing limits the rest of
//     shutdown (later RSHUTDOWNs, session write, output teardown) until
//     php_request_shutdown() resets the heap. On PHP 7.1-7.4.20 and 8.0 the
//     restore succeeds below usage instead, as php_error_cb()'s does in stock
//     PHP. (8.0.8+ checks only in zend_set_memory_limit_ex(), used by
//     ini_set(), not in zend_set_memory_limit().)
//   - Sentinel memory_limit: a memory_limit of -2 (PHP 7.0-8.1) or
//     18446744073709551614 (8.2+) set at runtime writes exactly the sentinel
//     engine limit, so our handlers do not see the change (see _keep_sentinel)
//     and the previous soft and hard limits stay in force, where stock PHP
//     would have no limit. Comparing PG(memory_limit) on every allocation
//     would catch it, at a cost on every allocation for these exotic values.
//   - Ordinary out-of-memory errors: whole-request arming applies to every
//     request on an unpatched runtime, so an application's own crossing of
//     memory_limit is deferred too, counted in the deferred metric
//     (phase:runtime), tagged on the span and the trace kept.
//
// 5. Not addressed.
//   - Unrelated stock-PHP bugs: those not about memory (e.g. the parse_str
//     user-handler use-after-free).
//   - Exotic extensions: compatibility beyond chaining what is captured at
//     startup.
//   - Extensions that need plain zend_mm: with our handlers installed for the
//     whole request, is_zend_mm() is false, so an extension that requires it
//     stops working. memprof's runtime memprof_enable() does not profile, and
//     MEMPROF_PROFILE=1 profiles only if memprof's RINIT runs before ours (it
//     then swaps in its own heap, and ours are installed on that one).
//   - Handlers reset after a bailout: when a bailout skipped our RSHUTDOWN,
//     post_deactivate resets the heap to plain zend_mm instead of restoring
//     the handlers found at install, so an extension below us that keeps its
//     handlers across requests loses them (see _settle_handlers). The
//     profiler installs its own again in its next RINIT.
//   - Request level performance: the profiler stacking and the cost of
//     whole-request arming are not yet validated.
//
// Non-goal: worker recycling. A worker ends only where the engine would end
// it: the hard-limit _exit(137), and a deferred E_ERROR from the startup parse
// on SAPIs that exit after a failed request startup (php-fpm, php-cgi), as the
// engine's own out-of-memory error there would. apache2handler keeps the
// process. A deferred error from request_parse_body() ends only the request.

#include "php2shell_mitigation.h"

#include <php.h>

#include "configuration.h"
#include "ddappsec.h" // TSRMLS_CACHE as local-dynamic: no STATIC_TLS
#include "logging.h"

#include "php2shell/p2s_monitor.h"
#include "php2shell/p2s_oom_guard.h"
#include "php2shell/p2s_policy.h"
#include "php2shell/p2s_request_parse_body.h"
#include "php2shell/p2s_runtime.h"
#include "php2shell/p2s_stale_state.h"
#include "php2shell/p2s_startup_parse.h"

// Set at the end of dd_php2shell_minit(); dd_php2shell_startup() installs the
// hooks only after it. Written during startup and in MSHUTDOWN only.
static bool _minit_done;

// ===========================================================================
// Public API
// ===========================================================================

void dd_php2shell_minit(void)
{
    dd_p2s_policy_minit();
    dd_p2s_guard_minit();
    _minit_done = true;
#ifdef TESTING
    // Test-only functions, registered only with datadog.appsec.testing on.
    if (get_global_DD_APPSEC_TESTING()) {
        dd_p2s_startup_parse_register_testing(); // status
        dd_p2s_stale_state_register_testing();   // post_deactivate_reset
        dd_p2s_policy_register_testing();        // set_allowed
        dd_p2s_runtime_register_testing();       // request_status
    }
#endif
}

// ---- hooks -----------------------------------------------------------------
//
// Each slot is captured once, from the zend_extension startup hook (after every
// module MINIT), and never written again until MSHUTDOWN. The saved pointers,
// kept by the unit that owns each slot, are written only then
// (single-threaded) and read unsynchronised at request time.
//
// Capture the hook slots and put our wrappers in them, unit by unit. Called
// once, from the zend_extension startup hook: every module MINIT has run, so a
// handler registered in any of them (mbstring's treat_data,
// ext/filter's input_filter, even when loaded after ddappsec) is captured, and
// nothing runs concurrently. Each slot that is not NULL and does not already
// hold our wrapper is saved once and gets our wrapper; a second call, or
// statics surviving a module restart, therefore never capture our own wrapper.
// Nothing writes the slots again until MSHUTDOWN. The wrappers are installed
// whatever the mode: the mode is resolved lazily (php-fpm applies pool env[]
// after startup), and an inactive wrapper only forwards.
void dd_php2shell_startup(void)
{
    // Runs from the zend_extension startup hook, i.e. in
    // zend_startup_extensions(), after zend_startup_modules() ran every module
    // MINIT, and before zend_post_startup() copies the function tables for ZTS.
    if (_minit_done && dd_p2s_policy_startup_may_hook()) {
        dd_p2s_startup_parse_install();
        dd_p2s_stale_state_install();
        dd_p2s_request_parse_body_install();
        dd_p2s_runtime_install();
        dd_p2s_monitor_install();
        dd_p2s_policy_hooks_installed();
    }
}

void dd_php2shell_mshutdown(void)
{
    // Leave no pointer to our wrappers in the process-global slots: the DSO
    // may be unmapped after MSHUTDOWN. A slot that still holds our wrapper gets
    // a handler that is valid whichever modules are still loaded; a slot
    // overwritten by another module is left alone. The saved pointers are
    // cleared so that statics surviving a restart capture again.
    dd_p2s_guard_mshutdown();
    dd_p2s_startup_parse_uninstall();
    dd_p2s_stale_state_uninstall();
    dd_p2s_request_parse_body_uninstall();
    dd_p2s_runtime_uninstall();
    dd_p2s_monitor_uninstall();
    dd_p2s_policy_mshutdown();
    _minit_done = false;
}

void dd_php2shell_set_allowed(bool allowed)
{
    dd_p2s_policy_set_allowed(allowed);
}

void dd_php2shell_set_waf_decision(const char *nonnull value, size_t len)
{
    dd_p2s_decision decision;
    if (!dd_p2s_policy_parse_decision(value, len, &decision)) {
        mlog(dd_log_warning,
            "PHP2Shell mitigation: unknown WAF decision '%.*s', ignored",
            (int)MIN(len, INT_MAX), value);
        return;
    }
    dd_p2s_policy_set_decision(decision);
}

const char *nullable dd_php2shell_vulnerable_value(void)
{
    return dd_p2s_policy_vulnerable_value();
}

void dd_php2shell_rinit(void) { dd_p2s_runtime_rinit(); }

void dd_php2shell_rshutdown(void) { dd_p2s_runtime_rshutdown(); }

// One check and one MINFO row per hook (see dd_p2s_hook_check_displaced): the
// displacement check runs in post_deactivate, in the order treat_data,
// input_filter, deactivate, request_parse_body(), rfc1867, read_post,
// zend_interrupt_function, gc_mem_caches(), zend_error_cb. The deactivate,
// rfc1867, zend_interrupt_function and zend_error_cb slots are captured
// whenever dd_php2shell_startup() installed the hooks, even if they held NULL;
// request_parse_body() and gc_mem_caches() count as not captured once they are
// gone (disable_functions). Relaxed loads only.
void dd_php2shell_minfo(void)
{
    dd_p2s_policy_minfo();
    // The hook rows only while the mitigation is active; when inactive,
    // whether the hooks are installed is not reported.
    if (!dd_p2s_policy_active()) {
        return;
    }
    // Read-only: each hook row compares the live slot with our wrapper.
    dd_p2s_startup_parse_minfo_treat_data();
    dd_p2s_startup_parse_minfo_input_filter();
    dd_p2s_startup_parse_minfo_rfc1867();
    dd_p2s_stale_state_minfo_deactivate();
    dd_p2s_startup_parse_minfo_read_post();
    dd_p2s_request_parse_body_minfo();
    dd_p2s_runtime_minfo_interrupt();
    dd_p2s_runtime_minfo_gc_mem_caches();
    dd_p2s_monitor_minfo_error_cb();
}

void dd_php2shell_post_deactivate(void)
{
    // Fallback for a skipped RSHUTDOWN: on 7.x, zend_deactivate_modules()
    // wraps the whole RSHUTDOWN loop in one zend_try, so a bailout in a module
    // whose RSHUTDOWN runs before ours skips ours; on 8.x, a bailout in the
    // RINIT of a later module runs no RSHUTDOWN. Before the gate, which may
    // have closed during the request, and before shutdown_memory_manager(),
    // which below PHP 8.4 does not reset a heap with custom handlers.
    dd_p2s_guard_settle();
    if (!dd_p2s_policy_active()) {
        return;
    }
    dd_p2s_startup_parse_check_treat_data();
    dd_p2s_startup_parse_check_input_filter();
    dd_p2s_stale_state_check_deactivate();
    dd_p2s_request_parse_body_check();
    dd_p2s_startup_parse_check_rfc1867();
    dd_p2s_startup_parse_check_read_post();
    dd_p2s_runtime_check_interrupt();
    dd_p2s_runtime_check_gc_mem_caches();
    dd_p2s_monitor_check_error_cb();
    dd_p2s_stale_state_post_deactivate();
}
