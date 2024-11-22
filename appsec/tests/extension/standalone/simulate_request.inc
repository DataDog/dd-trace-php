<?php

use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/../inc/request_replayer.inc';
include __DIR__ . '/../inc/distributed_tracing.inc';

function datadog_header($header) {
    $prefix = "x-datadog";
    return substr($header, 0, strlen($prefix)) === $prefix;
}

function datadog_query_headers() {
    $port = getenv('HTTPBIN_PORT') ?: '80';
    $url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $headers = dt_decode_headers_from_httpbin($response);
    return array_filter($headers, "datadog_header", ARRAY_FILTER_USE_KEY);
}

function simulate_request($rr)
{
    rinit();
    \DDTrace\start_span();
    $curl_headers = datadog_query_headers();
    \DDTrace\close_span();
    rshutdown();
    $root = json_decode($rr->waitForDataAndReplay()["body"], true);
    return  ['spans' => $root["chunks"][0]["spans"] ?? $root[0], 'curl_headers' => $curl_headers];
}

