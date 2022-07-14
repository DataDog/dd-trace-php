<?php

// Add instrumentation calls
\DDTrace\trace_method('datadog\\negativeclass', 'negativemethod', function () {
    echo "NegativeClass::negative_method\n";
});
\DDTrace\trace_function('datadog\\negative_function', function () {
    echo "negative_function\n";
});

// include the classes after instrumenting
require __DIR__ . '/include.php';

// call the functions after tracing them with an empty cache
Datadog\NegativeClass::negativeMethod();
Datadog\negative_function();
