// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// The span tags of a memory error, and the trace keep that goes with them.

#ifndef DD_P2S_REPORT_H
#define DD_P2S_REPORT_H

#include "p2s_oom_guard.h"

// The kind of a native memory error (see p2s_monitor.c).
typedef enum {
    // "Allowed memory size of N bytes exhausted": memory_limit was crossed.
    DD_P2S_MEMORY_LIMIT = 0,
    // "Out of memory": the operating system refused the allocation.
    DD_P2S_OUT_OF_MEMORY,
} dd_p2s_memory_error;

// Block state: a deferred error is being raised in `phase`. Sets
// _dd.appsec.php2shell.deferred = <phase> and keeps the trace.
void dd_p2s_report_deferred(dd_deferred_phase phase);
// Monitor state: a native memory error of `kind` is being raised in `phase`.
// Sets _dd.appsec.php2shell.memory_error = <kind> and
// _dd.appsec.php2shell.phase = <phase> and keeps the trace.
void dd_p2s_report_memory_error(
    dd_p2s_memory_error kind, dd_deferred_phase phase);

#endif // DD_P2S_REPORT_H
