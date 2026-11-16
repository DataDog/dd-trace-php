<?php

function dt_decode_headers_from_httpbin($response)
{
    $data = json_decode($response, true);
    if (!$data || !isset($data['headers'])) {
        echo 'Invalid response:' . PHP_EOL;
        var_dump($response);
        return [];
    }
    $headers = [];
    foreach ($data['headers'] as $key => $value) {
        $headers[strtolower($key)] = mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
    }
    return $headers;
}

function dt_dump_headers_from_httpbin(array $headers, array $whitelist)
{
    foreach ($headers as $key => $value) {
        if (!in_array($key, $whitelist, true)) {
            continue;
        }
        echo $key . ': ' . $value . PHP_EOL;
    }
}
