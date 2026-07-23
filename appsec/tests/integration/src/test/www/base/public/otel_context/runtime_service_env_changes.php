<?php

$rootSpan = \DDTrace\root_span();
if (!$rootSpan) {
    http_response_code(500);
    echo json_encode(['error' => 'missing root span']);
    return;
}

ini_set('datadog.service', 'otel-thread-context-updated-service');
ini_set('datadog.version', '2.3.4');
ini_set('datadog.env', 'otel-thread-context-updated-env');

$waited = \datadog\appsec\testing\wait_for_debugger();

header('Content-Type: application/json');
echo json_encode([
    'waited' => $waited,
    'trace_id' => $rootSpan->traceId,
    'span_id' => $rootSpan->hexId(),
    'local_root_span_id' => $rootSpan->hexId(),
    'service_name' => $rootSpan->service,
    'service_version' => $rootSpan->version,
    'deployment_environment_name' => $rootSpan->env,
]);
