<?php

$rootSpan = \DDTrace\root_span();
if (!$rootSpan) {
    http_response_code(500);
    echo json_encode(['error' => 'missing root span']);
    return;
}

$originalTraceId = $rootSpan->traceId;
\DDTrace\set_distributed_tracing_context('22685491128062564232121423433698517538', '42');

$waited = \datadog\appsec\testing\wait_for_debugger();

header('Content-Type: application/json');
echo json_encode([
    'waited' => $waited,
    'original_trace_id' => $originalTraceId,
    'trace_id' => $rootSpan->traceId,
    'span_id' => $rootSpan->hexId(),
    'local_root_span_id' => $rootSpan->hexId(),
    'service_name' => $rootSpan->service,
    'service_version' => $rootSpan->version,
    'deployment_environment_name' => $rootSpan->env,
]);
