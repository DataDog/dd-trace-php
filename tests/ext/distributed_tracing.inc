<?php

function dt_dump_headers_from_httpbin($response, array $whitelist)
{
    $data = json_decode($response, true);
    if (!$data || !isset($data['headers'])) {
        echo 'Invalid response:' . PHP_EOL;
        var_dump($response);
        return;
    }

    foreach ($data['headers'] as $key => $value) {
        $key = strtolower($key);
        if (!in_array($key, $whitelist, true)) {
            continue;
        }
        echo $key . ': ' . $value . PHP_EOL;
    }
}
