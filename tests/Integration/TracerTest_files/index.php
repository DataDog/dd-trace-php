<?php

use DDTrace\GlobalTracer;

if ('/override-resource' === $_SERVER['REQUEST_URI']) {
    $tracer = GlobalTracer::get();
    $tracer->getSafeRootSpan()->setResource('custom-resource');
}

return "OK\n";
