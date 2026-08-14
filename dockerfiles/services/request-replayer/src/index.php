<?php

error_reporting(\E_ALL);

include __DIR__ . '/vendor/autoload.php';
include __DIR__ . '/state-lock.php';

use MessagePack\BufferUnpacker;
use MessagePack\UnpackOptions;

if ('cli-server' !== PHP_SAPI) {
    echo "For use via the CLI SAPI's built-in web server only.\n";
    exit;
}

function decodeDogStatsDMetrics($metrics)
{
    $metrics = array_filter($metrics);

    // Format of DogStatsD metrics: metric_name:value|type|#tag1:value1,tag2:value2
    // Parts:                      |-> 0             |-> 1|-> 2
    $decodedMetrics = [];
    foreach ($metrics as $metric) {
        $parts = explode('|', $metric);

        $nameAndValue = explode(':', $parts[0]);
        $metricName = $nameAndValue[0];
        $value = $nameAndValue[1];

        $type = $parts[1];

        $tags = [];
        if (count($parts) > 2) {
            $parts[2] = substr($parts[2], 1); // Remove leading #
            $tags = explode(',', $parts[2]);
            $tags = array_map(function ($tag) {
                return explode(':', $tag);
            }, $tags);
            $tags = array_combine(array_column($tags, 0), array_column($tags, 1));
        }
        $decodedMetrics[] = [
            'name' => $metricName,
            'value' => $value,
            'type' => $type,
            'tags' => $tags,
        ];
    }
    return $decodedMetrics;
}

$uri = explode("?", $_SERVER['REQUEST_URI'])[0];

$temp_location = sys_get_temp_dir();

if (!getenv('REQUEST_REPLAYER_METRICS_SERVER_MANAGED')) {
    $metricsServerPid = "$temp_location/metrics-server.pid";
    if (!file_exists($metricsServerPid)) {
        shell_exec("nohup bash -c 'php metricsserver.php & pid=$!; echo \$pid > $metricsServerPid; wait \$pid; rm $metricsServerPid' > /dev/null 2>&1 &");
    }
}

$token = $_SERVER["HTTP_X_DATADOG_TEST_SESSION_TOKEN"] ?? "";

if ($uri === "/metrics") {
    $decodedMetrics = decodeDogStatsDMetrics(explode("\n", trim($_GET["metrics"], "\n")));
    if (isset($decodedMetrics[0]["tags"]["x-datadog-test-session-token"])) {
        $token = $decodedMetrics[0]["tags"]["x-datadog-test-session-token"];
        unset($decodedMetrics[0]["tags"]["x-datadog-test-session-token"]);
    }
}

if ($token != "") {
    $token = str_replace("/", "-", $token);
    $temp_location .= "/token-$token";
    @mkdir($temp_location);
}

define('REQUEST_LATEST_DUMP_FILE', getenv('REQUEST_LATEST_DUMP_FILE') ?: ("$temp_location/dump.json"));
define('REQUEST_NEXT_RESPONSE_FILE', getenv('REQUEST_NEXT_RESPONSE_FILE') ?: ("$temp_location/response.json"));
define('REQUEST_LOG_FILE', getenv('REQUEST_LOG_FILE') ?: ("$temp_location/requests-log.txt"));
define('REQUEST_RC_CONFIGS_FILE', getenv('REQUEST_RC_CONFIGS_FILE') ?: ("$temp_location/rc_configs.json"));
define('REQUEST_RC_REQUESTS_FILE', getenv('REQUEST_RC_REQUESTS_FILE') ?: ("$temp_location/rc_requests.json"));
define('REQUEST_METRICS_FILE', getenv('REQUEST_METRICS_FILE') ?: ("$temp_location/metrics.json"));
define('REQUEST_METRICS_LOG_FILE', getenv('REQUEST_METRICS_LOG_FILE') ?: ("$temp_location/metrics-log.txt"));
define('REQUEST_STATS_FILE', getenv('REQUEST_STATS_FILE') ?: ("$temp_location/stats.json"));
define('REQUEST_AGENT_INFO_FILE', getenv('REQUEST_AGENT_INFO_FILE') ?: ("$temp_location/agent-info.txt"));
define('REQUEST_STATE_LOCK_FILE', "$temp_location/.state.lock");

function logRequest($message, $data = '')
{
    global $token;
    if (!empty($data)) {
        $message .= ":\n" . $data;
    }
    error_log(
        sprintf('[%s | %s%s] %s', $_SERVER['REQUEST_URI'], REQUEST_LATEST_DUMP_FILE, $token == "" ? "" : " | $token", $message)
    );
}

set_error_handler(function ($number, $message, $errfile, $errline) {
    if (!($number & error_reporting())) {
        return true;
    }
    logRequest("Triggered error $number $message in $errfile on line $errline: " . (new \Exception)->getTraceAsString());
    trigger_error($message, $number);
});

switch ($uri) {
    case '/replay':
        $request = withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () {
            if (!file_exists(REQUEST_LATEST_DUMP_FILE)) {
                logRequest('Cannot replay last request; request log does not exist');
                return null;
            }
            $request = file_get_contents(REQUEST_LATEST_DUMP_FILE);
            unlink(REQUEST_LATEST_DUMP_FILE);
            unlink(REQUEST_LOG_FILE);
            logRequest('Returned last request and deleted request log', $request);
            return $request;
        });
        echo $request ?? '';
        break;
    case '/replay-metrics':
        $request = withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () {
            if (!file_exists(REQUEST_METRICS_FILE)) {
                logRequest('Cannot replay last request; metrics log does not exist');
                return null;
            }
            $request = file_get_contents(REQUEST_METRICS_FILE);
            unlink(REQUEST_METRICS_FILE);
            unlink(REQUEST_METRICS_LOG_FILE);
            logRequest('Returned last metrics and deleted metrics log', $request);
            return $request;
        });
        echo $request ?? '';
        break;
    case '/replay-stats':
        $request = withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () {
            if (!file_exists(REQUEST_STATS_FILE)) {
                logRequest('Cannot replay stats; stats log does not exist');
                return null;
            }
            $request = file_get_contents(REQUEST_STATS_FILE);
            unlink(REQUEST_STATS_FILE);
            logRequest('Returned stats and deleted stats log', $request);
            return $request;
        });
        echo $request ?? '';
        break;
    case '/replay-rc-requests':
        $request = withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () {
            if (!file_exists(REQUEST_RC_REQUESTS_FILE)) {
                logRequest('Cannot replay RC requests; RC requests log does not exist');
                return null;
            }
            $request = file_get_contents(REQUEST_RC_REQUESTS_FILE);
            unlink(REQUEST_RC_REQUESTS_FILE);
            logRequest('Returned RC requests and deleted RC requests log', $request);
            return $request;
        });
        echo $request ?? '';
        break;
    case '/clear-dumped-data':
        withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () {
            if (!file_exists(REQUEST_LATEST_DUMP_FILE) && !file_exists(REQUEST_METRICS_FILE) && !file_exists(REQUEST_RC_CONFIGS_FILE)) {
                logRequest('Cannot delete request log; request log does not exist');
                return;
            }
            foreach ([
                REQUEST_RC_CONFIGS_FILE,
                REQUEST_LATEST_DUMP_FILE,
                REQUEST_LOG_FILE,
                REQUEST_METRICS_FILE,
                REQUEST_METRICS_LOG_FILE,
                REQUEST_STATS_FILE,
                REQUEST_NEXT_RESPONSE_FILE,
                REQUEST_AGENT_INFO_FILE,
                REQUEST_RC_REQUESTS_FILE,
            ] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            logRequest('Deleted request log');
        });
        break;
    case '/next-response':
        $raw = file_get_contents('php://input');
        withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () use ($raw) {
            file_put_contents(REQUEST_NEXT_RESPONSE_FILE, $raw);
        });
        break;
    case '/add-rc-config-file':
        $raw = file_get_contents('php://input');
        withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () use ($raw) {
            $rc_configs = file_exists(REQUEST_RC_CONFIGS_FILE) ? json_decode(file_get_contents(REQUEST_RC_CONFIGS_FILE), true) : [];
            $rc_configs[$_GET["path"]] = ["service" => $_GET["service"], "data" => $raw];
            file_put_contents(REQUEST_RC_CONFIGS_FILE, json_encode($rc_configs, JSON_UNESCAPED_SLASHES));
        });
        break;
    case '/del-rc-config-file':
        withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () {
            $rc_configs = file_exists(REQUEST_RC_CONFIGS_FILE) ? json_decode(file_get_contents(REQUEST_RC_CONFIGS_FILE), true) : [];
            unset($rc_configs[$_GET["path"]]);
            file_put_contents(REQUEST_RC_CONFIGS_FILE, json_encode($rc_configs, JSON_UNESCAPED_SLASHES));
        });
        break;
    case '/v0.7/config':
        $request = file_get_contents('php://input');
        logRequest("Requested remote config", $request);
        $response = withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () use ($request) {
            $tracesStack = file_exists(REQUEST_RC_REQUESTS_FILE) ? json_decode(file_get_contents(REQUEST_RC_REQUESTS_FILE), true) : [];
            $tracesStack[] = ['uri' => $_SERVER['REQUEST_URI'], 'headers' => getallheaders(), 'body' => $request];
            file_put_contents(REQUEST_RC_REQUESTS_FILE, json_encode($tracesStack));

            $decodedRequest = json_decode($request, true);
            $rc_configs = file_exists(REQUEST_RC_CONFIGS_FILE) ? json_decode(file_get_contents(REQUEST_RC_CONFIGS_FILE), true) : [];
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
            foreach ($rc_configs as $path => $config) {
                if ($config["service"] == $decodedRequest["client"]["client_tracer"]["service"]) {
                    $content = $config["data"];
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
            }
            return $response;
        });
        logRequest("Returned remote config", json_encode($response, JSON_UNESCAPED_SLASHES));
        $response["targets"] = base64_encode(json_encode($response["targets"], JSON_UNESCAPED_SLASHES));
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        break;
    case "/metrics":
        $_SERVER['REQUEST_URI'] = $uri;
        logRequest('Logged new metrics', json_encode($decodedMetrics));
        withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () use ($decodedMetrics) {
            $allMetrics = file_exists(REQUEST_METRICS_FILE) ? json_decode(file_get_contents(REQUEST_METRICS_FILE), true) : [];
            foreach ($decodedMetrics as $metric) {
                file_put_contents(REQUEST_METRICS_LOG_FILE, json_encode($metric) . "\n", FILE_APPEND);
                $allMetrics[] = $metric;
            }
            file_put_contents(REQUEST_METRICS_FILE, json_encode($allMetrics));
        });
        break;
    case '/set-agent-info':
        $raw = file_get_contents('php://input');
        withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () use ($raw) {
            file_put_contents(REQUEST_AGENT_INFO_FILE, $raw);
        });
        break;
    case '/info':
        $file = withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () {
            return @file_get_contents(REQUEST_AGENT_INFO_FILE) ?: "{}";
        });
        logRequest('Requested /info endpoint, returning ' . $file);
        header("datadog-agent-state: " . sha1($file));
        echo $file;
        break;
    case '/v0.6/stats':
        $raw = file_get_contents('php://input');
        $unpacker = new BufferUnpacker($raw, UnpackOptions::BIGINT_AS_STR);
        $decoded = $unpacker->unpack();
        // OkSummary and ErrorSummary are binary DDSketch protobuf bytes; hex-encode them so
        // json_encode does not fail on non-UTF-8 data.
        $hexEncodeBinaryStats = function (&$arr) use (&$hexEncodeBinaryStats) {
            if (!is_array($arr)) return;
            foreach ($arr as $key => &$value) {
                if (($key === 'OkSummary' || $key === 'ErrorSummary') && is_string($value)) {
                    $value = bin2hex($value);
                } elseif (is_array($value)) {
                    $hexEncodeBinaryStats($value);
                }
            }
        };
        $hexEncodeBinaryStats($decoded);
        $body = json_encode($decoded);
        $newStatsRequest = [
            'uri' => $_SERVER['REQUEST_URI'],
            'headers' => getallheaders(),
            'body' => $body,
        ];
        withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () use ($newStatsRequest) {
            $statsStack = file_exists(REQUEST_STATS_FILE) ? json_decode(file_get_contents(REQUEST_STATS_FILE), true) : [];
            $statsStack[] = $newStatsRequest;
            file_put_contents(REQUEST_STATS_FILE, json_encode($statsStack));
        });
        logRequest('Logged stats request', $body);
        break;
    default:
        $headers = getallheaders();
        if (isset($headers['X-Datadog-Diagnostic-Check']) || isset($headers['x-datadog-diagnostic-check'])) {
            logRequest('Received diagnostic check; ignoring');
            break;
        }

        $newIncomingRequest = [
            'uri' => $_SERVER['REQUEST_URI'],
            'headers' => $headers,
        ];

        if (!empty($_FILES)) {
            $newIncomingRequest["files"] = $_FILES;
            foreach ($newIncomingRequest["files"] as &$file) {
                $file["contents"] = file_get_contents($file["tmp_name"]);
                unset($file["tmp_name"]);
            }
        } else {
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

            $newIncomingRequest["body"] = $body;
        }
        $newIncomingRequestJson = json_encode($newIncomingRequest);
        $nextResponse = withRequestReplayerStateLock(REQUEST_STATE_LOCK_FILE, function () use ($newIncomingRequest, $newIncomingRequestJson) {
            $tracesStack = file_exists(REQUEST_LATEST_DUMP_FILE) ? json_decode(file_get_contents(REQUEST_LATEST_DUMP_FILE), true) : [];
            $tracesStack[] = $newIncomingRequest;
            file_put_contents(REQUEST_LATEST_DUMP_FILE, json_encode($tracesStack));
            file_put_contents(REQUEST_LOG_FILE, $newIncomingRequestJson . "\n", FILE_APPEND);
            logRequest('Logged new request', $newIncomingRequestJson);

            if (!file_exists(REQUEST_NEXT_RESPONSE_FILE)) {
                return null;
            }
            $response = file_get_contents(REQUEST_NEXT_RESPONSE_FILE);
            unlink(REQUEST_NEXT_RESPONSE_FILE);
            return $response;
        });
        echo $nextResponse ?? '';
        break;
}
