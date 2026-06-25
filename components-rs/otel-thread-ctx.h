// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0


#ifndef DDOG_OTEL_THREAD_CTX_H
#define DDOG_OTEL_THREAD_CTX_H

#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include "common.h"

#if defined(__linux__)
#endif

#if defined(__linux__)
#endif

#if defined(__linux__)
#endif

#if defined(__linux__)
#endif

#ifdef __cplusplus
extern "C" {
#endif // __cplusplus

#if defined(__linux__)
/**
 * Allocate and initialise a new thread context.
 *
 * Returns a non-null owned handle that must eventually be released with
 * `ddog_otel_thread_ctx_free`.
 */
struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_new(const uint8_t (*trace_id)[16],
                                                          const uint8_t (*span_id)[8],
                                                          const uint8_t (*local_root_span_id)[8]);
#endif

#if defined(__linux__)
struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_new_with_attrs(const uint8_t (*trace_id)[16],
                                                                     const uint8_t (*span_id)[8],
                                                                     const uint8_t (*local_root_span_id)[8],
                                                                     const struct ddog_OtelThreadContextAttribute *attrs,
                                                                     uintptr_t attrs_len);
#endif

#if defined(__linux__)
bool ddog_otel_thread_ctx_record_update(struct ddog_ThreadContextRecord *ctx,
                                        const uint8_t (*trace_id)[16],
                                        const uint8_t (*span_id)[8],
                                        const uint8_t (*local_root_span_id)[8],
                                        const struct ddog_OtelThreadContextAttribute *attrs,
                                        uintptr_t attrs_len);
#endif

#if defined(__linux__)
bool ddog_otel_thread_ctx_record_update_span_id(struct ddog_ThreadContextRecord *ctx,
                                                const uint8_t (*span_id)[8]);
#endif

#if defined(__linux__)
/**
 * Free an owned thread context.
 *
 * # Safety
 *
 * `ctx` must be a valid non-null pointer obtained from `ddog_otel_thread_ctx_new` or
 * `ddog_otel_thread_ctx_detach`, and must not be used after this call. In particular, `ctx`
 * must not be currently attached to a thread.
 */
void ddog_otel_thread_ctx_free(struct ddog_ThreadContextHandle *ctx);
#endif

#if defined(__linux__)
/**
 * Attach `ctx` to the current thread. Returns the previously attached context if any, or null
 * otherwise.
 *
 * # Safety
 *
 * `ctx` must be a valid non-null pointer obtained from this API. Ownership of `ctx` is
 * transferred to the TLS slot: the caller must not drop `ctx` while it is still actively
 * attached.
 */
struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_attach(struct ddog_ThreadContextHandle *ctx);
#endif

#if defined(__linux__)
/**
 * Attach an externally owned record to the current thread without taking ownership.
 *
 * # Safety
 *
 * `ctx` must point to a live record that remains allocated until it is detached.
 */
void ddog_otel_thread_ctx_attach_record(struct ddog_ThreadContextRecord *ctx);
#endif

#if defined(__linux__)
/**
 * Remove the currently attached context from the TLS slot.
 *
 * Returns the detached context (caller now owns it and must release it with
 * `ddog_otel_thread_ctx_free`), or null if the slot was empty.
 */
struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_detach(void);
#endif

#if defined(__linux__)
/**
 * Clear the current thread's context slot without taking ownership of the previous record.
 */
void ddog_otel_thread_ctx_detach_record(void);
#endif

#if defined(__linux__)
/**
 * Clear the current thread's context slot if it currently points to `ctx`.
 *
 * Returns true when the slot was cleared.
 */
bool ddog_otel_thread_ctx_detach_record_if_current(struct ddog_ThreadContextRecord *ctx);
#endif

#if defined(__linux__)
/**
 * Update the currently attached context in-place.
 *
 * If no context is currently attached, one is created and attached, equivalent to calling
 * `ddog_otel_thread_ctx_new` followed by `ddog_otel_thread_ctx_attach`.
 */
void ddog_otel_thread_ctx_update(const uint8_t (*trace_id)[16],
                                 const uint8_t (*span_id)[8],
                                 const uint8_t (*local_root_span_id)[8]);
#endif

#if defined(__linux__)
void ddog_otel_thread_ctx_update_with_attrs(const uint8_t (*trace_id)[16],
                                            const uint8_t (*span_id)[8],
                                            const uint8_t (*local_root_span_id)[8],
                                            const struct ddog_OtelThreadContextAttribute *attrs,
                                            uintptr_t attrs_len);
#endif

#ifdef __cplusplus
}  // extern "C"
#endif  // __cplusplus

#endif  /* DDOG_OTEL_THREAD_CTX_H */
