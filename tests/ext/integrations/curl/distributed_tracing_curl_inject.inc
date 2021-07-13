<?php

namespace DDTrace\Bridge;

function curl_inject_distributed_headers($ch, array $headers)
{
    $headers[] = 'x-datadog-trace-id: ' . \DDTrace\trace_id();
    $headers[] = 'x-datadog-parent-id: ' . \dd_trace_peek_span_id();
    $headers[] = 'x-datadog-origin: phpt-test';
    \curl_setopt($ch, \CURLOPT_HTTPHEADER, $headers);
}
