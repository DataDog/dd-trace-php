<?php
putenv('DD_TRACE_CLI_ENABLED=true');
putenv('DD_SPANS_LIMIT=9999999');
putenv('DD_AGENT_HOST=ddagent_integration');
putenv('DD_TRACE_DEBUG_CURL_OUTPUT=1');
putenv("DD_TRACE_DEBUG_CURL_OUTPUT=1");
putenv('DD_TRACE_BETA_SEND_TRACES_VIA_THREAD=1');
putenv('DD_TRACE_AGENT_TIMEOUT=500');
putenv('DD_TRACE_AGENT_CONNECT_TIMEOUT=500');

function_exists('dd_trace_internal_fn') && dd_trace_internal_fn('ddtrace_reload_config');

function start_span($trace_id, $parent_id)
{
    $start_time = (int) (microtime(true) * 1000 * 1000 * 1000);
    $span = [
        "name" => "test_name",
        "resource" => "test_resource",
        "service" => "test_service",
        "start" => 1518038421211969000,
        "duration" => 1,
        "error" => 0,
        "trace_id" => $trace_id,
        "parent_id" => $parent_id,
        "span_id" => (int)dd_trace_generate_id(),
        "start" => $start_time,
        "duration" => ((int) (microtime(true) * 1000 * 1000 * 1000)) - $span["start"]
    ];
    $span["duration"] = ((int) (microtime(true) * 1000 * 1000 * 1000)) - $span["start"];
    if ($span["duration"] === 0) {
        $span["duration"] = 1;
    }
    return $span;
}

$num_traces = 1000000;
$spans_in_trace = 50;

for ($trace_i = 0; $trace_i < $num_traces; $trace_i++) {
    $root_span = [
        "name" => "root_name",
        "resource" => "root_resource",
        "service" => "root_service",
        "duration" => 1,
        "error" => 0,
        "meta" => [
            "_sampling_priority_v1" => "0.5"
        ],
        "trace_id" => (int)dd_trace_generate_id(),
        "span_id" => (int)dd_trace_generate_id(),
        "start" => (int) (microtime(true) * 1000 * 1000 * 1000)
    ];

    for ($i = 0; $i < $spans_in_trace; $i++) {
        $s = start_span($root_span["trace_id"], $root_span["span_id"]);
        dd_trace_buffer_span($s);
    }
    $root_span["duration"] = ((int) (microtime(true) * 1000 * 1000 * 1000)) - $root_span["start"];
    echo "Trace: $trace_i" . PHP_EOL;
    dd_trace_buffer_span($root_span);
    dd_trace_internal_fn('increase_trace_id');
    dd_trace_internal_fn('synchronous_flush');
}
