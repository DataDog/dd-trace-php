<?php

$entrypointRoot = \DDTrace\root_span();
if (!$entrypointRoot) {
    http_response_code(500);
    echo json_encode(['error' => 'missing entrypoint root span']);
    return;
}

$entrypointStack = \DDTrace\active_stack();

\DDTrace\switch_stack();
file_put_contents('/tmp/otel_context_phase', 'empty');
$emptyWaited = \datadog\appsec\testing\wait_for_debugger();

\DDTrace\switch_stack($entrypointStack);
file_put_contents('/tmp/otel_context_phase', 'entrypoint-restored');
$entrypointRestoredWaited = \datadog\appsec\testing\wait_for_debugger();

$nestedRoot = \DDTrace\start_trace_span();
if (!$nestedRoot) {
    http_response_code(500);
    echo json_encode(['error' => 'missing nested root span']);
    return;
}

\DDTrace\switch_stack($entrypointStack);
file_put_contents('/tmp/otel_context_phase', 'entrypoint-switched');
$entrypointSwitchedWaited = \datadog\appsec\testing\wait_for_debugger();

\DDTrace\switch_stack($nestedRoot);
file_put_contents('/tmp/otel_context_phase', 'nested-switched');
$nestedSwitchedWaited = \datadog\appsec\testing\wait_for_debugger();

header('Content-Type: application/json');
echo json_encode([
    'empty_waited' => $emptyWaited,
    'entrypoint_restored_waited' => $entrypointRestoredWaited,
    'entrypoint_switched_waited' => $entrypointSwitchedWaited,
    'nested_switched_waited' => $nestedSwitchedWaited,
    'entrypoint_trace_id' => $entrypointRoot->traceId,
    'entrypoint_span_id' => $entrypointRoot->hexId(),
    'entrypoint_local_root_span_id' => $entrypointRoot->hexId(),
    'nested_trace_id' => $nestedRoot->traceId,
    'nested_span_id' => $nestedRoot->hexId(),
    'nested_local_root_span_id' => $nestedRoot->hexId(),
    'service_name' => $entrypointRoot->service,
    'service_version' => $entrypointRoot->version,
    'deployment_environment_name' => $entrypointRoot->env,
]);
