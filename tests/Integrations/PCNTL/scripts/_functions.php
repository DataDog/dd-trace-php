<?php

function call_httpbin($path)
{
    $port = getenv('HTTPBIN_PORT') ?: '80';
    $url = "http://" . getenv('HTTPBIN_HOSTNAME') . ($port == 80 ? '' : ':' . $port) . '/' . $path;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    if (PHP_VERSION_ID < 80000) { curl_close($ch); }
}
