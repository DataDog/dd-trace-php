// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// The emulation is armed only on a real zend_mm heap with a finite
// memory_limit. While armed, our Zend MM handlers serve every allocation and
// the engine's own limit is raised to a sentinel, so no insertion is
// abandoned. They chain over any custom handlers already present: the
// profiler installs its own in RINIT, so the request level may find them, but
// a startup window never does.
// Usage is read from zend_mm (real_size), so a chained allocator is accounted
// for as long as it allocates from the zend_mm heap, as the profiler does.
// After each allocation:
//   - above the soft limit (after a GC attempt), the out-of-memory error
//     becomes pending, the input_filter wrapper drops every later variable
//     of the parse and the rfc1867 callback wrapper skips every later file
//     part, so growth stops with the variable or file part in flight; in a
//     multipart parse, the read_post wrapper also reports the end of the
//     body, so a header section in progress stops growing (see _read_post in
//     p2s_startup_parse.c);
//   - above the hard limit, the process ends with _exit(137). A fresh huge
//     allocation that would cross it ends the process before it reaches the
//     OS.
// When the parse returns (a safe point: the arrays are complete), a pending
// error is raised as E_ERROR, under a finite engine limit (the larger of S and
// usage + 8 MiB, see _set_finite_limit). Another fatal inside the window (e.g.
// an allocation the OS refuses) leaves under the same finite limit before its
// bailout continues.
//
// Request level (see p2s_runtime.c): our handlers, kept in the chain under
// another extension's at our RSHUTDOWN, are settled from the slot in
// post_deactivate, after every RSHUTDOWN: if it holds ours (a bailout skipped
// our RSHUTDOWN, or the extension on top restored ours), the heap goes back to
// plain zend_mm (see _settle_handlers).
// MSHUTDOWN does the same when a failed request startup skipped both (see
// _reset_handlers_at_mshutdown). Before arming handlers kept in the chain,
// the slot is checked again; if it shows they left it, they are installed
// afresh (see _ensure_handlers).

#include "p2s_oom_guard.h"

#include <php.h>
#include <stdatomic.h>
#include <stddef.h> // offsetof
#include <stdio.h>
#include <stdlib.h> // getenv
#include <string.h> // memcpy
#include <unistd.h> // _exit

#include "../compatibility.h"
#include "../ddappsec.h" // TSRMLS_CACHE as local-dynamic: no STATIC_TLS
#include "../ddtrace.h"  // datadog_metric_* runtime pointers
#include "../logging.h"
#include "../php_compat.h" // zend_string_init_interned (PHP < 7.2)
#include "../telemetry.h"  // dd_telemetry_add_metric
#include "p2s_policy.h"

// The engine's real limit while the mitigation is armed. Effectively disables
// the engine's own enforcement so that this file is the sole authority on
// soft/hard limits. zend_mm's checks (`size > limit -
// real_size`; 7.0: `real_size + size > limit`) hold with it on every version.
// Not upstream's ZEND_MM_LIMIT_MAX (PHP_INT_MAX), which a memory_limit of
// PHP_INT_MAX writes as is: an engine limit equal to the sentinel is not seen
// as moved (see _keep_sentinel). This one only a memory_limit of -2 (PHP
// 7.0-8.1) or 18446744073709551614 (8.2+) writes; set at runtime, such a value
// is not seen as a change and the previous limits stay in force (see Coverage:
// Sentinel memory_limit).
#define DD_PHP2SHELL_SENTINEL_LIMIT (SIZE_MAX - 1)

enum {
    // Process exit status for the hard-limit abort; mirrors upstream
    // _exit(137).
    DD_HARD_EXIT_STATUS = 137,
    // Size of the stack buffer holding the "mode:<...>,phase:<...>" telemetry
    // tags (the longest is "mode:auto,phase:request_parse_body").
    DD_METRIC_TAG_BUFSIZE = 64,
};

// Whether _disarm() also restores the engine's configured memory_limit.
typedef enum {
    // Leave the sentinel limit in place: the caller is about to raise the
    // deferred fatal, _exit, or otherwise does not want a tight limit to bail
    // the imminent allocations; it restores the real limit itself if needed.
    DD_DISARM_KEEP_LIMIT = 0,
    // Restore the configured memory_limit now (the ordinary, parse-succeeded
    // path).
    DD_DISARM_RESTORE_LIMIT,
} dd_disarm_mode;

// Whether the engine's heap is a real zend_mm heap, i.e. the process was not
// started with USE_ZEND_ALLOC=0 (which replaces zend_mm with a malloc()-backed
// stub). This holds regardless of custom handlers installed on top of it
// (e.g. the profiler's), which is why is_zend_mm() cannot answer it.
static bool _heap_is_zend_mm = true;
// Whether finding our Zend MM handlers replaced at removal was logged; once per
// process (see _remove_handlers).
static atomic_bool _handlers_replaced_warned;

// Telemetry: two COUNT metrics, interned once in MINIT. Both are emitted at
// points the tracer's own request-finish flush never reaches (the request dies
// in startup / the worker _exit()s), so after appending the point we flush it
// SYNCHRONOUSLY via datadog_telemetry_commit_metrics() -- a blocking IPC write
// to the sidecar process, which survives the worker. See _emit_and_flush,
// including its note on the sidecar application registration.
#define DD_METRIC_DEFERRED "appsec.php2shell.soft_limit_deferred"
#define DD_METRIC_HARD_EXIT "appsec.php2shell.hard_limit_exit"
static zend_string *nullable _metric_deferred_name;
static zend_string *nullable _metric_hard_exit_name;

// ---- forward declarations (implementation details) ------------------------

static size_t _request_hard_limit(size_t soft);
static bool _zend_alloc_disabled(void);
static const char *_phase_name(dd_deferred_phase phase);
static void _set_finite_limit(void);
static bool _ensure_handlers(void);
static bool _install_handlers(void);
static void _remove_handlers(void);
static void _reset_handlers_at_mshutdown(void);
static void _settle_handlers(void);
static bool _handlers_ours(zend_mm_heap *nonnull heap);
static bool _handlers_ours_flag_off(zend_mm_heap *nonnull heap);
static void _request_end(void);
static void _forget_request(void);
static void _arm(size_t soft, size_t hard);
static void _disarm(dd_disarm_mode mode);
static void _set_handlers(zend_mm_heap *nonnull heap, bool ours);
static void _set_plain_handlers(zend_mm_heap *nonnull heap);
static size_t _heap_limit(zend_mm_heap *nonnull heap);
static zend_always_inline void _keep_sentinel(void);
static ZEND_COLD void _follow_limit(void);
static size_t _run_gc(void);
static void _precheck_hard_limit(size_t size);
static bool _exceeds_hard(size_t used, size_t size);
static void _check_limit(size_t add_size);
static size_t _usage(void);
static void _request_interrupt(void);
static ZEND_COLD ZEND_NORETURN void _hard_abort(size_t add_size);
static void _emit_and_flush(
    zend_string *nullable name, const char *nullable phase);

// ---- per-request / per-thread arming state ---------------------------------
//
// Our Zend MM handlers are installed by a startup window (a single synchronous
// treat_data() before RINIT, see dd_p2s_startup_parse_run_window), which
// removes them when it ends, or, on PHP 7.1+, at the end of appsec's RINIT for
// the request, removed at the start of appsec's RSHUTDOWN (see
// dd_php2shell_rinit). Between install and removal the slot is never written:
// only the bools change. A request_parse_body() window nests inside the request
// level (see dd_p2s_guard_window_begin). The allocator-facing fields
// (heap/prev_*) are only used between _install_handlers() and
// _remove_handlers(); `soft` and `hard` are deliberately read AFTER _disarm()
// too (the deferred-fatal message and the hard-abort message use them). It is
// thread-local for ZTS; the allocator handlers (plain C function pointers that
// cannot carry context) read it.

#if PHP_VERSION_ID >= 80400
typedef void *(*dd_mm_alloc_fn)(
    size_t ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC);
typedef void (*dd_mm_free_fn)(void *ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC);
typedef void *(*dd_mm_realloc_fn)(
    void *, size_t ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC);
typedef size_t (*dd_mm_gc_fn)(void);
typedef void (*dd_mm_shutdown_fn)(bool, bool);
#else
typedef void *(*dd_mm_alloc_fn)(size_t);
typedef void (*dd_mm_free_fn)(void *);
typedef void *(*dd_mm_realloc_fn)(void *, size_t);
#endif

ZEND_TLS struct {
    // Our handlers are in the chain of `heap` (see _install_handlers): in
    // the slot, or under another extension's handlers that chain to them.
    // Cleared only when the slot shows they are not (see _remove_handlers,
    // _settle_handlers, _ensure_handlers, _reset_handlers_at_mshutdown);
    // while set, nothing installs them again.
    bool installed;
    // They were installed at appsec's RINIT, for the request (see
    // dd_php2shell_rinit), rather than by a startup window.
    bool request;
    // The engine sits at the sentinel and our handlers enforce the limits.
    // Without it, installed handlers only forward.
    bool armed;
    // Inside an armed parse window (see dd_p2s_startup_parse_run_window).
    bool window;
    bool pending;
    bool had_prev;
    size_t soft;
    size_t hard;
    // PG(memory_limit) when `soft` was last computed from it; a different
    // value means memory_limit changed since (see _follow_limit).
    zend_long memory_limit;
    zend_mm_heap *heap;
    dd_mm_alloc_fn prev_malloc;
    dd_mm_free_fn prev_free;
    dd_mm_realloc_fn prev_realloc;
#if PHP_VERSION_ID >= 80400
    dd_mm_gc_fn prev_gc;
    dd_mm_shutdown_fn prev_shutdown;
#endif
} _arm_state;

// ===========================================================================
// Public API
// ===========================================================================

void dd_p2s_guard_minit(void)
{
    _heap_is_zend_mm = !_zend_alloc_disabled();
    _metric_deferred_name =
        zend_string_init_interned(ZEND_STRL(DD_METRIC_DEFERRED), 1);
    _metric_hard_exit_name =
        zend_string_init_interned(ZEND_STRL(DD_METRIC_HARD_EXIT), 1);
}

void dd_p2s_guard_mshutdown(void) { _reset_handlers_at_mshutdown(); }

bool dd_p2s_guard_available(void) { return _heap_is_zend_mm; }

// The soft limit the emulation enforces: the configured memory_limit, or no
// limit at all (SIZE_MAX) when it is non-positive ("unlimited"). It is at least
// one chunk (ZEND_MM_CHUNK_SIZE): PHP 7.x and 8.0 enforce at least that much
// when memory_limit is lower, and the heap already uses one chunk when the
// parse starts.
size_t dd_p2s_guard_soft_limit(void)
{
    zend_long limit = (zend_long)PG(memory_limit);
    return limit > 0 ? MAX((size_t)limit, ZEND_MM_CHUNK_SIZE) : SIZE_MAX;
}

// Upstream formula: 200% of memory_limit, or memory_limit + 1 GiB when
// memory_limit >= 1 GiB. Saturates for "no limit" (see
// dd_p2s_guard_soft_limit).
size_t dd_p2s_guard_hard_limit_floor(size_t soft)
{
    if (soft > SIZE_MAX - DD_ONE_GIB) {
        return SIZE_MAX;
    }
    if (soft >= DD_ONE_GIB) {
        return soft + DD_ONE_GIB;
    }
    return soft * 2;
}

bool dd_p2s_guard_in_window(void) { return _arm_state.window; }

bool dd_p2s_guard_error_pending(void)
{
    return _arm_state.armed && _arm_state.pending;
}

// Arm for a window with soft limit `soft` and hard limit `hard`; false if it
// could not arm. A startup window (before RINIT) installs its own handlers,
// removed when it ends. A window inside the request (request_parse_body()
// after appsec's RINIT) installs nothing: the request-level handlers are
// there, and installing again would capture them as the previous ones. Nor
// does a startup window whose handlers are still chained under another
// extension's since an earlier request (see _remove_handlers): it only arms
// them, unless the slot shows they are out of the chain (see
// _ensure_handlers). If the
// request level is armed only the limits change, and a pending error carried
// in is kept, so the window drops everything and raises at its end; if a
// deferred raise disarmed it, the window arms again for its duration.
// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
bool dd_p2s_guard_window_begin(size_t soft, size_t hard, bool *nonnull nested)
{
    *nested = _arm_state.request && _arm_state.armed;
    if (!_arm_state.armed) {
        if (!_ensure_handlers()) {
            return false;
        }
        _arm(soft, hard);
    } else {
        _arm_state.soft = soft;
        _arm_state.hard = hard;
        _arm_state.memory_limit = (zend_long)PG(memory_limit);
    }
    _arm_state.window = true;
    return true;
}

// End an armed window that did not raise. With `nested` (the request
// level was armed when the window began), back to the request-level limits;
// otherwise disarm and restore memory_limit, which also removes the handlers
// of a startup window.
void dd_p2s_guard_window_end(bool nested)
{
    _arm_state.window = false;
    if (nested) {
        _arm_state.memory_limit = (zend_long)PG(memory_limit);
        _arm_state.soft = dd_p2s_guard_soft_limit();
        _arm_state.hard = _request_hard_limit(_arm_state.soft);
        return;
    }
    _disarm(DD_DISARM_RESTORE_LIMIT);
}

// Stop enforcing the window's limits before raising its deferred error: the
// engine stays on the sentinel (see dd_p2s_guard_raise).
void dd_p2s_guard_window_stop(void) { _disarm(DD_DISARM_KEEP_LIMIT); }

// Stop enforcing the window's limits on a bailout from its parse, and leave
// the sentinel for a finite limit (see _set_finite_limit).
void dd_p2s_guard_window_abort(void)
{
    _disarm(DD_DISARM_KEEP_LIMIT);
    _set_finite_limit();
}

// Raise the deferred out-of-memory error. Called only at a safe point: after
// the parse of a window returned (see dd_p2s_startup_parse_run_window), with
// the window's phase; otherwise at a VM interrupt (see _interrupt), with
// DD_PHASE_RUNTIME. Either way the request runs unarmed afterwards:
// request-level handlers stay installed and only forward until RSHUTDOWN.
// NOLINTBEGIN(bugprone-easily-swappable-parameters)
ZEND_COLD ZEND_NORETURN void dd_p2s_guard_raise(
    dd_deferred_phase phase, size_t dropped)
// NOLINTEND(bugprone-easily-swappable-parameters)
{
    // Stop the checks; a startup window also removes its handlers. The engine
    // is still on the sentinel limit.
    _disarm(DD_DISARM_KEEP_LIMIT);
    _arm_state.pending = false;
    // Leave the sentinel before anything else: the log line below can be
    // displayed, and output can run user code (an output buffer callback);
    // after a deferral in a runtime request_parse_body(), user shutdown
    // functions also run before request shutdown restores memory_limit.
    _set_finite_limit();
    // From treat_data, the request is about to die in startup via the E_ERROR
    // below without reaching module RSHUTDOWN, so the tracer's request-finish
    // flush never runs. Flush synchronously now so the point reaches the
    // sidecar regardless (from request_parse_body() the flush is merely early).
    const char *phase_name = _phase_name(phase);
    _emit_and_flush(_metric_deferred_name, phase_name);
    if (phase != DD_PHASE_RUNTIME) {
        mlog(dd_log_warning,
            "PHP2Shell mitigation: request data parse exceeded memory_limit "
            "(%zu bytes); dropped %zu request variables; raising deferred "
            "out-of-memory error at a safe point (phase:%s)",
            _arm_state.soft, dropped, phase_name);
    } else {
        mlog(dd_log_warning,
            "PHP2Shell mitigation: allocation after request startup exceeded "
            "memory_limit (%zu bytes); raising deferred out-of-memory error "
            "at a VM interrupt (phase:%s)",
            _arm_state.soft, phase_name);
    }
    zend_error_noreturn(E_ERROR,
        "Allowed memory size of %zu bytes exhausted (Datadog AppSec PHP2Shell "
        "mitigation deferred this to avoid heap corruption)",
        _arm_state.soft);
}

// Install (or arm already chained) handlers for the whole request, from
// appsec's RINIT; the caller has checked the gate and dd_p2s_guard_available().
void dd_p2s_guard_open(void)
{
    if (!_ensure_handlers()) {
        return;
    }
    _arm_state.request = true;
    // Armed even with no memory_limit (soft SIZE_MAX), so that a later
    // ini_set() can tighten it (see _follow_limit).
    size_t soft = dd_p2s_guard_soft_limit();
    _arm(soft, _request_hard_limit(soft));
}

void dd_p2s_guard_close(void) { _request_end(); }

void dd_p2s_guard_settle(void)
{
    _disarm(DD_DISARM_RESTORE_LIMIT);
    _forget_request();
    _settle_handlers();
}

bool dd_p2s_guard_in_chain(zend_mm_heap *nonnull heap)
{
    return _arm_state.installed && heap == _arm_state.heap;
}

#ifdef TESTING
void dd_p2s_guard_testing_status(dd_p2s_guard_status *nonnull out)
{
    out->armed_request = _arm_state.armed && _arm_state.request;
    out->pending = _arm_state.pending;
    out->soft = _arm_state.soft;
    out->hard = _arm_state.hard;
}
#endif

// ===========================================================================
// Implementation details
// ===========================================================================

// The hard limit outside a window, at the request level: max(L, S + 8 MiB),
// with L upstream's limit (dd_p2s_guard_hard_limit_floor); the window formula's
// floor with no input term. The windows' additive term keeps a small
// memory_limit from exiting on the first chunk past it. Not based on the usage:
// a memory_limit lowered below it (PHP 7.1-7.3, 7.4.0-7.4.20, 8.0.0-8.0.7)
// gives a hard limit the next allocation can cross (see Coverage: memory_limit
// lowered below usage). SIZE_MAX for "no limit".
static size_t _request_hard_limit(size_t soft)
{
    if (soft == SIZE_MAX) {
        return SIZE_MAX;
    }
    return MAX(dd_p2s_guard_hard_limit_floor(soft),
        dd_p2s_sat_add(soft, DD_PHP2SHELL_HARD_MARGIN));
}

// The engine's own test, from alloc_globals_ctor(): USE_ZEND_ALLOC set to a
// value that parses as 0 makes every thread's heap a malloc()-backed stub. The
// engine reads it once at startup; we read it in MINIT, before any request.
static bool _zend_alloc_disabled(void)
{
    // Called only from MINIT, before any request thread runs, as the engine
    // itself calls getenv() for this variable; there is no thread-safe
    // alternative and nothing can call setenv() concurrently at that point.
    // NOLINTNEXTLINE(concurrency-mt-unsafe)
    const char *tmp = getenv("USE_ZEND_ALLOC");
#if PHP_VERSION_ID >= 80100
    return tmp && !ZEND_ATOL(tmp);
#else
    return tmp && !zend_atoi(tmp, 0);
#endif
}

// The value of the deferred metric's phase tag.
static const char *_phase_name(dd_deferred_phase phase)
{
    switch (phase) {
    case DD_PHASE_STARTUP:
        return "startup";
    case DD_PHASE_REQUEST_PARSE_BODY:
        return "request_parse_body";
    case DD_PHASE_RUNTIME:
    default:
        return "runtime";
    }
}

// Replace the sentinel engine limit with a finite one on the two exits of an
// armed window that do not restore memory_limit: the deferred fatal and a
// bailout from the parse (see dd_p2s_startup_parse_run_window). Code that runs
// afterwards (the metric flush, the log line, formatting the fatal, output
// buffer callbacks, user shutdown functions) must not run with no limit at all.
// The limit is the soft limit, or the current usage plus
// DD_PHP2SHELL_HARD_MARGIN when that is higher: zend_set_memory_limit() refuses
// a limit below the current usage (7.4.21+, 8.1+; on 8.0 only
// zend_set_memory_limit_ex() does, from 8.0.8), which a crossing leaves above
// the soft limit. PG(memory_limit) keeps the configured value.
static void _set_finite_limit(void)
{
    zend_set_memory_limit(MAX(
        _arm_state.soft, dd_p2s_sat_add(_usage(), DD_PHP2SHELL_HARD_MARGIN)));
}

// ---- custom Zend MM allocator handlers -------------------------------------

// The start of zend_mm_heap (Zend/zend_alloc.c) up to its limit, which no API
// reads, with the default ZEND_MM_CUSTOM, ZEND_MM_STORAGE, ZEND_MM_STAT and
// ZEND_MM_LIMIT. PHP 8.4 added shadow_key; ZEND_MM_BINS is 30 on every
// version. _arm() checks the layout in debug builds; were it wrong, a release
// build would only reset the sentinel on every allocation.
#define DD_ZEND_MM_BINS 30
typedef struct {
    int use_custom_heap;
    void *storage;
    size_t size;
    size_t peak;
#if PHP_VERSION_ID >= 80400
    uintptr_t shadow_key;
#endif
    void *free_slot[DD_ZEND_MM_BINS];
    size_t real_size;
    size_t real_peak;
    size_t limit;
} dd_mm_heap_head;

// The engine's current limit on `heap` (zend_set_memory_limit() writes it).
static size_t _heap_limit(zend_mm_heap *nonnull heap)
{
    size_t limit;
    memcpy(&limit, (const char *)heap + offsetof(dd_mm_heap_head, limit),
        sizeof(limit));
    return limit;
}

// Garbage-collect the armed heap, as the engine does before it reports a
// memory-limit error; that path in zend_mm cannot run while armed, since the
// limit is the sentinel. A chained foreign allocator that provides a gc handler
// (8.4+ ABI) is forwarded to: the profiler's allocation profiling, for
// instance, installs one that forwards in turn. Otherwise (before 8.4, or a
// chained allocator without one) disable the custom slot for the duration and
// run zend_mm_gc() directly. A foreign allocator chained below us still
// allocates from this zend_mm heap (the profiler calls _zend_mm_alloc() on
// 8.4+, and before that forwards or flips use_custom_heap off around the call),
// so zend_mm_gc() collects what it allocated. zend_mm_gc() only walks zend_mm's
// own structures, which are valid on a real zend_mm heap whatever sits on top;
// only such a heap is armed (see dd_p2s_startup_parse_run_window). It runs no
// destructors.
static size_t _run_gc(void)
{
#if PHP_VERSION_ID >= 80400
    if (_arm_state.had_prev && _arm_state.prev_gc) {
        return _arm_state.prev_gc();
    }
#endif
    int saved = dd_p2s_heap_disable_custom(_arm_state.heap);
    size_t freed = zend_mm_gc(_arm_state.heap);
    dd_p2s_heap_restore_custom(_arm_state.heap, saved);
    return freed;
}

// The custom handlers take the caller's file and line from PHP 8.4 on.
#if PHP_VERSION_ID >= 80400
#    define DD_MM_DC ZEND_FILE_LINE_DC ZEND_FILE_LINE_ORIG_DC
#    define DD_MM_CC ZEND_FILE_LINE_RELAY_CC ZEND_FILE_LINE_ORIG_RELAY_CC
#else
#    define DD_MM_DC
#    define DD_MM_CC
#endif

// The handlers installed while armed. Each forwards to the handlers found at
// arm time (prev_*), or to zend_mm with the custom slot disabled; malloc and
// realloc then check the limits (see _check_limit). Before forwarding, they
// put the engine back at the sentinel if something moved it (see
// _keep_sentinel), so zend_mm never bails on the limit while armed. A fresh
// huge allocation is checked against the hard limit before it is forwarded too
// (see _precheck_hard_limit).
static void *_dd_mm_malloc(size_t size DD_MM_DC)
{
    _keep_sentinel();
    _precheck_hard_limit(size);
    void *ptr;
    if (_arm_state.had_prev) {
        ptr = _arm_state.prev_malloc(size DD_MM_CC);
    } else {
        int saved = dd_p2s_heap_disable_custom(_arm_state.heap);
        ptr = zend_mm_alloc(_arm_state.heap, size);
        dd_p2s_heap_restore_custom(_arm_state.heap, saved);
    }
    _check_limit(size);
    return ptr;
}

static void _dd_mm_free(void *p DD_MM_DC)
{
    if (_arm_state.had_prev) {
        _arm_state.prev_free(p DD_MM_CC);
    } else {
        int saved = dd_p2s_heap_disable_custom(_arm_state.heap);
        zend_mm_free(_arm_state.heap, p);
        dd_p2s_heap_restore_custom(_arm_state.heap, saved);
    }
}

static void *_dd_mm_realloc(void *p, size_t size DD_MM_DC)
{
    _keep_sentinel();
    void *ptr;
    if (_arm_state.had_prev) {
        ptr = _arm_state.prev_realloc(p, size DD_MM_CC);
    } else {
        int saved = dd_p2s_heap_disable_custom(_arm_state.heap);
        ptr = zend_mm_realloc(_arm_state.heap, p, size);
        dd_p2s_heap_restore_custom(_arm_state.heap, saved);
    }
    _check_limit(size);
    return ptr;
}

#if PHP_VERSION_ID >= 80400
static size_t _dd_mm_gc(void) { return _run_gc(); }

static void _dd_mm_shutdown(bool full, bool silent)
{
    if (_arm_state.had_prev && _arm_state.prev_shutdown) {
        _arm_state.prev_shutdown(full, silent);
        return;
    }
    int saved = dd_p2s_heap_disable_custom(_arm_state.heap);
    zend_mm_shutdown(_arm_state.heap, full, silent);
    dd_p2s_heap_restore_custom(_arm_state.heap, saved);
}
#endif

// Runs before an allocation is forwarded while armed. The engine's limit is
// the sentinel from _arm() on, but anything can set it directly with
// zend_set_memory_limit(): php_error_cb() restores memory_limit before every
// bailing fatal (E_ERROR, E_USER_ERROR, uncaught exceptions, the timeout
// fatal, appsec's own blocking error), so the shutdown functions that follow
// would otherwise allocate under it; ini_set() of memory_limit writes it too.
// Not undone, zend_mm would bail on that limit mid-insertion, and a bailout
// inside a forwarder leaves use_custom_heap off (see
// _handlers_ours_flag_off).
static zend_always_inline void _keep_sentinel(void)
{
    if (_arm_state.armed && UNEXPECTED(_heap_limit(_arm_state.heap) !=
                                       DD_PHP2SHELL_SENTINEL_LIMIT)) {
        _follow_limit();
    }
}

// The engine left the sentinel while armed. If memory_limit (PG) changed
// since the soft limit was computed (a successful ini_set(), or any other
// path), it becomes the new soft limit and the hard limit is recomputed;
// otherwise (php_error_cb()'s restore) the limits stay. A window keeps its own
// limits either way; dd_p2s_guard_window_end() picks up a new value. A refused
// ini_set() changes neither the engine nor PG. Either way the engine goes back
// to the sentinel. Allocates nothing.
static ZEND_COLD void _follow_limit(void)
{
    if (!_arm_state.window &&
        (zend_long)PG(memory_limit) != _arm_state.memory_limit) {
        _arm_state.memory_limit = (zend_long)PG(memory_limit);
        _arm_state.soft = dd_p2s_guard_soft_limit();
        _arm_state.hard = _request_hard_limit(_arm_state.soft);
    }
    zend_set_memory_limit(DD_PHP2SHELL_SENTINEL_LIMIT);
}

// Runs before a fresh allocation is forwarded while armed. Only for huge
// blocks (> ZEND_MM_MAX_LARGE_SIZE): zend_mm always serves those with new
// memory and grows real_size by at least `size` (page-rounded), so
// `used + size > hard` means the post-allocation check below would terminate
// anyway. Terminating first means such a request never reaches the OS
// allocator, whose own failure path (zend_mm_safe_error -> bailout) would run
// before we regain control. Smaller sizes may be served from memory already
// counted in real_size, where the same sum would over-estimate, so they are
// left to the post-allocation check. Reallocations are not pre-checked.
//
// Only a real zend_mm heap is armed (see dd_p2s_startup_parse_run_window). A
// foreign allocator chained over it is assumed to serve huge blocks from
// zend_mm, as the profiler does.
static void _precheck_hard_limit(size_t size)
{
    if (!_arm_state.armed || size <= ZEND_MM_MAX_LARGE_SIZE) {
        return;
    }
    if (UNEXPECTED(_exceeds_hard(_usage(), size))) {
        _hard_abort(size);
    }
}

// Whether usage `used` plus a block of `size` bytes is above the hard limit.
static bool _exceeds_hard(size_t used, size_t size)
{
    return size > _arm_state.hard || used > _arm_state.hard - size;
}

// Runs after every allocation while installed, a no-op unless armed. Above
// the hard limit we terminate without cleanup; above the soft limit (after a
// GC attempt, mirroring upstream) we record a pending OOM. We never bail here
// -- that is the whole point: the in-progress hash insertion must be allowed
// to complete.
static void _check_limit(size_t add_size)
{
    if (!_arm_state.armed) {
        return;
    }
    size_t used = _usage();

    if (UNEXPECTED(_exceeds_hard(used, 0))) {
        _hard_abort(add_size);
    }

    if (used > _arm_state.soft && !_arm_state.pending) {
        // Mirror upstream's "gc then re-check".
        _run_gc();

        if (_usage() > _arm_state.soft) {
            _arm_state.pending = true;
            if (!_arm_state.window) {
                _request_interrupt();
            }
        }
    }
}

// Ask the VM to run the interrupt helper at its next check, where _interrupt
// raises the pending error (request level, outside a window).
static void _request_interrupt(void)
{
#if PHP_VERSION_ID >= 80200
    zend_atomic_bool_store_ex(&EG(vm_interrupt), true);
#elif defined(DD_PHP2SHELL_HAVE_INTERRUPT)
    EG(vm_interrupt) = 1;
#endif
}

// The usage the soft/hard checks compare against: zend_mm's real usage
// (chunks plus huge blocks), the figure the engine's own limit check uses.
// Only a real zend_mm heap is armed (see dd_p2s_startup_parse_run_window).
static size_t _usage(void) { return zend_memory_usage(true); }

static ZEND_COLD ZEND_NORETURN void _hard_abort(size_t add_size)
{
    // Stop the checks before anything else so the stderr write below does not
    // re-enter this function from our handler (installed request-level
    // handlers stay and only forward). The engine keeps the sentinel limit,
    // so this cannot bail.
    _disarm(DD_DISARM_KEEP_LIMIT);

    // Record and SYNCHRONOUSLY flush the hard-limit metric before _exit(137)
    // (which bypasses request shutdown and its telemetry flush). The flush is a
    // bounded blocking IPC write to the sidecar, which survives our exit; the
    // engine still holds the sentinel limit so the small allocations it needs
    // cannot bail. This must run before the _exit below.
    _emit_and_flush(_metric_hard_exit_name, NULL);

    UNUSED(fprintf(stderr,
        "[ddappsec] PHP2Shell mitigation: hard memory limit of %zu bytes "
        "exhausted (tried to allocate %zu bytes); terminating to avoid heap "
        "corruption\n",
        _arm_state.hard, add_size));
    UNUSED(fflush(stderr));
    _exit(DD_HARD_EXIT_STATUS);
}

// Append the metric point, tagged with the mode and, if not NULL, the `phase`
// (see dd_deferred_phase), and flush it synchronously to the sidecar. Only
// reached on the two failure paths: the hard exit runs under the sentinel
// limit, the deferred error under the current usage plus
// DD_PHP2SHELL_HARD_MARGIN, so the small allocations here cannot bail.
static void _emit_and_flush(
    zend_string *nullable name, const char *nullable phase)
{
    if (name == NULL || datadog_metric_register_buffer == NULL ||
        datadog_metric_add_point == NULL) {
        return;
    }
    // Build the "mode:<...>[,phase:<...>]" tags request-scoped: this avoids a
    // process-lifetime string and the ZTS hazard of interning after MINIT.
    char tag_buf[DD_METRIC_TAG_BUFSIZE];
    int n;
    if (phase != NULL) {
        n = snprintf(tag_buf, sizeof(tag_buf), "mode:%s,phase:%s",
            dd_p2s_policy_mode_name(), phase);
    } else {
        n = snprintf(
            tag_buf, sizeof(tag_buf), "mode:%s", dd_p2s_policy_mode_name());
    }
    zend_string *tags = zend_string_init(tag_buf, (size_t)n, 0);
    dd_telemetry_add_metric(name, 1, tags, DDTRACE_METRIC_TYPE_COUNT);
    zend_string_release(tags);

    // We emit during request startup, before the tracer's RINIT registers the
    // telemetry application on the sidecar connection. A plain buffer flush
    // targets the "current application" and would be dropped sidecar-side ("No
    // application found") until that registration happens on a later request.
    // Force the registration now so the synchronous flush below actually
    // delivers, including on the first request of a fresh worker. After RINIT
    // (runtime request_parse_body()) the application is already registered,
    // and the call would also process remote config, so it is skipped there.
    if (!PG(modules_activated) &&
        datadog_sidecar_ensure_telemetry_app != NULL) {
        datadog_sidecar_ensure_telemetry_app();
    }

    // Flush now: the callers never reach the tracer's request-finish flush.
    if (datadog_telemetry_commit_metrics != NULL) {
        datadog_telemetry_commit_metrics();
    }
}

// ---- arm / disarm ----------------------------------------------------------

// Before arming handlers not installed for this arm (a startup window or
// RINIT): make sure ours are in the chain of the current heap, since _arm()
// lifts the engine limit and only our handlers enforce one afterwards. With
// `installed` carried over (see _remove_handlers), the slot decides:
//   - ours on top, or another extension's (assumed to chain to ours, see
//     Coverage: Handlers dropped from the chain): arm them;
//   - plain zend_mm (also ours with use_custom_heap left off, which zend_mm
//     then bypasses), or not the heap we installed on: nobody calls ours, so
//     `installed` is cleared and they are installed afresh.
// False, with nothing installed, if they could not be (see _install_handlers).
static bool _ensure_handlers(void)
{
    if (_arm_state.installed) {
        if (zend_mm_get_heap() == _arm_state.heap && !is_zend_mm()) {
            return true;
        }
        _arm_state.installed = false;
    }
    return _install_handlers();
}

// Put our handlers on the request heap, chained over any custom handlers
// already there (e.g. the profiler's, installed in its RINIT). False, with
// nothing changed, if the slot already holds ours: chaining over them would
// make them their own previous handlers. Arms nothing (see _arm).
static bool _install_handlers(void)
{
    zend_mm_heap *heap = zend_mm_get_heap();
    if (_handlers_ours(heap)) {
        mlog(dd_log_debug, "PHP2Shell mitigation: our memory manager handlers "
                           "are already installed; not installing again");
        return false;
    }

    _arm_state.heap = heap;
    if (is_zend_mm()) {
        _arm_state.had_prev = false;
        _arm_state.prev_malloc = NULL;
        _arm_state.prev_free = NULL;
        _arm_state.prev_realloc = NULL;
#if PHP_VERSION_ID >= 80400
        _arm_state.prev_gc = NULL;
        _arm_state.prev_shutdown = NULL;
#endif
    } else {
        _arm_state.had_prev = true;
#if PHP_VERSION_ID >= 80400
        zend_mm_get_custom_handlers_ex(heap, &_arm_state.prev_malloc,
            &_arm_state.prev_free, &_arm_state.prev_realloc,
            &_arm_state.prev_gc, &_arm_state.prev_shutdown);
#else
        zend_mm_get_custom_handlers(heap, &_arm_state.prev_malloc,
            &_arm_state.prev_free, &_arm_state.prev_realloc);
#endif
    }

    _set_handlers(heap, true);
    _arm_state.installed = true;
    return true;
}

// Take our handlers off the heap and forget the request-level state. The slot
// is restored only if it is still the heap we installed on AND our handlers
// still occupy it, also with use_custom_heap left off by a native bailout
// inside a forwarder (see _handlers_ours_flag_off). Otherwise another
// extension put its own handlers over ours
// (breaking LIFO) and chains to them: the slot is left alone and `installed`
// stays set, since we are still in its chain; reinstalling stale "previous"
// pointers would be worse, and installing again later would chain over
// ourselves. post_deactivate settles it (see _settle_handlers). That is logged
// once per process.
static void _remove_handlers(void)
{
    if (!_arm_state.installed) {
        return;
    }
    _forget_request();
    zend_mm_heap *heap = _arm_state.heap;
    if (zend_mm_get_heap() == heap &&
        (_handlers_ours(heap) || _handlers_ours_flag_off(heap))) {
        _set_handlers(heap, false);
        _arm_state.installed = false;
    } else if (!atomic_exchange_explicit(
                   &_handlers_replaced_warned, true, memory_order_relaxed)) {
        mlog(dd_log_warning,
            "PHP2Shell mitigation: the memory manager handlers were replaced "
            "by another extension; leaving them in place");
    }
}

// At request end (post_deactivate, after every RSHUTDOWN), decide from the
// slot whether our handlers are still in the chain:
//   - ours on top: our RSHUTDOWN was skipped by a bailout, or the extension
//     that was on top restored ours. The heap goes back to plain zend_mm
//     rather than to the handlers found at install: on the bailout path the
//     profiler's own fallback (its zend_extension deactivate, which runs
//     before us) found ours on top and gave up its state, and restoring its
//     handlers would make its next RINIT chain over itself. An extension
//     below us that keeps its handlers across requests loses them here (see
//     Coverage: Handlers reset after a bailout);
//   - plain zend_mm: nobody can call ours;
//   - another extension's: assumed to chain to ours; they stay in the chain
//     (see Coverage: Handlers dropped from the chain).
static void _settle_handlers(void)
{
    if (!_arm_state.installed) {
        return;
    }
    zend_mm_heap *heap = _arm_state.heap;
    if (zend_mm_get_heap() != heap) {
        return;
    }
    if (_handlers_ours(heap)) {
        _set_plain_handlers(heap);
    } else if (!is_zend_mm()) {
        return;
    }
    _arm_state.installed = false;
}

// At MSHUTDOWN, after a failed request startup: php-fpm and php-cgi go
// straight to php_module_shutdown() when php_request_startup() fails (e.g. a
// module whose RINIT runs after ours bails out), so neither our RSHUTDOWN nor
// post_deactivate ran. zend_shutdown() then unloads ddappsec.so, and the final
// shutdown_memory_manager() would free the heap through our handlers. If they
// are in the slot (also with use_custom_heap left off), the heap goes back to
// plain zend_mm, as in _settle_handlers, which also lets the profiler's own
// MSHUTDOWN fallback, if it runs after ours, find plain zend_mm. The engine
// limit goes back to memory_limit if armed. Handlers of another extension on
// top are left alone.
static void _reset_handlers_at_mshutdown(void)
{
    if (!_arm_state.installed) {
        return;
    }
    bool armed = _arm_state.armed;
    _forget_request();
    zend_mm_heap *heap = _arm_state.heap;
    if (zend_mm_get_heap() == heap &&
        (_handlers_ours(heap) || _handlers_ours_flag_off(heap))) {
        _set_plain_handlers(heap);
        _arm_state.installed = false;
    }
    if (armed) {
        zend_set_memory_limit((size_t)(zend_long)PG(memory_limit));
    }
}

// Whether our handler set occupies the custom-handler slot of `heap`, which
// must be the current heap.
static bool _handlers_ours(zend_mm_heap *nonnull heap)
{
    if (is_zend_mm()) {
        return false;
    }
    dd_mm_alloc_fn cur_malloc = NULL;
    dd_mm_free_fn cur_free = NULL;
    dd_mm_realloc_fn cur_realloc = NULL;
#if PHP_VERSION_ID >= 80400
    dd_mm_gc_fn cur_gc = NULL;
    dd_mm_shutdown_fn cur_shutdown = NULL;
    zend_mm_get_custom_handlers_ex(
        heap, &cur_malloc, &cur_free, &cur_realloc, &cur_gc, &cur_shutdown);
#else
    zend_mm_get_custom_handlers(heap, &cur_malloc, &cur_free, &cur_realloc);
#endif
    return cur_malloc == _dd_mm_malloc && cur_free == _dd_mm_free &&
           cur_realloc == _dd_mm_realloc;
}

// Whether our handler set is in the custom-handler slot of `heap` (the current
// heap) with use_custom_heap off. A forwarder (ours, or the profiler's below
// us before 8.4) turns the flag off around its zend_mm call; a native bailout
// there (outside the armed state, or an allocation the OS refused) leaves it
// off, and the zend_mm getters then report no handlers. The slot is read with
// the flag turned on for the duration. After a reset to plain zend_mm, from
// PHP 7.3 the slot keeps the last handlers, so this can also be true for a
// heap that is really plain: only for handlers known to be installed.
static bool _handlers_ours_flag_off(zend_mm_heap *nonnull heap)
{
    if (!is_zend_mm()) {
        return false;
    }
    *(int *)heap = ZEND_MM_CUSTOM_HEAP_STD;
    bool ours = _handlers_ours(heap);
    *(int *)heap = ZEND_MM_CUSTOM_HEAP_NONE;
    return ours;
}

// End the request level: disarm, restoring the configured memory_limit, and
// remove the handlers. From appsec's RSHUTDOWN; post_deactivate covers a
// bailout that skipped it (see _settle_handlers). A no-op when nothing is
// installed.
static void _request_end(void)
{
    _disarm(DD_DISARM_RESTORE_LIMIT);
    _remove_handlers();
}

// Clear the per-request bools; the handlers and `installed` are untouched.
static void _forget_request(void)
{
    _arm_state.request = false;
    _arm_state.armed = false;
    _arm_state.window = false;
    _arm_state.pending = false;
}

// Arm installed handlers: raise the engine's limit to the sentinel, so that
// from here on only _check_limit() and _precheck_hard_limit() enforce the soft
// and hard limits. Clears a pending error.
// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
static void _arm(size_t soft, size_t hard)
{
    ZEND_ASSERT(_arm_state.installed);
    _arm_state.pending = false;
    _arm_state.soft = soft;
    _arm_state.hard = hard;
    _arm_state.memory_limit = (zend_long)PG(memory_limit);
    zend_set_memory_limit(DD_PHP2SHELL_SENTINEL_LIMIT);
    // The layout _heap_limit() assumes.
    ZEND_ASSERT(zend_mm_get_heap() != _arm_state.heap ||
                _heap_limit(_arm_state.heap) == DD_PHP2SHELL_SENTINEL_LIMIT);
    _arm_state.armed = true;
}

// Stop enforcing the limits and leave any window. The handlers of a startup
// window are removed; request-level ones stay and forward until RSHUTDOWN (a
// pending error is then dropped there). A no-op when not armed.
static void _disarm(dd_disarm_mode mode)
{
    if (!_arm_state.armed) {
        return;
    }
    _arm_state.armed = false;
    _arm_state.window = false;
    if (!_arm_state.request) {
        _remove_handlers();
    }
    if (mode == DD_DISARM_RESTORE_LIMIT) {
        // The configured memory_limit, which may have changed since the arm
        // (ini_set, see _follow_limit).
        zend_set_memory_limit((size_t)(zend_long)PG(memory_limit));
    }
}

// With `ours`, install our handler set on `heap`; otherwise put back what
// _arm() found there: the saved foreign handlers, or none.
static void _set_handlers(zend_mm_heap *nonnull heap, bool ours)
{
#if PHP_VERSION_ID >= 80400
    if (ours) {
        zend_mm_set_custom_handlers_ex(heap, _dd_mm_malloc, _dd_mm_free,
            _dd_mm_realloc, _dd_mm_gc, _dd_mm_shutdown);
    } else if (_arm_state.had_prev) {
        zend_mm_set_custom_handlers_ex(heap, _arm_state.prev_malloc,
            _arm_state.prev_free, _arm_state.prev_realloc, _arm_state.prev_gc,
            _arm_state.prev_shutdown);
    } else {
        _set_plain_handlers(heap);
    }
#else
    if (ours) {
        zend_mm_set_custom_handlers(
            heap, _dd_mm_malloc, _dd_mm_free, _dd_mm_realloc);
    } else if (_arm_state.had_prev) {
        zend_mm_set_custom_handlers(heap, _arm_state.prev_malloc,
            _arm_state.prev_free, _arm_state.prev_realloc);
    } else {
        _set_plain_handlers(heap);
    }
#endif
}

// Leave `heap` with no custom handlers: plain zend_mm.
static void _set_plain_handlers(zend_mm_heap *nonnull heap)
{
#if PHP_VERSION_ID >= 80400
    zend_mm_set_custom_handlers_ex(heap, NULL, NULL, NULL, NULL, NULL);
#else
    zend_mm_set_custom_handlers(heap, NULL, NULL, NULL);
#    if PHP_VERSION_ID < 70300
    // PHP 7.0-7.2 do not reset use_custom_heap when all handlers are
    // NULL, leaving custom allocation "enabled" with NULL callbacks so
    // the next allocation crashes. Clear the flag explicitly, exactly as
    // the profiler does for these versions.
    *(int *)heap = ZEND_MM_CUSTOM_HEAP_NONE;
#    endif
#endif
}
