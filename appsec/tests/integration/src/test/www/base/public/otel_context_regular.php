<?php

$rootSpan = \DDTrace\root_span();
if (!$rootSpan) {
    http_response_code(500);
    echo json_encode(['error' => 'missing root span']);
    return;
}

$waited = \datadog\appsec\testing\wait_for_debugger();

header('Content-Type: application/json');
echo json_encode([
    'waited' => $waited,
    'trace_id' => $rootSpan->traceId,
    'span_id' => $rootSpan->hexId(),
    'local_root_span_id' => $rootSpan->hexId(),
]);
