<?php

$outerSpan = \DDTrace\root_span();
$userRequestSpan = \DDTrace\start_trace_span();
$userRequestSpan->name = 'otel_context.user_request';
$userRequestSpan->resource = 'otel_context.user_request';

\DDTrace\UserRequest\notify_start($userRequestSpan, [
    '_GET' => $_GET,
    '_POST' => $_POST,
    '_SERVER' => $_SERVER,
    '_FILES' => $_FILES,
    '_COOKIE' => $_COOKIE,
]);

$waited = \datadog\appsec\testing\wait_for_debugger();

$response = [
    'waited' => $waited,
    'trace_id' => $userRequestSpan->traceId,
    'span_id' => $userRequestSpan->hexId(),
    'local_root_span_id' => $userRequestSpan->hexId(),
    'outer_span_id' => $outerSpan ? $outerSpan->hexId() : null,
    'service_name' => $outerSpan ? $outerSpan->service : null,
    'service_version' => $outerSpan ? $outerSpan->version : null,
    'deployment_environment_name' => $outerSpan ? $outerSpan->env : null,
];

\DDTrace\UserRequest\notify_commit($userRequestSpan, 200, [
    'Content-Type' => ['application/json'],
]);
\DDTrace\close_span();

if ($outerSpan) {
    \DDTrace\switch_stack($outerSpan);
}

header('Content-Type: application/json');
echo json_encode($response);
