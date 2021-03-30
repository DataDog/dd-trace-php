<?php

if (PHP_MAJOR_VERSION !== 5) {
    if (phpversion('ddtrace') !== false) {
        \DDTrace\trace_function('some_function', function ($span) {
            $span->type = 'cli';
            $span->service = getenv('DD_SERVICE');
            $span->meta['scenario'] = 'prepend-getenv';
        });
    }
}

function some_function()
{
    $host = getenv('HTTPBIN_HOST') ?: 'localhost';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$host/get");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_exec($ch);
    curl_close($ch);
}

error_log('Script started...');

while (true) {
    /* Beginning Datadog */
    if (PHP_MAJOR_VERSION === 5 && class_exists('DDTrace\GlobalTracer')) {
        $tracer = DDTrace\GlobalTracer::get();
        $scope = $tracer->startActiveSpan('some_operation_name');
        $rootSpan = $scope->getSpan();
        $rootSpan->setResource('some_resource');
        $rootSpan->setTag(DDTrace\Tag::SERVICE_NAME, getenv('DD_SERVICE'));
        $rootSpan->setTag(DDTrace\Tag::SPAN_TYPE, DDTrace\Type::CLI);
    }
    /* End Datadog */

    // ... customer code ...
    some_function();
    // ... customer code ...

    /* Beginning Datadog */
    if ($tracer !== null) {
        $scope->close();
        $tracer->flush();
        $tracer->reset();
    }
    /* End Datadog */
}
