<?php

error_reporting(\E_ALL);

include __DIR__ . '/vendor/autoload.php';

use MessagePack\BufferUnpacker;
use MessagePack\UnpackOptions;

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

set_error_handler(function ($number, $message) {
    logRequest('Triggered error ' . $number . ' ' . $message);
    trigger_error($message, $number);
});

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
            // We unpack in two phases:
            //  1) using UnpackOptions::BIGINT_AS_GMP and only asserting that trace_id, span_id and parent_id are either
            //     integers (when <= PHP_INT_MAX) or GMP (when > PHP_INT_MAX);
            //  2) using UnpackOptions::BIGINT_AS_STR and storing the actual result.
            // We cannot use the first unpacked payload as when, later, we json_encode() the payload, GMPs larger than
            // PHP_INT_MAX would be serialized to PHP_INT_MAX
            $gmpUnpacker = new BufferUnpacker($raw, UnpackOptions::BIGINT_AS_GMP);
            $gmpTraces = $gmpUnpacker->unpack();
            foreach ($gmpTraces as $trace) {
                foreach ($trace as $span) {
                    foreach (['trace_id', 'span_id', 'parent_id'] as $field) {
                        if (!isset($span[$field])) {
                            continue;
                        }

                        $value = $span[$field];
                        if (!is_int($value) && !is_a($value, 'GMP')) {
                            logRequest("Wrong type for $field: " . var_export($value, 1));
                            exit();
                        }
                    }
                }
            }

            $strUnpacker = new BufferUnpacker($raw, UnpackOptions::BIGINT_AS_STR);
            $strTraces = $strUnpacker->unpack();
            foreach ($strTraces as &$trace) {
                foreach ($trace as &$span) {
                    foreach (['trace_id', 'span_id', 'parent_id'] as $field) {
                        if (!isset($span[$field])) {
                            continue;
                        }

                        $span[$field] = (string)$span[$field];
                    }
                }
            }

            $body = json_encode($strTraces);
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
