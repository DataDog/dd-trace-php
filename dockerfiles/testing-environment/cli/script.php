<?php

// Temporarily required on PHP 5
if (phpversion('ddtrace') !== false) {
    \DDTrace\trace_function('entrypoint_function', function ($span) {
        $span->type = 'cli';
        $span->service = getenv('DD_SERVICE');
        $span->meta['scenario'] = 'prepend-getenv';
    });
}


function entrypoint_function()
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'httpbin/get?client=curl');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_exec($ch);
    curl_close($ch);
}

error_log('Script started...');

while (true) {
    entrypoint_function();
    if (PHP_MAJOR_VERSION < 7
            && class_exists('DDTrace\GlobalTracer')
            && is_a($tracer = \DDTrace\GlobalTracer::get(), 'DDTrace\Tracer')
    ) {
        // Temporarily required on PHP 5
        $tracer->flush();
        $tracer->reset();
    }
    usleep(/* 100ms */ 100 * 1000);
}
