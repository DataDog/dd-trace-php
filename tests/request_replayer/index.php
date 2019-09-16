<?php

include __DIR__ . '/vendor/autoload.php';

use MessagePack\MessagePack;

if ('cli-server' !== PHP_SAPI) {
    echo "For use via the CLI SAPI's built-in web server only.\n";
    exit;
}

define('REQUEST_LOG_FILE', sys_get_temp_dir() . '/dump.json');

function logRequest($message, $data = '')
{
    if (!empty($data)) {
        $message .= ":\n" . $data;
    }
    error_log(
        sprintf('[%s | %s] %s', $_SERVER['REQUEST_URI'], REQUEST_LOG_FILE, $message)
    );
}

switch ($_SERVER['REQUEST_URI']) {
    case '/replay':
        if (!file_exists(REQUEST_LOG_FILE)) {
            logRequest('Cannot replay last request; request log does not exist');
            break;
        }
        $request = file_get_contents(REQUEST_LOG_FILE);
        echo $request;
        unlink(REQUEST_LOG_FILE);
        logRequest('Returned last request and deleted request log', $request);
        break;
    case '/clear-dumped-data':
        if (!file_exists(REQUEST_LOG_FILE)) {
            logRequest('Cannot delete request log; request log does not exist');
            break;
        }
        unlink(REQUEST_LOG_FILE);
        logRequest('Deleted request log');
        break;
    default:
        $headers = getallheaders();

        $raw = file_get_contents('php://input');
        if (isset($headers['Content-Type']) && $headers['Content-Type'] === 'application/msgpack') {
            $body = json_encode(MessagePack::unpack($raw));
        } else {
            $body = $raw;
        }
        $value = json_encode([
            'uri' => $_SERVER['REQUEST_URI'],
            'headers' => $headers,
            'body' => $body,
        ]);
        file_put_contents(REQUEST_LOG_FILE, $value);
        logRequest('Logged new request', $value);
        break;
}
