<?php

function call_httpbin($path)
{
    $port = getenv('HTTPBIN_PORT') ?: '80';
    $url = getenv('HTTPBIN_HOSTNAME') . ':' . $port . '/' . $path;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    curl_close($ch);
}
