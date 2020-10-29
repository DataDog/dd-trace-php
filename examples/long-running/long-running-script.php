<?php

use DDTrace\SpanData;
use DDTrace\Type;

// This part is required for long running processes
\DDTrace\trace_function('repetitive_function', function (SpanData $span, $args) {
    /* Optional: $span->name and $span->resource
     * `name` defaults to the function/method name being traced.
     * `resource` defaults to span's name. If you set it, use something with low cardinality.
     */
    
    // Required:
    $span->service = getenv('DD_SERVICE');
    $span->type = Type::CLI; // or Type::MESSAGE_PRODUCER or anything appropriate from DDTrace\Type::
});

// This is the function that is repeated and that will be the root of your trace.
function repetitive_function()
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "httpbin/get?key=value");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    error_log('Received response: ' . var_export($output, 1));
    curl_close($ch);
}

$count = 0;
while (true) {
    repetitive_function($count++);
    sleep(1);
}
