<?php

$rootSpan = \DDTrace\start_trace_span();
if (!$rootSpan) {
    http_response_code(500);
    echo json_encode(['error' => 'missing root span']);
    return;
}

$rootSpan->samplingPriority = DD_TRACE_PRIORITY_SAMPLING_USER_KEEP;
file_put_contents('/tmp/otel_context_phase', 'kept');
$keptWaited = \datadog\appsec\testing\wait_for_debugger();

$rootSpan->samplingPriority = DD_TRACE_PRIORITY_SAMPLING_USER_REJECT;
file_put_contents('/tmp/otel_context_phase', 'rejected');
$rejectedWaited = \datadog\appsec\testing\wait_for_debugger();

header('Content-Type: application/json');
echo json_encode([
    'waited' => $keptWaited && $rejectedWaited,
    'trace_id' => $rootSpan->traceId,
    'span_id' => $rootSpan->hexId(),
    'local_root_span_id' => $rootSpan->hexId(),
    'service_name' => $rootSpan->service,
    'service_version' => $rootSpan->version,
    'deployment_environment_name' => $rootSpan->env,
]);
