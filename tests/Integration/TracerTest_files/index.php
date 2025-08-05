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
    $port = getenv('HTTPBIN_PORT') ?: '80';
    $url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/status/200';
    $ch = curl_init($url);
    curl_exec($ch);
    error_log('/curl-host completed');
}

if ('/curl-ip' === $uriPath) {
    $ch = curl_init("http://127.0.0.1");
    curl_exec($ch);
    error_log('/curl-ip completed');
}

echo "OK\n";
