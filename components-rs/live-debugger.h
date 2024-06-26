// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.

#ifndef DDOG_LIVE_DEBUGGER_H
#define DDOG_LIVE_DEBUGGER_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include "common.h"

void drop_span_decoration_probe(struct ddog_SpanDecorationProbe);

struct ddog_CaptureConfiguration ddog_capture_defaults(void);

void ddog_register_expr_evaluator(const struct ddog_Evaluator *eval);

struct ddog_ConditionEvaluationResult ddog_evaluate_condition(const struct ddog_ProbeCondition *condition,
                                                              void *context);

void ddog_drop_void_collection_string(struct ddog_VoidCollection void_);

struct ddog_VoidCollection ddog_evaluate_unmanaged_string(const struct ddog_DslString *segments,
                                                          void *context,
                                                          struct ddog_Vec_SnapshotEvaluationError **errors);

struct ddog_ValueEvaluationResult ddog_evaluate_value(const struct ddog_ProbeValue *value,
                                                      void *context);

struct ddog_IntermediateValue ddog_evaluated_value_get(const struct ddog_InternalIntermediateValue *value);

void ddog_evaluated_value_drop(struct ddog_InternalIntermediateValue*);

struct ddog_VoidCollection ddog_evaluated_value_into_unmanaged_string(struct ddog_InternalIntermediateValue *value,
                                                                      void *context);

struct ddog_LiveDebuggingParseResult ddog_parse_live_debugger_json(ddog_CharSlice json);

void ddog_drop_live_debugger_parse_result(struct ddog_LiveDebuggingParseResult);

ddog_DebuggerCapture *ddog_create_exception_snapshot(struct ddog_Vec_DebuggerPayload *buffer,
                                                     ddog_CharSlice service,
                                                     ddog_CharSlice language,
                                                     ddog_CharSlice id,
                                                     ddog_CharSlice exception_id,
                                                     uint64_t timestamp);

struct ddog_DebuggerPayload *ddog_create_log_probe_snapshot(const struct ddog_Probe *probe,
                                                            const ddog_CharSlice *message,
                                                            ddog_CharSlice service,
                                                            ddog_CharSlice language,
                                                            uint64_t timestamp);

void ddog_update_payload_message(struct ddog_DebuggerPayload *payload, ddog_CharSlice message);

ddog_DebuggerCapture *ddog_snapshot_entry(struct ddog_DebuggerPayload *payload);

ddog_DebuggerCapture *ddog_snapshot_lines(struct ddog_DebuggerPayload *payload, uint32_t line);

ddog_DebuggerCapture *ddog_snapshot_exit(struct ddog_DebuggerPayload *payload);

bool ddog_snapshot_redacted_name(ddog_CharSlice name);

void ddog_snapshot_add_redacted_name(ddog_CharSlice name);

bool ddog_snapshot_redacted_type(ddog_CharSlice name);

void ddog_snapshot_add_redacted_type(ddog_CharSlice name);

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

ddog_CharSlice ddog_evaluation_error_first_msg(const struct ddog_Vec_SnapshotEvaluationError *vec);

void ddog_evaluation_error_drop(struct ddog_Vec_SnapshotEvaluationError*);

struct ddog_DebuggerPayload *ddog_evaluation_error_snapshot(const struct ddog_Probe *probe,
                                                            ddog_CharSlice service,
                                                            ddog_CharSlice language,
                                                            struct ddog_Vec_SnapshotEvaluationError *errors,
                                                            uint64_t timestamp);

void ddog_serialize_debugger_payload(const struct ddog_DebuggerPayload *payload,
                                     void (*callback)(ddog_CharSlice));

void ddog_drop_debugger_payload(struct ddog_DebuggerPayload*);

struct ddog_DebuggerPayload *ddog_debugger_diagnostics_create(const struct ddog_Probe *probe,
                                                              ddog_CharSlice service,
                                                              ddog_CharSlice runtime_id,
                                                              uint64_t timestamp);

void ddog_debugger_diagnostics_set_parent_id(struct ddog_DebuggerPayload *payload,
                                             ddog_CharSlice parent_id);

struct ddog_String *ddog_live_debugger_build_tags(ddog_CharSlice debugger_version,
                                                  ddog_CharSlice env,
                                                  ddog_CharSlice version,
                                                  ddog_CharSlice runtime_id,
                                                  struct ddog_Vec_Tag global_tags);

struct ddog_String *ddog_live_debugger_tags_from_raw(ddog_CharSlice tags);

ddog_MaybeError ddog_live_debugger_spawn_sender(const ddog_Endpoint *endpoint,
                                                struct ddog_String *tags,
                                                struct ddog_SenderHandle **handle);

bool ddog_live_debugger_send_raw_data(struct ddog_SenderHandle *handle,
                                      enum ddog_DebuggerType debugger_type,
                                      struct ddog_OwnedCharSlice data);

bool ddog_live_debugger_send_payload(struct ddog_SenderHandle *handle,
                                     const struct ddog_DebuggerPayload *data);

void ddog_live_debugger_drop_sender(struct ddog_SenderHandle *sender);

void ddog_live_debugger_join_sender(struct ddog_SenderHandle *sender);

#endif /* DDOG_LIVE_DEBUGGER_H */
