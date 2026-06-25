<?php

$entrypointRoot = \DDTrace\root_span();
if (!$entrypointRoot) {
    http_response_code(500);
    echo json_encode(['error' => 'missing entrypoint root span']);
    return;
}

$originalService = $entrypointRoot->service;
$originalVersion = $entrypointRoot->version;
$originalEnv = $entrypointRoot->env;

$entrypointRoot->service = 'otel-thread-context-entrypoint-service';
$entrypointRoot->version = '4.5.6';
$entrypointRoot->env = 'otel-thread-context-entrypoint-env';

$nestedRoot = \DDTrace\start_trace_span();
if (!$nestedRoot) {
    http_response_code(500);
    echo json_encode(['error' => 'missing nested root span']);
    return;
}

$nestedRoot->service = 'otel-thread-context-nested-service';
$nestedRoot->version = '7.8.9';
$nestedRoot->env = 'otel-thread-context-nested-env';

$waited = \datadog\appsec\testing\wait_for_debugger();

header('Content-Type: application/json');
echo json_encode([
    'waited' => $waited,
    'trace_id' => $nestedRoot->traceId,
    'span_id' => $nestedRoot->hexId(),
    'local_root_span_id' => $nestedRoot->hexId(),
    'service_name' => $entrypointRoot->service,
    'service_version' => $entrypointRoot->version,
    'deployment_environment_name' => $entrypointRoot->env,
    'original_service_name' => $originalService,
    'original_service_version' => $originalVersion,
    'original_deployment_environment_name' => $originalEnv,
    'entrypoint_service_name' => $entrypointRoot->service,
    'entrypoint_service_version' => $entrypointRoot->version,
    'entrypoint_deployment_environment_name' => $entrypointRoot->env,
    'nested_service_name' => $nestedRoot->service,
    'nested_service_version' => $nestedRoot->version,
    'nested_deployment_environment_name' => $nestedRoot->env,
]);
