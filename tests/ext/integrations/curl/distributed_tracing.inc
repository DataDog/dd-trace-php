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
        $headers[strtolower($key)] = $value;
    }
    return $headers;
}

function dt_dump_headers_from_httpbin(array $headers, array $whitelist)
{
    foreach ($headers as $key => $value) {
        if (!in_array($key, $whitelist, true)) {
            continue;
        }
        // go-httpbin returns values in arrays to include this use case:
        // https://github.com/golang/go/blob/cf7ec0fa098a46c3b75cc3d625f5d7528fe6e984/src/net/http/request.go#L138-L152
        echo $key . ': ' . implode("|", $value) . PHP_EOL;
    }
}
