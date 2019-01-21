<?php

$filename = basename(getenv('DD_REQUEST_DUMPER_FILE') ?: 'php_request_replayer_' . getmypid() . '.json');
$requestLog = sys_get_temp_dir() . '/' . $filename;

if (php_sapi_name() == 'cli-server') {
    if ($_SERVER['REQUEST_URI'] == '/replay') {
        if (file_exists($requestLog)) {
            $value = file_get_contents($requestLog);
            error_log("Returning value from $requestLog: " . print_r($value, true));
            echo $value;
        } else {
            error_log("No value to replay in $requestLog");
        }
        file_put_contents($requestLog, '');
    } elseif ($_SERVER['REQUEST_URI'] == '/clear-dumped-data') {
        if (file_exists($requestLog)) {
            error_log("Clearing dumped data in $requestLog");
            unlink($requestLog);
        }
    } else {
        $value = json_encode([
            'uri' => $_SERVER['REQUEST_URI'],
            'headers' => getallheaders(),
            'body' => file_get_contents('php://input'),
        ]);
        error_log("Dumping data in $requestLog: $value");
        file_put_contents($requestLog, $value);
    }
}
