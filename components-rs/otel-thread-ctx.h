// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#ifndef DDOG_OTEL_THREAD_CTX_H
#define DDOG_OTEL_THREAD_CTX_H

#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#ifdef __linux__

#define ddog_MAX_ATTRS_DATA_SIZE 612

typedef struct ddog_ThreadContextHandle ddog_ThreadContextHandle;

struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_new(const uint8_t (*trace_id)[16],
                                                          const uint8_t (*span_id)[8],
                                                          const uint8_t (*local_root_span_id)[8]);

void ddog_otel_thread_ctx_free(struct ddog_ThreadContextHandle *ctx);

struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_attach(struct ddog_ThreadContextHandle *ctx);

struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_detach(void);

void ddog_otel_thread_ctx_update(const uint8_t (*trace_id)[16],
                                 const uint8_t (*span_id)[8],
                                 const uint8_t (*local_root_span_id)[8]);

#endif

#endif /* DDOG_OTEL_THREAD_CTX_H */
