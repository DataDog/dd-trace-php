// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// The out-of-memory guard: our Zend MM handlers, which emulate the soft
// (memory_limit) and hard limits while armed, defer a soft-limit crossing to a
// safe point, and terminate the process at the hard limit.

#ifndef DD_P2S_OOM_GUARD_H
#define DD_P2S_OOM_GUARD_H

#include <php.h>
#include <stdbool.h>
#include <stddef.h>

#include "../attributes.h"

// The request level needs the VM interrupt (EG(vm_interrupt) and
// zend_interrupt_function), added in PHP 7.1. PHP 7.0 has only the windows.
#if PHP_VERSION_ID >= 70100
#    define DD_PHP2SHELL_HAVE_INTERRUPT 1
#endif

#define DD_ONE_GIB ((size_t)1 << 30)
#define DD_ONE_MIB ((size_t)1 << 20)
// Fixed costs added to the hard limits (see _hard_limit in p2s_startup_parse.c
// and _request_hard_limit in p2s_oom_guard.c).
#define DD_PHP2SHELL_HARD_MARGIN (8 * DD_ONE_MIB)

// Where a deferred out-of-memory error is raised: the phase tag of the
// deferred metric and the log line (see dd_p2s_guard_raise_deferred_oom).
typedef enum {
    // At the end of the startup parse (a treat_data window, before RINIT).
    DD_PHASE_STARTUP = 0,
    // At the end of a request_parse_body() window (PHP 8.4+).
    DD_PHASE_REQUEST_PARSE_BODY,
    // At a VM interrupt, after a crossing at the request level.
    DD_PHASE_RUNTIME,
} dd_deferred_phase;

// The name of a phase: the value of the deferred metric's phase tag and of the
// span tags (see p2s_report.c).
const char *nonnull dd_p2s_phase_name(dd_deferred_phase phase);

// a + b, or SIZE_MAX on overflow.
static inline size_t dd_p2s_sat_add(size_t a, size_t b)
{
    size_t res;
    return __builtin_add_overflow(a, b, &res) ? SIZE_MAX : res;
}

// a x b, or SIZE_MAX on overflow.
static inline size_t dd_p2s_sat_mul(size_t a, size_t b)
{
    size_t res;
    return __builtin_mul_overflow(a, b, &res) ? SIZE_MAX : res;
}

static zend_always_inline int dd_p2s_heap_disable_custom(
    zend_mm_heap *nonnull heap)
{
    // use_custom_heap is the first field of zend_mm_heap (an int). The profiler
    // relies on the same layout. Flipping it to NONE lets zend_mm_gc() actually
    // run while we hold the custom-handler slot.
    int saved = *(int *)heap;
    *(int *)heap = ZEND_MM_CUSTOM_HEAP_NONE;
    return saved;
}

static zend_always_inline void dd_p2s_heap_restore_custom(
    zend_mm_heap *nonnull heap, int v)
{
    *(int *)heap = v;
}

// Detect a real zend_mm heap and intern the metric names. MINIT only.
void dd_p2s_guard_minit(void);
// Put the heap back to plain zend_mm if our handlers are still on it. Must
// run before the hook slots are restored in MSHUTDOWN.
void dd_p2s_guard_mshutdown(void);
// Whether the heap is a real zend_mm heap (USE_ZEND_ALLOC is not 0).
bool dd_p2s_guard_available(void);
size_t dd_p2s_guard_soft_limit(void);
size_t dd_p2s_guard_hard_limit_floor(size_t soft);
// Inside an armed parse window.
bool dd_p2s_guard_in_window(void);
// Armed, with a soft-limit crossing pending.
bool dd_p2s_guard_error_pending(void);
// Ask the VM to run the interrupt helper at its next check.
void dd_p2s_guard_request_interrupt(void);
// The pending error could not be raised at this interrupt: each allocation
// asks for the interrupt again until it is raised.
void dd_p2s_guard_raise_refused(void);
// Arm for a parse window; false if it could not arm. `nested` gets whether
// the request level was armed when the window began.
bool dd_p2s_guard_window_begin(size_t soft, size_t hard, bool *nonnull nested);
// End an armed window that did not raise; `nested` as from
// dd_p2s_guard_window_begin().
void dd_p2s_guard_window_end(bool nested);
// Stop an armed window before raising its deferred error.
void dd_p2s_guard_window_stop(void);
// Stop an armed window on a bailout from its parse.
void dd_p2s_guard_window_abort(void);
// Raise the deferred out-of-memory error at a safe point. `dropped` is the
// number of request variables dropped (logged for a window phase).
ZEND_COLD ZEND_NORETURN void dd_p2s_guard_raise_deferred_oom(
    dd_deferred_phase phase, size_t dropped);
// Arm for the whole request (appsec's RINIT, or dd_p2s_guard_reconcile).
void dd_p2s_guard_open(void);
// Make the request level armed or not, mid-request (FrankenPHP worker mode,
// between HTTP requests): opens it if needed; otherwise only flags and the
// engine limit change, the handlers stay until dd_p2s_guard_close(). A
// disarm with an error pending is left to the interrupt that raises it.
void dd_p2s_guard_reconcile(bool armed);
// Marker in the deferred fatal's message, so that monitor detection skips it.
#define DD_P2S_DEFERRED_MARKER "(Datadog AppSec PHP2Shell mitigation deferred"
// End the request level (appsec's RSHUTDOWN).
void dd_p2s_guard_close(void);
// post_deactivate: disarm, forget the request and settle the handlers.
void dd_p2s_guard_settle(void);
// Whether our handlers are in the chain of `heap`.
bool dd_p2s_guard_in_chain(zend_mm_heap *nonnull heap);
#ifdef TESTING
typedef struct {
    // Armed for the whole request.
    bool armed_request;
    // A crossing is pending (armed or not).
    bool pending;
    size_t soft;
    size_t hard;
} dd_p2s_guard_status;
void dd_p2s_guard_testing_status(dd_p2s_guard_status *nonnull out);
#endif

#endif // DD_P2S_OOM_GUARD_H
