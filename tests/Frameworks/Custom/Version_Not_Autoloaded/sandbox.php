<?php

dd_trace_function('myWebsite', function($span) {
    $span->name = $span->resource = 'myWebsite';
    $span->type = 'web';
    $span->service = 'foo_service';
});

function myWebsite() {
    echo 'Sandbox-enabled web page' . PHP_EOL;
}

myWebsite();
