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
define('REQUEST_NEXT_RESPONSE_FILE', getenv('REQUEST_NEXT_RESPONSE_FILE') ?: (sys_get_temp_dir() . '/response.json'));
define('REQUEST_LOG_FILE', getenv('REQUEST_LOG_FILE') ?: (sys_get_temp_dir() . '/requests-log.txt'));
define('REQUEST_RC_CONFIGS_FILE', getenv('REQUEST_RC_CONFIGS_FILE') ?: (sys_get_temp_dir() . '/rc_configs.json'));

function logRequest($message, $data = '')
{
    if (!empty($data)) {
        $message .= ":\n" . $data;
    }
    error_log(
        sprintf('[%s | %s] %s', $_SERVER['REQUEST_URI'], REQUEST_LATEST_DUMP_FILE, $message)
    );
}

set_error_handler(function ($number, $message, $errfile, $errline) {
    logRequest("Triggered error $number $message in $errfile on line $errline");
    trigger_error($message, $number);
});

$rc_configs = file_exists(REQUEST_RC_CONFIGS_FILE) ? json_decode(file_get_contents(REQUEST_RC_CONFIGS_FILE), true) : [];

switch (explode("?", $_SERVER['REQUEST_URI'])[0]) {
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
        if (!file_exists(REQUEST_LATEST_DUMP_FILE) && !file_exists(REQUEST_RC_CONFIGS_FILE)) {
            logRequest('Cannot delete request log; request log does not exist');
            break;
        }
        if (file_exists(REQUEST_RC_CONFIGS_FILE)) {
            unlink(REQUEST_RC_CONFIGS_FILE);
        }
        if (file_exists(REQUEST_LATEST_DUMP_FILE)) {
            unlink(REQUEST_LATEST_DUMP_FILE);
            unlink(REQUEST_LOG_FILE);
        }
        if (file_exists(REQUEST_NEXT_RESPONSE_FILE)) {
            unlink(REQUEST_NEXT_RESPONSE_FILE);
        }
        logRequest('Deleted request log');
        break;
    case '/next-response':
        $raw = file_get_contents('php://input');
        file_put_contents(REQUEST_NEXT_RESPONSE_FILE, $raw);
        break;
    case '/add-rc-config-file':
        $rc_configs[$_GET["path"]] = file_get_contents('php://input');
        file_put_contents(REQUEST_RC_CONFIGS_FILE, json_encode($rc_configs, JSON_UNESCAPED_SLASHES));
        break;
    case '/del-rc-config-file':
        unset($rc_configs[$_GET["path"]]);
        file_put_contents(REQUEST_RC_CONFIGS_FILE, json_encode($rc_configs, JSON_UNESCAPED_SLASHES));
        break;
    case '/v0.7/config':
        $request = file_get_contents('php://input');
        logRequest("Requested remote config", $request);
        $recentUpdate = @filemtime(REQUEST_RC_CONFIGS_FILE) > time() - 2;
        $response = [
            "roots" => [],
            "targets" => [
                "signatures" => [],
                "signed" => [
                    "_type" => "targets",
                    "custom" => [
                        "opaque_backend_state" => "foobarbaz",
                        "agent_refresh_interval" => ($recentUpdate ? 10 : 10000) * 1000000, // in ns
                    ],
                    "expires" => "9999-12-31T23:59:59Z",
                    "spec_version" => "1.0.0",
                    "targets" => new \StdClass,
                    "version" => 1,
                ],
            ],
            "target_files" => [],
            "client_configs" => [],
        ];
        foreach ($rc_configs as $path => $content) {
            $response["targets"]["signed"]["targets"]->$path = [
                "custom" => ["v" => strlen($path)],
                "hashes" => ["sha256" => hash("sha256", $content)],
                "length" => strlen($content),
            ];
            $response["target_files"][] = [
                "path" => $path,
                "raw" => base64_encode($content),
            ];
            $response["client_configs"][] = $path;
        }
        logRequest("Returned remote config", json_encode($response, JSON_UNESCAPED_SLASHES));
        $response["targets"] = base64_encode(json_encode($response["targets"], JSON_UNESCAPED_SLASHES));
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        break;
    default:
        $headers = getallheaders();
        if (isset($headers['X-Datadog-Diagnostic-Check']) || isset($headers['x-datadog-diagnostic-check'])) {
            logRequest('Received diagnostic check; ignoring');
            break;
        }

        $raw = file_get_contents('php://input');
        if ((isset($headers['Content-Type']) && $headers['Content-Type'] === 'application/msgpack')
            || (isset($headers['content-type']) && $headers['content-type'] === 'application/msgpack')) {
            // We unpack in two phases:
            //  1) using UnpackOptions::BIGINT_AS_GMP and only asserting that trace_id, span_id and parent_id are either
            //     integers (when <= PHP_INT_MAX) or GMP (when > PHP_INT_MAX);
            //  2) using UnpackOptions::BIGINT_AS_STR and storing the actual result.
            // We cannot use the first unpacked payload as when, later, we json_encode() the payload, GMPs larger than
            // PHP_INT_MAX would be serialized to PHP_INT_MAX
            $gmpUnpacker = new BufferUnpacker($raw, UnpackOptions::BIGINT_AS_GMP);
            $gmpTraces = $gmpUnpacker->unpack();
            foreach (isset($gmpTraces["chunks"]) ? $gmpTraces["chunks"] : $gmpTraces as $trace) {
                foreach (isset($trace["spans"]) ? $trace["spans"] : $trace as $span) {
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
            $traces = isset($strTraces["chunks"]) ? [&$strTraces["chunks"]] : [&$strTraces];
            foreach ($traces[0] as &$trace) {
                $spans = isset($trace["spans"]) ? [&$trace["spans"]] : [&$trace];
                foreach ($spans[0] as &$span) {
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

        if (file_exists(REQUEST_NEXT_RESPONSE_FILE)) {
            readfile(REQUEST_NEXT_RESPONSE_FILE);
            unlink(REQUEST_NEXT_RESPONSE_FILE);
        }
        break;
}
