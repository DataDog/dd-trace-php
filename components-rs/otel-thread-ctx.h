// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#ifndef DDOG_OTEL_THREAD_CTX_H
#define DDOG_OTEL_THREAD_CTX_H

#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <components-rs/common.h>

#ifdef __linux__

#define ddog_MAX_ATTRS_DATA_SIZE 612

typedef struct ddog_ThreadContextHandle ddog_ThreadContextHandle;

typedef struct ddog_ThreadContextRecord {
  uint8_t trace_id[16];
  uint64_t span_id;
  uint8_t valid;
  uint8_t _reserved;
  uint16_t attrs_data_size;
  uint8_t attrs_data[ddog_MAX_ATTRS_DATA_SIZE];
} ddog_ThreadContextRecord;

typedef struct ddog_OtelThreadContextAttribute {
  uint8_t key_index;
  ddog_CharSlice value;
} ddog_OtelThreadContextAttribute;

struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_new(const uint8_t (*trace_id)[16],
                                                          const uint8_t (*span_id)[8],
                                                          const uint8_t (*local_root_span_id)[8]);

struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_new_with_attrs(const uint8_t (*trace_id)[16],
                                                                     const uint8_t (*span_id)[8],
                                                                     const uint8_t (*local_root_span_id)[8],
                                                                     const struct ddog_OtelThreadContextAttribute *attrs,
                                                                     uintptr_t attrs_len);

bool ddog_otel_thread_ctx_record_update(struct ddog_ThreadContextRecord *ctx,
                                        const uint8_t (*trace_id)[16],
                                        const uint8_t (*span_id)[8],
                                        const uint8_t (*local_root_span_id)[8],
                                        const struct ddog_OtelThreadContextAttribute *attrs,
                                        uintptr_t attrs_len);

bool ddog_otel_thread_ctx_record_update_span_id(struct ddog_ThreadContextRecord *ctx,
                                                const uint8_t (*span_id)[8]);

void ddog_otel_thread_ctx_free(struct ddog_ThreadContextHandle *ctx);

struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_attach(struct ddog_ThreadContextHandle *ctx);

void ddog_otel_thread_ctx_attach_record(struct ddog_ThreadContextRecord *ctx);

struct ddog_ThreadContextHandle *ddog_otel_thread_ctx_detach(void);

void ddog_otel_thread_ctx_detach_record(void);

bool ddog_otel_thread_ctx_detach_record_if_current(struct ddog_ThreadContextRecord *ctx);

void ddog_otel_thread_ctx_update(const uint8_t (*trace_id)[16],
                                 const uint8_t (*span_id)[8],
                                 const uint8_t (*local_root_span_id)[8]);

void ddog_otel_thread_ctx_update_with_attrs(const uint8_t (*trace_id)[16],
                                            const uint8_t (*span_id)[8],
                                            const uint8_t (*local_root_span_id)[8],
                                            const struct ddog_OtelThreadContextAttribute *attrs,
                                            uintptr_t attrs_len);

#endif

#endif /* DDOG_OTEL_THREAD_CTX_H */
