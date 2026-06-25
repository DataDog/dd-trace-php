<?php

$mainRoot = \DDTrace\root_span();
if (!$mainRoot) {
    http_response_code(500);
    echo json_encode(['error' => 'missing main root span']);
    return;
}

$state = [];
$fiber = new \Fiber(function () use (&$state) {
    $fiberRoot = \DDTrace\start_trace_span();
    if (!$fiberRoot) {
        $state['error'] = 'missing fiber root span';
        return;
    }

    file_put_contents('/tmp/otel_context_phase', 'fiber');
    $state['fiber_waited'] = \datadog\appsec\testing\wait_for_debugger();
    $state['fiber_trace_id'] = $fiberRoot->traceId;
    $state['fiber_span_id'] = $fiberRoot->hexId();
    $state['fiber_local_root_span_id'] = $fiberRoot->hexId();

    \Fiber::suspend();
    \DDTrace\close_span();
});

$fiber->start();
if (isset($state['error'])) {
    http_response_code(500);
    echo json_encode(['error' => $state['error']]);
    return;
}

file_put_contents('/tmp/otel_context_phase', 'main');
$mainWaited = \datadog\appsec\testing\wait_for_debugger();

$fiber->resume();

header('Content-Type: application/json');
echo json_encode([
    'fiber_waited' => $state['fiber_waited'] ?? false,
    'main_waited' => $mainWaited,
    'main_trace_id' => $mainRoot->traceId,
    'main_span_id' => $mainRoot->hexId(),
    'main_local_root_span_id' => $mainRoot->hexId(),
    'fiber_trace_id' => $state['fiber_trace_id'] ?? null,
    'fiber_span_id' => $state['fiber_span_id'] ?? null,
    'fiber_local_root_span_id' => $state['fiber_local_root_span_id'] ?? null,
    'service_name' => $mainRoot->service,
    'service_version' => $mainRoot->version,
    'deployment_environment_name' => $mainRoot->env,
]);
