<?php

$rootSpan = \DDTrace\root_span();
if (!$rootSpan) {
    http_response_code(500);
    echo json_encode(['error' => 'missing initial root span']);
    return;
}

ini_set('datadog.trace.enabled', '0');
file_put_contents('/tmp/otel_context_phase', 'disabled');
$disabledWaited = \datadog\appsec\testing\wait_for_debugger();

ini_set('datadog.trace.enabled', '1');
$rootSpan = \DDTrace\root_span();
if (!$rootSpan) {
    http_response_code(500);
    echo json_encode(['error' => 'missing reenabled root span']);
    return;
}

file_put_contents('/tmp/otel_context_phase', 'reenabled');
$reenabledWaited = \datadog\appsec\testing\wait_for_debugger();

header('Content-Type: application/json');
echo json_encode([
    'waited' => $reenabledWaited,
    'disabled_waited' => $disabledWaited,
    'trace_id' => $rootSpan->traceId,
    'span_id' => $rootSpan->hexId(),
    'local_root_span_id' => $rootSpan->hexId(),
    'service_name' => $rootSpan->service,
    'service_version' => $rootSpan->version,
    'deployment_environment_name' => $rootSpan->env,
]);
