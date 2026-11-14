// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// The startup span: a request whose startup window raises its deferred fatal
// never reaches RINIT, so it has no root span. What its span needs is saved in
// its pool's slot of shared memory, and a later traced request of the same pool
// sends the span as a trace of its own (see p2s_startup_span.c).

#ifndef DD_P2S_STARTUP_SPAN_H
#define DD_P2S_STARTUP_SPAN_H

#include <stddef.h>

// Map the slots of every pool. Called once per process tree, at module startup,
// before any SAPI forks its workers. On failure the feature is off for the
// process tree.
void dd_p2s_startup_span_create(void);
// Unmap what this process still maps, leaving the contents to the other
// processes. Called at MSHUTDOWN.
void dd_p2s_startup_span_destroy(void);
// At the first call in a process, bind it to its pool's slot and unmap the
// others; then record the request's start time. Called from the zend_extension
// activate hook, before the startup parse.
void dd_p2s_startup_span_activate(void);
// The startup window is raising its deferred fatal, under a finite limit:
// build $_SERVER and save the request's span data in the slot if it is empty,
// else count the event as dropped. `breached_limit` is the memory_limit that
// was crossed.
void dd_p2s_startup_span_save(size_t breached_limit);
// At AppSec RINIT, after the tracer created this request's root span: if the
// slot holds an event and this request is traced, take it and add its span as
// a trace of its own, sent by this request's flush.
void dd_p2s_startup_span_carry(void);

#endif // DD_P2S_STARTUP_SPAN_H
