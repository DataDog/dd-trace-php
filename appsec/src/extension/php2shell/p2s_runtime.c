// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// Request level. On PHP 7.1+, whenever the mitigation is active, the heap is
// also armed for the request itself: our Zend MM handlers are installed at the
// end of appsec's RINIT and removed at the start of appsec's RSHUTDOWN, only if
// the slot still holds them (see dd_p2s_runtime_rinit). That is LIFO with the
// profiler, which installs in its RINIT and removes in its RSHUTDOWN, in
// either module order. Between the two, the slot is never written; only the
// bools change. If another extension's handlers are on top at our RSHUTDOWN,
// they chain to ours: the slot is left alone and the handlers stay in the
// chain, so later windows and RINITs only arm them (see _remove_handlers).
// How post_deactivate and MSHUTDOWN settle them, and how they are armed again,
// is described at the top of p2s_oom_guard.c (_remove_handlers,
// _request_hard_limit and _follow_limit are there too). A startup window,
// before RINIT, installs and removes its own handlers and hands nothing over;
// a request_parse_body() window nests inside the request level, with its own
// limits, and installs nothing (see
// dd_p2s_guard_window_begin). Outside a window, S is memory_limit and the hard
// limit is max(L, S + 8 MiB), with L upstream's limit (see
// _request_hard_limit). A soft-limit crossing outside a window makes the error
// pending and sets EG(vm_interrupt); the error is raised at the next VM
// interrupt, after the inner interrupt handlers ran (see _interrupt), as
// max_execution_time is delivered; the handlers then stay installed and only
// forward. While armed, our handlers put the engine back at the sentinel
// whenever they find it elsewhere: php_error_cb() restores memory_limit before
// every bailing fatal, and ini_set() writes the engine limit. A changed
// memory_limit then becomes the new S, however it was changed (see
// _follow_limit). Below PHP 8.4, zend_mm_gc() frees nothing on a heap with
// custom handlers, so while ours are in the chain the gc_mem_caches() wrapper
// turns use_custom_heap off around the original (see _gc_mem_caches). An error
// still pending at RSHUTDOWN is dropped. This covers our RINIT to our
// RSHUTDOWN, which includes all user code (shutdown functions and destructors
// run before any RSHUTDOWN); see Coverage: Outside RINIT..RSHUTDOWN. In
// FrankenPHP worker mode one PHP request spans every HTTP request, so the
// request level also follows each HTTP request's WAF decision when that
// request starts (see dd_p2s_runtime_user_req_begin): it is opened, armed again
// or disarmed there, and the handlers stay until the worker's RSHUTDOWN. PHP
// 7.0, without EG(vm_interrupt), has only the windows.

#include "p2s_runtime.h"

#include <Zend/zend_API.h>
#include <php.h>
#include <stdatomic.h>

#include "../compatibility.h"
#include "../configuration.h"
#include "../ddappsec.h" // TSRMLS_CACHE as local-dynamic: no STATIC_TLS
#include "../logging.h"
#include "../php_compat.h" // NOLINT (must come before any *_arginfo.h)
#include "../php_objects.h"
#include "p2s_hook.h"
#include "p2s_oom_guard.h"
#include "p2s_policy.h"

// Below PHP 8.4, zend_mm_gc() frees nothing on a heap with custom handlers, so
// gc_mem_caches() would free nothing while the request level keeps ours
// installed (8.4+ forwards to the gc handler, see _dd_mm_gc). PHP 7.0 installs
// them only in the startup windows, where no user code runs.
#if defined(DD_PHP2SHELL_HAVE_INTERRUPT) && PHP_VERSION_ID < 80400
#    define DD_PHP2SHELL_WRAP_GC_MEM_CACHES 1
#endif

#ifdef DD_PHP2SHELL_WRAP_GC_MEM_CACHES
// The handler of gc_mem_caches() we replaced (see _gc_mem_caches), kept like
// _request_parse_body_orig (p2s_request_parse_body.c).
static zif_handler _gc_mem_caches_orig;
#endif
#ifdef DD_PHP2SHELL_HAVE_INTERRUPT
// The zend_interrupt_function we chain to (pcntl, the profiler and ddtrace set
// theirs in MINIT; ours is installed after them), or NULL.
static void (*_interrupt_inner)(zend_execute_data *execute_data);
#endif
#ifdef DD_PHP2SHELL_HAVE_INTERRUPT
// Whether dd_p2s_runtime_install() ran (zend_interrupt_function is captured
// even when it held NULL). Written during startup and in MSHUTDOWN only.
static bool _interrupt_captured;
// Whether the displacement of each slot of this unit was logged; process-wide.
static atomic_bool _displaced_logged_interrupt;
#endif
#ifdef DD_PHP2SHELL_WRAP_GC_MEM_CACHES
static atomic_bool _displaced_logged_gc_mem_caches;
#endif

#ifdef DD_PHP2SHELL_HAVE_INTERRUPT
static void _interrupt(zend_execute_data *execute_data);
#endif
#ifdef DD_PHP2SHELL_WRAP_GC_MEM_CACHES
static ZEND_NAMED_FUNCTION(_gc_mem_caches);
#endif
#ifdef TESTING
static PHP_FUNCTION(datadog_appsec_testing_php2shell_request_status);
#endif

// Called once, from the zend_extension startup hook (dd_php2shell_startup(),
// after every module MINIT), when the policy allows hooking.
void dd_p2s_runtime_install(void)
{
#ifdef DD_PHP2SHELL_HAVE_INTERRUPT
    _interrupt_captured = true;
    // The request level delivers the deferred error at a VM interrupt. pcntl,
    // the profiler and ddtrace set theirs in MINIT, so ours is the outermost.
    if (zend_interrupt_function != _interrupt) {
        _interrupt_inner = zend_interrupt_function;
        zend_interrupt_function = _interrupt;
    }
#endif

#ifdef DD_PHP2SHELL_WRAP_GC_MEM_CACHES
    // gc_mem_caches() is a Zend core function, so it exists by now; as for
    // request_parse_body(), disable_functions runs after this and only the
    // handler is saved. The profiler wraps it from its own startup hook too,
    // before or after us.
    zend_internal_function *gc_fn =
        dd_p2s_live_function(ZEND_STRL("gc_mem_caches"));
    ZEND_ASSERT(gc_fn != NULL);
    if (gc_fn != NULL && gc_fn->handler != _gc_mem_caches) {
        _gc_mem_caches_orig = gc_fn->handler;
        gc_fn->handler = _gc_mem_caches;
    }
#endif
}

// Called from MSHUTDOWN (dd_php2shell_mshutdown()), after the guard has reset
// the heap's handlers.
void dd_p2s_runtime_uninstall(void)
{
#ifdef DD_PHP2SHELL_HAVE_INTERRUPT
    DD_P2S_SLOT_RESTORE(zend_interrupt_function, _interrupt, _interrupt_inner);
    _interrupt_inner = NULL;
    _interrupt_captured = false;
    atomic_store(&_displaced_logged_interrupt, false);
#endif
#ifdef DD_PHP2SHELL_WRAP_GC_MEM_CACHES
    // The function table is still alive here (zend_shutdown() destroys it
    // after the modules); a function disable_functions removed is left alone.
    zend_internal_function *gc_fn =
        dd_p2s_live_function(ZEND_STRL("gc_mem_caches"));
    if (gc_fn != NULL && gc_fn->handler == _gc_mem_caches) {
        gc_fn->handler = _gc_mem_caches_orig;
    }
    _gc_mem_caches_orig = NULL;
    atomic_store(&_displaced_logged_gc_mem_caches, false);
#endif
}

// Called at the end of appsec's RINIT (dd_php2shell_rinit()), after remote
// config has been applied.
void dd_p2s_runtime_rinit(void)
{
#ifdef DD_PHP2SHELL_HAVE_INTERRUPT
    // The gate is read here, after remote config and this request's WAF
    // decision (request_init) had their say in this RINIT.
    // Startup windows removed their handlers when they ended, so the slot
    // holds what the profiler (or nobody) put there; or our handlers are
    // still chained under another extension's since an earlier request, and
    // are only armed (see _ensure_handlers in p2s_oom_guard.c).
    if (!dd_p2s_policy_armed() || !dd_p2s_guard_available()) {
        return;
    }
    dd_p2s_guard_open();
#endif
}

// Called at the start of each HTTP request in FrankenPHP worker mode
// (dd_php2shell_user_req_begin()), after its request_init applied the WAF
// decision. Opens the request level if RINIT did not; otherwise only flags
// change (see dd_p2s_guard_reconcile). The handlers stay until the worker's
// RSHUTDOWN.
void dd_p2s_runtime_user_req_begin(void)
{
#ifdef DD_PHP2SHELL_HAVE_INTERRUPT
    if (!dd_p2s_guard_available()) {
        return;
    }
    dd_p2s_guard_reconcile(dd_p2s_policy_armed());
#endif
}

// Called first in appsec's RSHUTDOWN (dd_php2shell_rshutdown()), mirroring
// dd_p2s_runtime_rinit().
void dd_p2s_runtime_rshutdown(void)
{
    // Before appsec's own RSHUTDOWN, which sets the engine limit directly,
    // and before zend_ini_deactivate() restores a runtime-changed
    // memory_limit. An error still pending is dropped (see Coverage: Request
    // level delivery).
    dd_p2s_guard_close();
}

void dd_p2s_runtime_check_interrupt(void)
{
#ifdef DD_PHP2SHELL_HAVE_INTERRUPT
    dd_p2s_hook_check_displaced(_interrupt_captured,
        __atomic_load_n(&zend_interrupt_function, __ATOMIC_RELAXED) ==
            _interrupt,
        &_displaced_logged_interrupt, "zend_interrupt_function",
        "if it does not chain to ours, runtime out-of-memory errors are "
        "not delivered before the hard limit");
#endif
}

void dd_p2s_runtime_check_gc_mem_caches(void)
{
#ifdef DD_PHP2SHELL_WRAP_GC_MEM_CACHES
    zif_handler gmc = dd_p2s_live_handler(ZEND_STRL("gc_mem_caches"));
    dd_p2s_hook_check_displaced(_gc_mem_caches_orig != NULL && gmc != NULL,
        gmc == _gc_mem_caches, &_displaced_logged_gc_mem_caches,
        "gc_mem_caches()", "if it does not chain to ours, it may free nothing");
#endif
}

void dd_p2s_runtime_minfo_interrupt(void)
{
#ifdef DD_PHP2SHELL_HAVE_INTERRUPT
    dd_p2s_hook_minfo_row("PHP2Shell zend_interrupt_function hook",
        _interrupt_captured,
        __atomic_load_n(&zend_interrupt_function, __ATOMIC_RELAXED) ==
            _interrupt);
#endif
}

void dd_p2s_runtime_minfo_gc_mem_caches(void)
{
#ifdef DD_PHP2SHELL_WRAP_GC_MEM_CACHES
    zif_handler gmc = dd_p2s_live_handler(ZEND_STRL("gc_mem_caches"));
    dd_p2s_hook_minfo_row("PHP2Shell gc_mem_caches() hook",
        _gc_mem_caches_orig != NULL && gmc != NULL, gmc == _gc_mem_caches);
#endif
}

#ifdef DD_PHP2SHELL_HAVE_INTERRUPT
// The zend_interrupt_function wrapper: chain first, so the inner handlers
// (pcntl signals, ddtrace, the profiler) see every interrupt, then raise the
// error a crossing outside a window left pending (see
// dd_p2s_guard_request_interrupt), if it still is. Code an inner handler runs
// allocates under the hard limit until the raise. The VM calls it from its
// interrupt helper, a safe point between opcodes.
static void _interrupt(zend_execute_data *execute_data)
{
    if (_interrupt_inner != NULL) {
        _interrupt_inner(execute_data);
    }
    if (UNEXPECTED(dd_p2s_guard_error_pending() && !dd_p2s_guard_in_window())) {
        // Not inside the tracer's sandbox (EH_THROW), around its hook
        // closures: it would swallow the bailout, or, from a begin hook, leave
        // the hooked call's span open (dropped at shutdown). Later allocations
        // ask for the interrupt again (see _check_limit).
        if (EG(error_handling) == EH_THROW) {
            dd_p2s_guard_raise_refused();
            return;
        }
        dd_p2s_guard_raise_deferred_oom(DD_PHASE_RUNTIME, 0);
    }
}
#endif

#ifdef DD_PHP2SHELL_WRAP_GC_MEM_CACHES
// The gc_mem_caches() wrapper (PHP 7.1-8.3). The original calls zend_mm_gc(),
// which frees nothing on a heap with custom handlers. While our handlers are in
// the chain of the current heap (see dd_p2s_guard_in_chain), use_custom_heap is
// turned off for the call and then put back as found, as _run_gc
// (p2s_oom_guard.c) does; the profiler's wrapper does the same, and nested,
// each restores the value it found. With arguments, the original only reports
// the error: forwarded as is, so that no user error handler runs with the flag
// off.
static ZEND_NAMED_FUNCTION(_gc_mem_caches)
{
    zend_mm_heap *heap = zend_mm_get_heap();
    if (!dd_p2s_guard_in_chain(heap) || ZEND_NUM_ARGS() != 0) {
        _gc_mem_caches_orig(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }
    int saved = dd_p2s_heap_disable_custom(heap);
    _gc_mem_caches_orig(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_p2s_heap_restore_custom(heap, saved);
}
#endif

// ---- testing functions -----------------------------------------------------

#ifdef TESTING
// Test-only, registered only with datadog.appsec.testing on (on every
// version): request_status() observes the request-level arming, which no real
// request can.
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    _php2shell_request_status_arginfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

// clang-format off
static const zend_function_entry _php2shell_runtime_testing_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "php2shell_mitigation_request_status", PHP_FN(datadog_appsec_testing_php2shell_request_status), _php2shell_request_status_arginfo, 0, NULL, NULL)
    PHP_FE_END
};
// clang-format on

void dd_p2s_runtime_register_testing(void)
{
    dd_phpobj_reg_funcs(_php2shell_runtime_testing_functions);
}

// The request-level arming: whether the whole request is
// armed, whether an error is pending, and the current limits (-1 above
// ZEND_LONG_MAX).
static PHP_FUNCTION(datadog_appsec_testing_php2shell_request_status)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }
    // Each field is read where it is reported: array_init() and
    // add_assoc_bool() allocate through the handlers and can set pending.
    dd_p2s_guard_status st;
    dd_p2s_guard_testing_status(&st);
    size_t soft = st.soft;
    size_t hard = st.hard;
    array_init(return_value);
    dd_p2s_guard_testing_status(&st);
    add_assoc_bool(return_value, "armed", st.armed_request);
    dd_p2s_guard_testing_status(&st);
    add_assoc_bool(return_value, "pending", st.pending);
    add_assoc_long(return_value, "soft_limit",
        soft > (size_t)ZEND_LONG_MAX ? -1 : (zend_long)soft);
    add_assoc_long(return_value, "hard_limit",
        hard > (size_t)ZEND_LONG_MAX ? -1 : (zend_long)hard);
}
#endif // TESTING
