/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 899dcc72fc2a852d8f1e8aad895c4b628cd17eb8 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_trace_method, 0, 3, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, className, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, methodName, IS_STRING, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, tracingClosureOrConfigArray, Closure, MAY_BE_NULL|MAY_BE_ARRAY, NULL)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_trace_function, 0, 2, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, functionName, IS_STRING, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, tracingClosureOrConfigArray, Closure, MAY_BE_ARRAY|MAY_BE_NULL, NULL)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_hook_function, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, functionName, IS_STRING, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, prehookOrConfigArray, Closure, MAY_BE_ARRAY|MAY_BE_NULL, "null")
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, posthook, Closure, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_hook_method, 0, 2, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, className, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, methodName, IS_STRING, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, prehookOrConfigArray, Closure, MAY_BE_ARRAY|MAY_BE_NULL, "null")
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, posthook, Closure, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_add_global_tag, 0, 2, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_DDTrace_add_distributed_tag arginfo_DDTrace_add_global_tag

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_set_user, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, userId, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, metadata, IS_ARRAY, 0, "[]")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, propagate, _IS_BOOL, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_DDTrace_close_spans_until, 0, 1, MAY_BE_FALSE|MAY_BE_LONG)
	ZEND_ARG_OBJ_INFO(0, span, DDTrace\\SpanData, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_DDTrace_active_span, 0, 0, DDTrace\\SpanData, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_DDTrace_root_span, 0, 0, DDTrace\\RootSpanData, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_TYPE_MASK_EX(arginfo_DDTrace_start_span, 0, 0, DDTrace\\SpanData, MAY_BE_FALSE)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, startTime, IS_DOUBLE, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_close_span, 0, 0, IS_FALSE, 1)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, finishTime, IS_DOUBLE, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_update_span_duration, 0, 1, IS_NULL, 1)
	ZEND_ARG_OBJ_INFO(0, span, DDTrace\\SpanData, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, finishTime, IS_DOUBLE, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_DDTrace_start_trace_span, 0, 0, DDTrace\\SpanData, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, startTime, IS_DOUBLE, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_DDTrace_active_stack, 0, 0, DDTrace\\SpanStack, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_DDTrace_create_stack, 0, 0, DDTrace\\SpanStack, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_TYPE_MASK_EX(arginfo_DDTrace_switch_stack, 0, 0, DDTrace\\SpanStack, MAY_BE_NULL|MAY_BE_FALSE)
	ZEND_ARG_OBJ_TYPE_MASK(0, newStack, DDTrace\\SpanData|DDTrace\\SpanStack, MAY_BE_NULL, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_set_priority_sampling, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, priority, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, global, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_get_priority_sampling, 0, 0, IS_LONG, 1)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, global, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_get_sanitized_exception_trace, 0, 1, IS_STRING, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, exception, Exception|Throwable, 0, NULL)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, skipFrames, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_consume_distributed_tracing_headers, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_MASK(0, headersOrCallback, MAY_BE_ARRAY|MAY_BE_CALLABLE, NULL)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_generate_distributed_tracing_headers, 0, 0, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, inject, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_DDTrace_find_active_exception, 0, 0, Throwable, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_extract_ip_from_headers, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_startup_logs, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_DDTrace_trace_id arginfo_DDTrace_startup_logs

#define arginfo_DDTrace_logs_correlation_trace_id arginfo_DDTrace_startup_logs

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_current_context, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_set_distributed_tracing_context, 0, 2, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, traceId, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, parentId, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, origin, IS_STRING, 1, "null")
	ZEND_ARG_TYPE_MASK(0, propagated_tags, MAY_BE_ARRAY|MAY_BE_STRING|MAY_BE_NULL, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_flush, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_curl_multi_exec_get_request_spans, 0, 1, IS_VOID, 0)
	ZEND_ARG_INFO(1, array)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_System_container_id, 0, 0, IS_STRING, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_Config_integration_analytics_enabled, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, integrationName, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_Config_integration_analytics_sample_rate, 0, 1, IS_DOUBLE, 0)
	ZEND_ARG_TYPE_INFO(0, integrationName, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_UserRequest_has_listeners, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_UserRequest_notify_start, 0, 2, IS_ARRAY, 1)
	ZEND_ARG_OBJ_INFO(0, span, DDTrace\\RootSpanData, 0)
	ZEND_ARG_TYPE_INFO(0, data, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, body, IS_MIXED, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_UserRequest_notify_commit, 0, 3, IS_ARRAY, 1)
	ZEND_ARG_OBJ_INFO(0, span, DDTrace\\RootSpanData, 0)
	ZEND_ARG_TYPE_INFO(0, status, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, body, IS_MIXED, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_UserRequest_set_blocking_function, 0, 2, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, span, DDTrace\\RootSpanData, 0)
	ZEND_ARG_TYPE_INFO(0, blockingFunction, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_Testing_trigger_error, 0, 2, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, errorType, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dd_trace_env_config, 0, 1, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, envName, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_dd_trace_disable_in_request arginfo_DDTrace_UserRequest_has_listeners

#define arginfo_dd_trace_reset arginfo_DDTrace_UserRequest_has_listeners

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_dd_trace_serialize_msgpack, 0, 1, MAY_BE_BOOL|MAY_BE_STRING)
	ZEND_ARG_TYPE_INFO(0, traceArray, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dd_trace_noop, 0, 0, _IS_BOOL, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(0, args, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dd_trace_dd_get_memory_limit, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_dd_trace_check_memory_under_limit arginfo_DDTrace_UserRequest_has_listeners

#define arginfo_dd_tracer_circuit_breaker_register_error arginfo_DDTrace_UserRequest_has_listeners

#define arginfo_dd_tracer_circuit_breaker_register_success arginfo_DDTrace_UserRequest_has_listeners

#define arginfo_dd_tracer_circuit_breaker_can_try arginfo_DDTrace_UserRequest_has_listeners

#define arginfo_dd_tracer_circuit_breaker_info arginfo_DDTrace_current_context

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ddtrace_config_app_name, 0, 0, IS_STRING, 1)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, fallbackName, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_ddtrace_config_distributed_tracing_enabled arginfo_DDTrace_UserRequest_has_listeners

#define arginfo_ddtrace_config_trace_enabled arginfo_DDTrace_UserRequest_has_listeners

#define arginfo_ddtrace_config_integration_enabled arginfo_DDTrace_Config_integration_analytics_enabled

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ddtrace_init, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, dir, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dd_trace_send_traces_via_thread, 0, 3, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, numTraces, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, curlHeaders, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dd_trace_buffer_span, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, traceArray, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_dd_trace_coms_trigger_writer_flush arginfo_dd_trace_dd_get_memory_limit

ZEND_BEGIN_ARG_INFO_EX(arginfo_dd_trace_internal_fn, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, functionName, IS_STRING, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(0, args, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dd_trace_set_trace_id, 0, 0, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, traceId, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_dd_trace_closed_spans_count arginfo_dd_trace_dd_get_memory_limit

#define arginfo_dd_trace_tracer_is_limited arginfo_DDTrace_UserRequest_has_listeners

#define arginfo_dd_trace_compile_time_microseconds arginfo_dd_trace_dd_get_memory_limit

#define arginfo_dd_trace_serialize_closed_spans arginfo_DDTrace_current_context

#define arginfo_dd_trace_peek_span_id arginfo_DDTrace_startup_logs

#define arginfo_dd_trace_close_all_spans_and_flush arginfo_DDTrace_flush

#define arginfo_dd_trace_function arginfo_DDTrace_trace_function

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dd_trace_method, 0, 3, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, className, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, methodName, IS_STRING, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, tracingClosureOrConfigArray, Closure, MAY_BE_ARRAY|MAY_BE_NULL, NULL)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dd_untrace, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, functionName, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, className, IS_STRING, 0, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dd_trace_synchronous_flush, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, timeout, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_dd_trace_forward_call arginfo_DDTrace_UserRequest_has_listeners

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dd_trace_generate_id, 0, 1, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, existingID, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_dd_trace_push_span_id arginfo_dd_trace_generate_id

#define arginfo_dd_trace_pop_span_id arginfo_DDTrace_startup_logs

#define arginfo_additional_trace_meta arginfo_DDTrace_current_context

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DDTrace_SpanLink_jsonSerialize, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DDTrace_SpanLink_fromHeaders, 0, 1, DDTrace\\SpanLink, 0)
	ZEND_ARG_TYPE_MASK(0, headersOrCallback, MAY_BE_ARRAY|MAY_BE_CALLABLE, NULL)
ZEND_END_ARG_INFO()

#define arginfo_class_DDTrace_SpanData_getDuration arginfo_dd_trace_dd_get_memory_limit

#define arginfo_class_DDTrace_SpanData_getStartTime arginfo_dd_trace_dd_get_memory_limit

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DDTrace_SpanData_getLink, 0, 0, DDTrace\\SpanLink, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DDTrace_SpanData_hexId arginfo_DDTrace_startup_logs


ZEND_FUNCTION(DDTrace_trace_method);
ZEND_FUNCTION(DDTrace_trace_function);
ZEND_FUNCTION(DDTrace_hook_function);
ZEND_FUNCTION(DDTrace_hook_method);
ZEND_FUNCTION(DDTrace_add_global_tag);
ZEND_FUNCTION(DDTrace_add_distributed_tag);
ZEND_FUNCTION(DDTrace_set_user);
ZEND_FUNCTION(DDTrace_close_spans_until);
ZEND_FUNCTION(DDTrace_active_span);
ZEND_FUNCTION(DDTrace_root_span);
ZEND_FUNCTION(DDTrace_start_span);
ZEND_FUNCTION(DDTrace_close_span);
ZEND_FUNCTION(DDTrace_update_span_duration);
ZEND_FUNCTION(DDTrace_start_trace_span);
ZEND_FUNCTION(DDTrace_active_stack);
ZEND_FUNCTION(DDTrace_create_stack);
ZEND_FUNCTION(DDTrace_switch_stack);
ZEND_FUNCTION(DDTrace_set_priority_sampling);
ZEND_FUNCTION(DDTrace_get_priority_sampling);
ZEND_FUNCTION(DDTrace_get_sanitized_exception_trace);
ZEND_FUNCTION(DDTrace_consume_distributed_tracing_headers);
ZEND_FUNCTION(DDTrace_generate_distributed_tracing_headers);
ZEND_FUNCTION(DDTrace_find_active_exception);
ZEND_FUNCTION(DDTrace_extract_ip_from_headers);
ZEND_FUNCTION(DDTrace_startup_logs);
ZEND_FUNCTION(DDTrace_trace_id);
ZEND_FUNCTION(DDTrace_logs_correlation_trace_id);
ZEND_FUNCTION(DDTrace_current_context);
ZEND_FUNCTION(DDTrace_set_distributed_tracing_context);
ZEND_FUNCTION(DDTrace_flush);
ZEND_FUNCTION(DDTrace_curl_multi_exec_get_request_spans);
ZEND_FUNCTION(DDTrace_System_container_id);
ZEND_FUNCTION(DDTrace_Config_integration_analytics_enabled);
ZEND_FUNCTION(DDTrace_Config_integration_analytics_sample_rate);
ZEND_FUNCTION(DDTrace_UserRequest_has_listeners);
ZEND_FUNCTION(DDTrace_UserRequest_notify_start);
ZEND_FUNCTION(DDTrace_UserRequest_notify_commit);
ZEND_FUNCTION(DDTrace_UserRequest_set_blocking_function);
ZEND_FUNCTION(DDTrace_Testing_trigger_error);
ZEND_FUNCTION(dd_trace_env_config);
ZEND_FUNCTION(dd_trace_disable_in_request);
ZEND_FUNCTION(dd_trace_reset);
ZEND_FUNCTION(dd_trace_serialize_msgpack);
ZEND_FUNCTION(dd_trace_noop);
ZEND_FUNCTION(dd_trace_dd_get_memory_limit);
ZEND_FUNCTION(dd_trace_check_memory_under_limit);
ZEND_FUNCTION(dd_tracer_circuit_breaker_register_error);
ZEND_FUNCTION(dd_tracer_circuit_breaker_register_success);
ZEND_FUNCTION(dd_tracer_circuit_breaker_can_try);
ZEND_FUNCTION(dd_tracer_circuit_breaker_info);
ZEND_FUNCTION(ddtrace_config_app_name);
ZEND_FUNCTION(ddtrace_config_distributed_tracing_enabled);
ZEND_FUNCTION(ddtrace_config_trace_enabled);
ZEND_FUNCTION(ddtrace_config_integration_enabled);
ZEND_FUNCTION(ddtrace_init);
ZEND_FUNCTION(dd_trace_send_traces_via_thread);
ZEND_FUNCTION(dd_trace_buffer_span);
ZEND_FUNCTION(dd_trace_coms_trigger_writer_flush);
ZEND_FUNCTION(dd_trace_internal_fn);
ZEND_FUNCTION(dd_trace_set_trace_id);
ZEND_FUNCTION(dd_trace_closed_spans_count);
ZEND_FUNCTION(dd_trace_tracer_is_limited);
ZEND_FUNCTION(dd_trace_compile_time_microseconds);
ZEND_FUNCTION(dd_trace_serialize_closed_spans);
ZEND_FUNCTION(dd_trace_peek_span_id);
ZEND_FUNCTION(dd_trace_close_all_spans_and_flush);
ZEND_FUNCTION(DDTrace_trace_function);
ZEND_FUNCTION(DDTrace_trace_method);
ZEND_FUNCTION(dd_untrace);
ZEND_FUNCTION(dd_trace_synchronous_flush);
ZEND_FUNCTION(dd_trace_forward_call);
ZEND_FUNCTION(dd_trace_push_span_id);
ZEND_FUNCTION(dd_trace_pop_span_id);
ZEND_FUNCTION(additional_trace_meta);
ZEND_METHOD(DDTrace_SpanLink, jsonSerialize);
ZEND_METHOD(DDTrace_SpanLink, fromHeaders);
ZEND_METHOD(DDTrace_SpanData, getDuration);
ZEND_METHOD(DDTrace_SpanData, getStartTime);
ZEND_METHOD(DDTrace_SpanData, getLink);
ZEND_METHOD(DDTrace_SpanData, hexId);


static const zend_function_entry ext_functions[] = {
	ZEND_NS_FALIAS("DDTrace", trace_method, DDTrace_trace_method, arginfo_DDTrace_trace_method)
	ZEND_NS_FALIAS("DDTrace", trace_function, DDTrace_trace_function, arginfo_DDTrace_trace_function)
	ZEND_NS_FALIAS("DDTrace", hook_function, DDTrace_hook_function, arginfo_DDTrace_hook_function)
	ZEND_NS_FALIAS("DDTrace", hook_method, DDTrace_hook_method, arginfo_DDTrace_hook_method)
	ZEND_NS_FALIAS("DDTrace", add_global_tag, DDTrace_add_global_tag, arginfo_DDTrace_add_global_tag)
	ZEND_NS_FALIAS("DDTrace", add_distributed_tag, DDTrace_add_distributed_tag, arginfo_DDTrace_add_distributed_tag)
	ZEND_NS_FALIAS("DDTrace", set_user, DDTrace_set_user, arginfo_DDTrace_set_user)
	ZEND_NS_FALIAS("DDTrace", close_spans_until, DDTrace_close_spans_until, arginfo_DDTrace_close_spans_until)
	ZEND_NS_FALIAS("DDTrace", active_span, DDTrace_active_span, arginfo_DDTrace_active_span)
	ZEND_NS_FALIAS("DDTrace", root_span, DDTrace_root_span, arginfo_DDTrace_root_span)
	ZEND_NS_FALIAS("DDTrace", start_span, DDTrace_start_span, arginfo_DDTrace_start_span)
	ZEND_NS_FALIAS("DDTrace", close_span, DDTrace_close_span, arginfo_DDTrace_close_span)
	ZEND_NS_FALIAS("DDTrace", update_span_duration, DDTrace_update_span_duration, arginfo_DDTrace_update_span_duration)
	ZEND_NS_FALIAS("DDTrace", start_trace_span, DDTrace_start_trace_span, arginfo_DDTrace_start_trace_span)
	ZEND_NS_FALIAS("DDTrace", active_stack, DDTrace_active_stack, arginfo_DDTrace_active_stack)
	ZEND_NS_FALIAS("DDTrace", create_stack, DDTrace_create_stack, arginfo_DDTrace_create_stack)
	ZEND_NS_FALIAS("DDTrace", switch_stack, DDTrace_switch_stack, arginfo_DDTrace_switch_stack)
	ZEND_NS_FALIAS("DDTrace", set_priority_sampling, DDTrace_set_priority_sampling, arginfo_DDTrace_set_priority_sampling)
	ZEND_NS_FALIAS("DDTrace", get_priority_sampling, DDTrace_get_priority_sampling, arginfo_DDTrace_get_priority_sampling)
	ZEND_NS_FALIAS("DDTrace", get_sanitized_exception_trace, DDTrace_get_sanitized_exception_trace, arginfo_DDTrace_get_sanitized_exception_trace)
	ZEND_NS_FALIAS("DDTrace", consume_distributed_tracing_headers, DDTrace_consume_distributed_tracing_headers, arginfo_DDTrace_consume_distributed_tracing_headers)
	ZEND_NS_FALIAS("DDTrace", generate_distributed_tracing_headers, DDTrace_generate_distributed_tracing_headers, arginfo_DDTrace_generate_distributed_tracing_headers)
	ZEND_NS_FALIAS("DDTrace", find_active_exception, DDTrace_find_active_exception, arginfo_DDTrace_find_active_exception)
	ZEND_NS_FALIAS("DDTrace", extract_ip_from_headers, DDTrace_extract_ip_from_headers, arginfo_DDTrace_extract_ip_from_headers)
	ZEND_NS_FALIAS("DDTrace", startup_logs, DDTrace_startup_logs, arginfo_DDTrace_startup_logs)
	ZEND_NS_FALIAS("DDTrace", trace_id, DDTrace_trace_id, arginfo_DDTrace_trace_id)
	ZEND_NS_FALIAS("DDTrace", logs_correlation_trace_id, DDTrace_logs_correlation_trace_id, arginfo_DDTrace_logs_correlation_trace_id)
	ZEND_NS_FALIAS("DDTrace", current_context, DDTrace_current_context, arginfo_DDTrace_current_context)
	ZEND_NS_FALIAS("DDTrace", set_distributed_tracing_context, DDTrace_set_distributed_tracing_context, arginfo_DDTrace_set_distributed_tracing_context)
	ZEND_NS_FALIAS("DDTrace", flush, DDTrace_flush, arginfo_DDTrace_flush)
	ZEND_NS_FALIAS("DDTrace", curl_multi_exec_get_request_spans, DDTrace_curl_multi_exec_get_request_spans, arginfo_DDTrace_curl_multi_exec_get_request_spans)
	ZEND_NS_FALIAS("DDTrace\\System", container_id, DDTrace_System_container_id, arginfo_DDTrace_System_container_id)
	ZEND_NS_FALIAS("DDTrace\\Config", integration_analytics_enabled, DDTrace_Config_integration_analytics_enabled, arginfo_DDTrace_Config_integration_analytics_enabled)
	ZEND_NS_FALIAS("DDTrace\\Config", integration_analytics_sample_rate, DDTrace_Config_integration_analytics_sample_rate, arginfo_DDTrace_Config_integration_analytics_sample_rate)
	ZEND_NS_FALIAS("DDTrace\\UserRequest", has_listeners, DDTrace_UserRequest_has_listeners, arginfo_DDTrace_UserRequest_has_listeners)
	ZEND_NS_FALIAS("DDTrace\\UserRequest", notify_start, DDTrace_UserRequest_notify_start, arginfo_DDTrace_UserRequest_notify_start)
	ZEND_NS_FALIAS("DDTrace\\UserRequest", notify_commit, DDTrace_UserRequest_notify_commit, arginfo_DDTrace_UserRequest_notify_commit)
	ZEND_NS_FALIAS("DDTrace\\UserRequest", set_blocking_function, DDTrace_UserRequest_set_blocking_function, arginfo_DDTrace_UserRequest_set_blocking_function)
	ZEND_NS_FALIAS("DDTrace\\Testing", trigger_error, DDTrace_Testing_trigger_error, arginfo_DDTrace_Testing_trigger_error)
	ZEND_FE(dd_trace_env_config, arginfo_dd_trace_env_config)
	ZEND_FE(dd_trace_disable_in_request, arginfo_dd_trace_disable_in_request)
	ZEND_FE(dd_trace_reset, arginfo_dd_trace_reset)
	ZEND_FE(dd_trace_serialize_msgpack, arginfo_dd_trace_serialize_msgpack)
	ZEND_FE(dd_trace_noop, arginfo_dd_trace_noop)
	ZEND_FE(dd_trace_dd_get_memory_limit, arginfo_dd_trace_dd_get_memory_limit)
	ZEND_FE(dd_trace_check_memory_under_limit, arginfo_dd_trace_check_memory_under_limit)
	ZEND_FE(dd_tracer_circuit_breaker_register_error, arginfo_dd_tracer_circuit_breaker_register_error)
	ZEND_FE(dd_tracer_circuit_breaker_register_success, arginfo_dd_tracer_circuit_breaker_register_success)
	ZEND_FE(dd_tracer_circuit_breaker_can_try, arginfo_dd_tracer_circuit_breaker_can_try)
	ZEND_FE(dd_tracer_circuit_breaker_info, arginfo_dd_tracer_circuit_breaker_info)
	ZEND_FE(ddtrace_config_app_name, arginfo_ddtrace_config_app_name)
	ZEND_FE(ddtrace_config_distributed_tracing_enabled, arginfo_ddtrace_config_distributed_tracing_enabled)
	ZEND_FE(ddtrace_config_trace_enabled, arginfo_ddtrace_config_trace_enabled)
	ZEND_FE(ddtrace_config_integration_enabled, arginfo_ddtrace_config_integration_enabled)
	ZEND_FE(ddtrace_init, arginfo_ddtrace_init)
	ZEND_FE(dd_trace_send_traces_via_thread, arginfo_dd_trace_send_traces_via_thread)
	ZEND_FE(dd_trace_buffer_span, arginfo_dd_trace_buffer_span)
	ZEND_FE(dd_trace_coms_trigger_writer_flush, arginfo_dd_trace_coms_trigger_writer_flush)
	ZEND_FE(dd_trace_internal_fn, arginfo_dd_trace_internal_fn)
	ZEND_FE(dd_trace_set_trace_id, arginfo_dd_trace_set_trace_id)
	ZEND_FE(dd_trace_closed_spans_count, arginfo_dd_trace_closed_spans_count)
	ZEND_FE(dd_trace_tracer_is_limited, arginfo_dd_trace_tracer_is_limited)
	ZEND_FE(dd_trace_compile_time_microseconds, arginfo_dd_trace_compile_time_microseconds)
	ZEND_FE(dd_trace_serialize_closed_spans, arginfo_dd_trace_serialize_closed_spans)
	ZEND_FE(dd_trace_peek_span_id, arginfo_dd_trace_peek_span_id)
	ZEND_FE(dd_trace_close_all_spans_and_flush, arginfo_dd_trace_close_all_spans_and_flush)
	ZEND_FALIAS(dd_trace_function, DDTrace_trace_function, arginfo_dd_trace_function)
	ZEND_FALIAS(dd_trace_method, DDTrace_trace_method, arginfo_dd_trace_method)
	ZEND_FE(dd_untrace, arginfo_dd_untrace)
	ZEND_FE(dd_trace_synchronous_flush, arginfo_dd_trace_synchronous_flush)
	ZEND_DEP_FE(dd_trace_forward_call, arginfo_dd_trace_forward_call)
	ZEND_DEP_FALIAS(dd_trace_generate_id, dd_trace_push_span_id, arginfo_dd_trace_generate_id)
	ZEND_DEP_FE(dd_trace_push_span_id, arginfo_dd_trace_push_span_id)
	ZEND_DEP_FE(dd_trace_pop_span_id, arginfo_dd_trace_pop_span_id)
	ZEND_DEP_FE(additional_trace_meta, arginfo_additional_trace_meta)
	ZEND_FE_END
};


static const zend_function_entry class_DDTrace_SpanLink_methods[] = {
	ZEND_ME(DDTrace_SpanLink, jsonSerialize, arginfo_class_DDTrace_SpanLink_jsonSerialize, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_SpanLink, fromHeaders, arginfo_class_DDTrace_SpanLink_fromHeaders, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_FE_END
};


static const zend_function_entry class_DDTrace_SpanData_methods[] = {
	ZEND_ME(DDTrace_SpanData, getDuration, arginfo_class_DDTrace_SpanData_getDuration, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_SpanData, getStartTime, arginfo_class_DDTrace_SpanData_getStartTime, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_SpanData, getLink, arginfo_class_DDTrace_SpanData_getLink, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_SpanData, hexId, arginfo_class_DDTrace_SpanData_hexId, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_DDTrace_RootSpanData_methods[] = {
	ZEND_FE_END
};


static const zend_function_entry class_DDTrace_SpanStack_methods[] = {
	ZEND_FE_END
};

static void register_ddtrace_symbols(int module_number)
{
	REGISTER_LONG_CONSTANT("DDTrace\\DBM_PROPAGATION_DISABLED", DD_TRACE_DBM_PROPAGATION_DISABLED, CONST_PERSISTENT);
	REGISTER_LONG_CONSTANT("DDTrace\\DBM_PROPAGATION_SERVICE", DD_TRACE_DBM_PROPAGATION_SERVICE, CONST_PERSISTENT);
	REGISTER_LONG_CONSTANT("DDTrace\\DBM_PROPAGATION_FULL", DD_TRACE_DBM_PROPAGATION_FULL, CONST_PERSISTENT);
	REGISTER_STRING_CONSTANT("DD_TRACE_VERSION", PHP_DDTRACE_VERSION, CONST_PERSISTENT);
	REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP", PRIORITY_SAMPLING_AUTO_KEEP, CONST_PERSISTENT);
	REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT", PRIORITY_SAMPLING_AUTO_REJECT, CONST_PERSISTENT);
	REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_USER_KEEP", PRIORITY_SAMPLING_USER_KEEP, CONST_PERSISTENT);
	REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_USER_REJECT", PRIORITY_SAMPLING_USER_REJECT, CONST_PERSISTENT);
	REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_UNKNOWN", DDTRACE_PRIORITY_SAMPLING_UNKNOWN, CONST_PERSISTENT);
	REGISTER_LONG_CONSTANT("DD_TRACE_PRIORITY_SAMPLING_UNSET", DDTRACE_PRIORITY_SAMPLING_UNSET, CONST_PERSISTENT);
}

static zend_class_entry *register_class_DDTrace_SpanLink(zend_class_entry *class_entry_JsonSerializable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DDTrace", "SpanLink", class_DDTrace_SpanLink_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	zend_class_implements(class_entry, 1, class_entry_JsonSerializable);

	zval property_traceId_default_value;
	ZVAL_UNDEF(&property_traceId_default_value);
	zend_string *property_traceId_name = zend_string_init("traceId", sizeof("traceId") - 1, 1);
	zend_declare_typed_property(class_entry, property_traceId_name, &property_traceId_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release(property_traceId_name);

	zval property_spanId_default_value;
	ZVAL_UNDEF(&property_spanId_default_value);
	zend_string *property_spanId_name = zend_string_init("spanId", sizeof("spanId") - 1, 1);
	zend_declare_typed_property(class_entry, property_spanId_name, &property_spanId_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release(property_spanId_name);

	zval property_traceState_default_value;
	ZVAL_UNDEF(&property_traceState_default_value);
	zend_string *property_traceState_name = zend_string_init("traceState", sizeof("traceState") - 1, 1);
	zend_declare_typed_property(class_entry, property_traceState_name, &property_traceState_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release(property_traceState_name);

	zval property_attributes_default_value;
	ZVAL_UNDEF(&property_attributes_default_value);
	zend_string *property_attributes_name = zend_string_init("attributes", sizeof("attributes") - 1, 1);
	zend_declare_typed_property(class_entry, property_attributes_name, &property_attributes_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
	zend_string_release(property_attributes_name);

	zval property_droppedAttributesCount_default_value;
	ZVAL_UNDEF(&property_droppedAttributesCount_default_value);
	zend_string *property_droppedAttributesCount_name = zend_string_init("droppedAttributesCount", sizeof("droppedAttributesCount") - 1, 1);
	zend_declare_typed_property(class_entry, property_droppedAttributesCount_name, &property_droppedAttributesCount_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_droppedAttributesCount_name);

	return class_entry;
}

static zend_class_entry *register_class_DDTrace_SpanData(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DDTrace", "SpanData", class_DDTrace_SpanData_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);

	zval property_name_default_value;
	ZVAL_EMPTY_STRING(&property_name_default_value);
	zend_string *property_name_name = zend_string_init("name", sizeof("name") - 1, 1);
	zend_declare_typed_property(class_entry, property_name_name, &property_name_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING|MAY_BE_NULL));
	zend_string_release(property_name_name);

	zval property_resource_default_value;
	ZVAL_EMPTY_STRING(&property_resource_default_value);
	zend_string *property_resource_name = zend_string_init("resource", sizeof("resource") - 1, 1);
	zend_declare_typed_property(class_entry, property_resource_name, &property_resource_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING|MAY_BE_NULL));
	zend_string_release(property_resource_name);

	zval property_service_default_value;
	ZVAL_EMPTY_STRING(&property_service_default_value);
	zend_string *property_service_name = zend_string_init("service", sizeof("service") - 1, 1);
	zend_declare_typed_property(class_entry, property_service_name, &property_service_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING|MAY_BE_NULL));
	zend_string_release(property_service_name);

	zval property_type_default_value;
	ZVAL_EMPTY_STRING(&property_type_default_value);
	zend_string *property_type_name = zend_string_init("type", sizeof("type") - 1, 1);
	zend_declare_typed_property(class_entry, property_type_name, &property_type_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING|MAY_BE_NULL));
	zend_string_release(property_type_name);

	zval property_meta_default_value;
	ZVAL_EMPTY_ARRAY(&property_meta_default_value);
	zend_string *property_meta_name = zend_string_init("meta", sizeof("meta") - 1, 1);
	zend_declare_typed_property(class_entry, property_meta_name, &property_meta_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
	zend_string_release(property_meta_name);

	zval property_metrics_default_value;
	ZVAL_EMPTY_ARRAY(&property_metrics_default_value);
	zend_string *property_metrics_name = zend_string_init("metrics", sizeof("metrics") - 1, 1);
	zend_declare_typed_property(class_entry, property_metrics_name, &property_metrics_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
	zend_string_release(property_metrics_name);

	zval property_exception_default_value;
	ZVAL_NULL(&property_exception_default_value);
	zend_string *property_exception_name = zend_string_init("exception", sizeof("exception") - 1, 1);
	zend_string *property_exception_class_Throwable = zend_string_init("Throwable", sizeof("Throwable")-1, 1);
	zend_declare_typed_property(class_entry, property_exception_name, &property_exception_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_CLASS(property_exception_class_Throwable, 0, MAY_BE_NULL));
	zend_string_release(property_exception_name);

	zval property_id_default_value;
	ZVAL_UNDEF(&property_id_default_value);
	zend_string *property_id_name = zend_string_init("id", sizeof("id") - 1, 1);
	zend_declare_typed_property(class_entry, property_id_name, &property_id_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release(property_id_name);

	zval property_links_default_value;
	ZVAL_EMPTY_ARRAY(&property_links_default_value);
	zend_string *property_links_name = zend_string_init("links", sizeof("links") - 1, 1);
	zend_declare_typed_property(class_entry, property_links_name, &property_links_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
	zend_string_release(property_links_name);

	zval property_peerServiceSources_default_value;
	ZVAL_EMPTY_ARRAY(&property_peerServiceSources_default_value);
	zend_string *property_peerServiceSources_name = zend_string_init("peerServiceSources", sizeof("peerServiceSources") - 1, 1);
	zend_declare_typed_property(class_entry, property_peerServiceSources_name, &property_peerServiceSources_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
	zend_string_release(property_peerServiceSources_name);

	zval property_parent_default_value;
	ZVAL_UNDEF(&property_parent_default_value);
	zend_string *property_parent_name = zend_string_init("parent", sizeof("parent") - 1, 1);
	zend_string *property_parent_class_DDTrace_SpanData = zend_string_init("DDTrace\\SpanData", sizeof("DDTrace\\SpanData")-1, 1);
	zend_declare_typed_property(class_entry, property_parent_name, &property_parent_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_CLASS(property_parent_class_DDTrace_SpanData, 0, MAY_BE_NULL));
	zend_string_release(property_parent_name);

	zval property_stack_default_value;
	ZVAL_UNDEF(&property_stack_default_value);
	zend_string *property_stack_name = zend_string_init("stack", sizeof("stack") - 1, 1);
	zend_string *property_stack_class_DDTrace_SpanStack = zend_string_init("DDTrace\\SpanStack", sizeof("DDTrace\\SpanStack")-1, 1);
	zend_declare_typed_property(class_entry, property_stack_name, &property_stack_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_CLASS(property_stack_class_DDTrace_SpanStack, 0, 0));
	zend_string_release(property_stack_name);

	return class_entry;
}

static zend_class_entry *register_class_DDTrace_RootSpanData(zend_class_entry *class_entry_DDTrace_SpanData)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DDTrace", "RootSpanData", class_DDTrace_RootSpanData_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_DDTrace_SpanData);

	zval property_origin_default_value;
	ZVAL_UNDEF(&property_origin_default_value);
	zend_string *property_origin_name = zend_string_init("origin", sizeof("origin") - 1, 1);
	zend_declare_typed_property(class_entry, property_origin_name, &property_origin_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release(property_origin_name);

	zval property_propagatedTags_default_value;
	ZVAL_EMPTY_ARRAY(&property_propagatedTags_default_value);
	zend_string *property_propagatedTags_name = zend_string_init("propagatedTags", sizeof("propagatedTags") - 1, 1);
	zend_declare_typed_property(class_entry, property_propagatedTags_name, &property_propagatedTags_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
	zend_string_release(property_propagatedTags_name);

	zval property_samplingPriority_default_value;
	ZVAL_LONG(&property_samplingPriority_default_value, DDTRACE_PRIORITY_SAMPLING_UNKNOWN);
	zend_string *property_samplingPriority_name = zend_string_init("samplingPriority", sizeof("samplingPriority") - 1, 1);
	zend_declare_typed_property(class_entry, property_samplingPriority_name, &property_samplingPriority_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_samplingPriority_name);

	zval property_propagatedSamplingPriority_default_value;
	ZVAL_UNDEF(&property_propagatedSamplingPriority_default_value);
	zend_string *property_propagatedSamplingPriority_name = zend_string_init("propagatedSamplingPriority", sizeof("propagatedSamplingPriority") - 1, 1);
	zend_declare_typed_property(class_entry, property_propagatedSamplingPriority_name, &property_propagatedSamplingPriority_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_propagatedSamplingPriority_name);

	zval property_tracestate_default_value;
	ZVAL_UNDEF(&property_tracestate_default_value);
	zend_string *property_tracestate_name = zend_string_init("tracestate", sizeof("tracestate") - 1, 1);
	zend_declare_typed_property(class_entry, property_tracestate_name, &property_tracestate_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release(property_tracestate_name);

	zval property_tracestateTags_default_value;
	ZVAL_EMPTY_ARRAY(&property_tracestateTags_default_value);
	zend_string *property_tracestateTags_name = zend_string_init("tracestateTags", sizeof("tracestateTags") - 1, 1);
	zend_declare_typed_property(class_entry, property_tracestateTags_name, &property_tracestateTags_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
	zend_string_release(property_tracestateTags_name);

	zval property_parentId_default_value;
	ZVAL_UNDEF(&property_parentId_default_value);
	zend_string *property_parentId_name = zend_string_init("parentId", sizeof("parentId") - 1, 1);
	zend_declare_typed_property(class_entry, property_parentId_name, &property_parentId_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release(property_parentId_name);

	zval property_traceId_default_value;
	ZVAL_EMPTY_STRING(&property_traceId_default_value);
	zend_string *property_traceId_name = zend_string_init("traceId", sizeof("traceId") - 1, 1);
	zend_declare_typed_property(class_entry, property_traceId_name, &property_traceId_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release(property_traceId_name);

	return class_entry;
}

static zend_class_entry *register_class_DDTrace_SpanStack(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DDTrace", "SpanStack", class_DDTrace_SpanStack_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);

	zval property_parent_default_value;
	ZVAL_UNDEF(&property_parent_default_value);
	zend_string *property_parent_name = zend_string_init("parent", sizeof("parent") - 1, 1);
	zend_string *property_parent_class_DDTrace_SpanStack = zend_string_init("DDTrace\\SpanStack", sizeof("DDTrace\\SpanStack")-1, 1);
	zend_declare_typed_property(class_entry, property_parent_name, &property_parent_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_CLASS(property_parent_class_DDTrace_SpanStack, 0, MAY_BE_NULL));
	zend_string_release(property_parent_name);

	zval property_active_default_value;
	ZVAL_NULL(&property_active_default_value);
	zend_string *property_active_name = zend_string_init("active", sizeof("active") - 1, 1);
	zend_string *property_active_class_DDTrace_SpanData = zend_string_init("DDTrace\\SpanData", sizeof("DDTrace\\SpanData")-1, 1);
	zend_declare_typed_property(class_entry, property_active_name, &property_active_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_CLASS(property_active_class_DDTrace_SpanData, 0, MAY_BE_NULL));
	zend_string_release(property_active_name);

	return class_entry;
}
