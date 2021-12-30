<?php

function show_curl_error_on_fail($ch)
{
    $error = curl_error($ch);
    if ($error) {
        echo 'curl error (' . curl_errno($ch) . '): ' . $error . PHP_EOL;
        var_dump(curl_getinfo($ch));
    }
}

function show_curl_multi_error_on_fail($status)
{
    if ($status != CURLM_OK) {
        echo 'curl multi error (' . $status . '): ' . curl_multi_strerror($status) . PHP_EOL;
    }
}
