<?php

namespace {
    \DDTrace\trace_function('call_httpbin', function ($span) {
        $span->type = 'custom';
        $span->service = 'pcntl-testing-service';
    });

    function call_httpbin()
    {
        usleep(10000);
    }
}

namespace DDTrace\Integrations {
    function load_deferred_integration()
    {
    }
}
