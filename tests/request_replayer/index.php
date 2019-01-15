<?php

$defaultRequestLog = '/tmp/php_request_replayer_' . getmypid() . '.json';
$requestLog = getenv('DD_REQUEST_DUMPER_FILE') ? : $defaultRequestLog;

if (php_sapi_name() == 'cli-server') {
    if ($_SERVER['REQUEST_URI'] == '/replay') {
        if (file_exists($requestLog)) {
            echo file_get_contents($requestLog);
        }
        file_put_contents($requestLog, '');
    } elseif ($_SERVER['REQUEST_URI'] == '/clear-dumped-data') {
        if (file_exists($requestLog)) {
            unlink($requestLog);
        }
    } else {
        file_put_contents($requestLog, json_encode([
            'uri' => $_SERVER['REQUEST_URI'],
            'headers' => getallheaders(),
            'body' => file_get_contents('php://input'),
        ]));
    }
}
