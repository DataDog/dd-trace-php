<?php

$requestLog = '/tmp/php_request_replayer_' . getmypid() . '.json';

if (php_sapi_name() == 'cli-server') {
    if ($_SERVER['REQUEST_URI'] == '/replay') {
        if (file_exists($requestLog)) {
            echo file_get_contents($requestLog);
        }
        file_put_contents($requestLog, '');
    } else {
        file_put_contents($requestLog, json_encode([
            'headers' => getallheaders(),
            'body' => file_get_contents('php://input'),
        ]));
    }
}
