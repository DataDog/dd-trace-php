// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.

#ifndef DDOG_LIVE_DEBUGGER_H
#define DDOG_LIVE_DEBUGGER_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include "common.h"

struct ddog_Capture ddog_capture_defaults(void);

void register_expr_evaluator(const struct ddog_Evaluator *eval);

bool evaluate_condition(const struct ddog_ProbeCondition *condition, const void *context);

struct ddog_VoidCollection evaluate_unmanaged_string(const struct ddog_DslString *condition,
                                                     const void *context);

struct ddog_LiveDebuggingParseResult parse_json(ddog_CharSlice json);

void drop_probe(struct ddog_LiveDebuggingData);

void drop_parse_result(struct ddog_LiveDebuggingParseResult);

ddog_DebuggerCapture *ddog_create_exception_snapshot(struct ddog_Vec_DebuggerPayloadCharSlice *buffer,
                                                     ddog_CharSlice service,
                                                     ddog_CharSlice language,
                                                     ddog_CharSlice id,
                                                     ddog_CharSlice exception_id,
                                                     uint64_t timestamp);

void ddog_snapshot_add_field(ddog_DebuggerCapture *capture,
                             enum ddog_FieldType type,
                             ddog_CharSlice name,
                             struct ddog_CaptureValue value);

void ddog_capture_value_add_element(struct ddog_CaptureValue *value,
                                    struct ddog_CaptureValue element);

void ddog_capture_value_add_entry(struct ddog_CaptureValue *value,
                                  struct ddog_CaptureValue key,
                                  struct ddog_CaptureValue element);

void ddog_capture_value_add_field(struct ddog_CaptureValue *value,
                                  ddog_CharSlice key,
                                  struct ddog_CaptureValue element);

void ddog_snapshot_format_new_uuid(uint8_t (*buf)[36]);

#endif /* DDOG_LIVE_DEBUGGER_H */
