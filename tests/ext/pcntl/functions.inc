<?php

\DDTrace\trace_function('call_httpbin', function ($span) {
    $span->type = 'custom';
    $span->service = 'pcntl-testing-service';
});

function call_httpbin()
{
    usleep(10000);
}
