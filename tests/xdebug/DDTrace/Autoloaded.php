<?php

namespace DDTrace;

class Autoloaded {
    public function __construct() {
        \DDTrace\trace_function('array_sum', function (\DDTrace\SpanData $span) {
            $span->name = 'array_sum';
        });
        print "Autoloader invoked\n";
    }
}
