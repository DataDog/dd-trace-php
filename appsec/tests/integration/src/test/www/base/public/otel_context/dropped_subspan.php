<?php

$rootSpan = \DDTrace\root_span();
if (!$rootSpan) {
    http_response_code(500);
    echo json_encode(['error' => 'missing root span']);
    return;
}

$childSpan = \DDTrace\start_span();
if (!$childSpan) {
    http_response_code(500);
    echo json_encode(['error' => 'missing child span']);
    return;
}

$childSpanId = $childSpan->hexId();
$dropped = \DDTrace\try_drop_span($childSpan);

$waited = \datadog\appsec\testing\wait_for_debugger();

header('Content-Type: application/json');
echo json_encode([
    'waited' => $waited,
    'dropped' => $dropped,
    'trace_id' => $rootSpan->traceId,
    'span_id' => $rootSpan->hexId(),
    'child_span_id' => $childSpanId,
    'local_root_span_id' => $rootSpan->hexId(),
    'service_name' => $rootSpan->service,
    'service_version' => $rootSpan->version,
    'deployment_environment_name' => $rootSpan->env,
]);
