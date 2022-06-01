<?php

include __DIR__ . '/vendor/autoload.php';

use MessagePack\MessagePack;

if ('cli-server' !== PHP_SAPI) {
    echo "For use via the CLI SAPI's built-in web server only.\n";
    exit;
}

define('REQUEST_LATEST_DUMP_FILE', getenv('REQUEST_LATEST_DUMP_FILE') ?: (sys_get_temp_dir() . '/dump.json'));
define('REQUEST_LOG_FILE', getenv('REQUEST_LOG_FILE') ?: (sys_get_temp_dir() . '/requests-log.txt'));

function logRequest($message, $data = '')
{
    if (!empty($data)) {
        $message .= ":\n" . $data;
    }
    error_log(
        sprintf('[%s | %s] %s', $_SERVER['REQUEST_URI'], REQUEST_LATEST_DUMP_FILE, $message)
    );
}

switch ($_SERVER['REQUEST_URI']) {
    case '/replay':
        if (!file_exists(REQUEST_LATEST_DUMP_FILE)) {
            logRequest('Cannot replay last request; request log does not exist');
            break;
        }
        $request = file_get_contents(REQUEST_LATEST_DUMP_FILE);
        echo $request;
        unlink(REQUEST_LATEST_DUMP_FILE);
        unlink(REQUEST_LOG_FILE);
        logRequest('Returned last request and deleted request log', $request);
        break;
    case '/clear-dumped-data':
        if (!file_exists(REQUEST_LATEST_DUMP_FILE)) {
            logRequest('Cannot delete request log; request log does not exist');
            break;
        }
        unlink(REQUEST_LATEST_DUMP_FILE);
        unlink(REQUEST_LOG_FILE);
        logRequest('Deleted request log');
        break;
    default:
        $headers = getallheaders();
        if (isset($headers['X-Datadog-Diagnostic-Check'])) {
            logRequest('Received diagnostic check; ignoring');
            break;
        }

        $raw = file_get_contents('php://input');
        if (isset($headers['Content-Type']) && $headers['Content-Type'] === 'application/msgpack') {
            $body = json_encode(MessagePack::unpack($raw));
        } else {
            $body = $raw;
        }
        if (file_exists(REQUEST_LATEST_DUMP_FILE)) {
            $tracesStack = json_decode(file_get_contents(REQUEST_LATEST_DUMP_FILE), true);
        } else {
            $tracesStack = [];
        }

        $newIncomingRequest = [
            'uri' => $_SERVER['REQUEST_URI'],
            'headers' => $headers,
            'body' => $body,
        ];

        $tracesStack[] = $newIncomingRequest;
        $newIncomingRequestJson = json_encode($newIncomingRequest);

        file_put_contents(REQUEST_LATEST_DUMP_FILE, json_encode($tracesStack));
        file_put_contents(REQUEST_LOG_FILE, $newIncomingRequestJson . "\n", FILE_APPEND);
        logRequest('Logged new request', $newIncomingRequestJson);
        break;
}
