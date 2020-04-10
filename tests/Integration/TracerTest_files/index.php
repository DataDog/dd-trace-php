<?php

use DDTrace\GlobalTracer;

$uriPath = $_SERVER['REQUEST_URI'];
error_log("Received request for uri path: '$uriPath'");

if ('/override-resource' === $uriPath) {
    $tracer = GlobalTracer::get();
    $tracer->getSafeRootSpan()->setResource('custom-resource');
    error_log('/override-resource completed');
}

if ('/curl-host' === $uriPath) {
    $ch = curl_init("http://httpbin_integration/status/200");
    curl_exec($ch);
    error_log('/curl-host completed');
}

if ('/curl-ip' === $uriPath) {
    $ch = curl_init("http://127.0.0.1");
    curl_exec($ch);
    error_log('/curl-ip completed');
}

return "OK\n";
