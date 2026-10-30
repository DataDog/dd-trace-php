<?php

require __DIR__ . '/../remote_config/remote_config.inc';

function build_log_probe($data) {
    $data["type"] = "LOG_PROBE";
    return build_probe($data);
}

function build_metric_probe($data) {
    $data["type"] = "METRIC_PROBE";
    return build_probe($data);
}

function build_span_probe($data) {
    $data["type"] = "SPAN_PROBE";
    return build_probe($data);
}

function build_span_decoration_probe($data) {
    $data["type"] = "SPAN_DECORATION_PROBE";
    return build_probe($data);
}

function build_probe($data) {
    static $probeId = 0;
    $json = $data + [
        "id" => (string)++$probeId,
        "language" => "php",
    ];
    // Line probes default to evaluating before the line.
    if (!isset($data["where"]["lines"])) {
        $json += ["evaluateAt" => "EXIT"];
    }
    return put_live_debugger_file($json);
}

function put_live_debugger_file($json) {
    $data = json_encode($json);
    $path = "datadog/2/LIVE_DEBUGGING/" . sha1($data) . "/config";
    put_rc_file($path, $data);
    if (isset($GLOBALS["awaited_live_debugger_configs"])) {
        $GLOBALS["awaited_live_debugger_configs"][] = sha1($data);
    }
    return $path;
}

class DebuggerLogReplayer {
    private $rr;
    private $outstandingLogs = [];
    private $outstandingDiagnostics = [];
    private $diagnosticsRequestSummary = [];

    public function __construct() {
        require_once __DIR__ . '/../includes/request_replayer.inc';
        $this->rr = new RequestReplayer;
    }

    public function waitForDebuggerDataAndReplay()
    {
        $i = 0;
        do {
            if ($i++ == $this->rr->maxIteration) {
                throw new Exception("wait for replay timeout");
            }
            usleep($this->rr->flushInterval);
        } while (empty($data = $this->replayDebuggerData()));
        return $data;
    }

    public function replayDebuggerData()
    {
        if ($this->outstandingLogs) {
            return array_shift($this->outstandingLogs);
        }
    
        // Request replayer now returns as many requests as were sent during a session.
        // For the scope of the tests, we are returning the very first one.
        $allLogs = json_decode(file_get_contents($this->rr->endpoint . '/replay', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ])), true);
        if ($allLogs) {
            $allLogs = array_values(array_filter($allLogs, function ($v) { return (strpos($v["uri"], '/debugger/v1/diagnostics') === 0 && isset($v["body"])) || strpos($v["uri"], '/debugger/v1/input') === 0 || strpos($v["uri"], '/debugger/v2/input') === 0; }));
        }
        if ($allLogs) {
            $this->outstandingLogs = $allLogs;
            return array_shift($this->outstandingLogs);
        }
        return [];
    }

    public function waitForDiagnosticsDataAndReplay()
    {
        $i = 0;
        do {
            if ($i++ == $this->rr->maxIteration) {
                throw new Exception("wait for replay timeout");
            }
            usleep($this->rr->flushInterval);
        } while (empty($data = $this->replayDiagnosticsData()));
        return $data;
    }

    public function replayDiagnosticsData()
    {
        if ($this->outstandingDiagnostics) {
            return array_shift($this->outstandingDiagnostics);
        }
    
        // Request replayer now returns as many requests as were sent during a session.
        // For the scope of the tests, we are returning the very first one.
        $allDiagnostics = json_decode(file_get_contents($this->rr->endpoint . '/replay', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ])), true);
        if ($allDiagnostics) {
            foreach ($allDiagnostics as $request) {
                $uri = $request["uri"] ?? "<missing>";
                if (strpos($uri, '/debugger/') === 0 && count($this->diagnosticsRequestSummary) < 20) {
                    $this->diagnosticsRequestSummary[] = [
                        "uri" => $uri,
                        "has_body" => isset($request["body"]),
                        "files" => isset($request["files"]) ? array_keys($request["files"]) : [],
                    ];
                }
            }
            $allDiagnostics = array_values(array_filter($allDiagnostics, function ($v) { return strpos($v["uri"], '/debugger/v1/diagnostics') === 0 && !isset($v["body"]); }));
        }
        if ($allDiagnostics) {
            $this->outstandingDiagnostics = $allDiagnostics;
            return array_shift($this->outstandingDiagnostics);
        }
        return [];
    }

    public function getDiagnosticsRequestSummary()
    {
        return $this->diagnosticsRequestSummary;
    }
}

// Returns the id of a throwaway line hook, which reveals how many line hooks were installed since the last one, or 0 without line hook support.
function dummy_line_hook_id() {
    try {
        $id = \DDTrace\install_line_hook("_dummy", 1);
    } catch (\Error $e) {
        return 0;
    }
    \DDTrace\remove_hook($id);
    return $id;
}

// Method probes take ascending positive hook ids and line probes descending negative ones, one per where.lines entry; a gap in either sequence is an installed probe.
// Line ranges also install function hooks as guards, which inflate that count, so additionally wait until every config the trigger wrote was applied.
function await_probe_installation($trigger, $num = 1) {
    ini_set("datadog.trace.hook_limit", "0");

    $last_id = \DDtrace\install_hook("_dummy", function () {});
    $last_line_id = dummy_line_hook_id();
    $GLOBALS["awaited_live_debugger_configs"] = [];
    $ret = $trigger();
    $configs = $GLOBALS["awaited_live_debugger_configs"];
    unset($GLOBALS["awaited_live_debugger_configs"]);

    for ($i = 0; $i < 15000; ++$i) {
        usleep(1000);

        $id = \DDtrace\install_hook("_dummy", function () {});
        $line_id = dummy_line_hook_id();
        $num -= $id - $last_id - 1;
        if ($line_id) {
            $num -= $last_line_id - $line_id - 1;
        }
        $last_id = $id;
        $last_line_id = $line_id;
        if ($num <= 0) {
            // A config is listed once its probe was applied.
            $loaded = dd_trace_internal_fn("get_loaded_remote_configs");
            $configs = array_filter($configs, function ($config) use ($loaded) { return !isset($loaded[$config]); });
            if (!$configs) {
                return $ret;
            }
        }
    }

    throw new RuntimeException("Probe installation timed out; $num probe(s) missing");
}

// Collects debugger traffic until $num_snapshots snapshots arrived and every probe id in $expected_statuses reported each listed status.
// Returns ["snapshots" => probe id => snapshots in arrival order, "statuses" => probe id => statuses in lifecycle order, an ERROR carrying its message].
function await_debugger_data($num_snapshots, $expected_statuses = [], $timeout = 30) {
    require_once __DIR__ . '/../includes/request_replayer.inc';
    $rr = new RequestReplayer;
    $rank = ["RECEIVED" => 0, "INSTALLED" => 1, "EMITTING" => 2, "ERROR" => 3, "BLOCKED" => 4];
    $snapshots = [];
    $statuses = [];
    $start = time();
    while (($num_snapshots > 0 || $expected_statuses) && time() - $start < $timeout) {
        usleep($rr->flushInterval);
        $requests = json_decode(file_get_contents($rr->endpoint . '/replay', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ])), true);
        foreach ((array)$requests as $request) {
            $uri = isset($request["uri"]) ? $request["uri"] : "";
            if (strpos($uri, '/debugger/') !== 0) {
                continue;
            }
            if (isset($request["files"]["event"]["contents"])) {
                foreach ((array)json_decode($request["files"]["event"]["contents"], true) as $payload) {
                    $diagnostic = $payload["debugger"]["diagnostics"];
                    $id = $diagnostic["probeId"];
                    $status = $diagnostic["status"];
                    $statuses[$id][$status] = isset($diagnostic["exception"]["message"]) ? "$status: {$diagnostic["exception"]["message"]}" : $status;
                    if (isset($expected_statuses[$id])) {
                        $expected_statuses[$id] = array_values(array_diff($expected_statuses[$id], [$status]));
                        if (!$expected_statuses[$id]) {
                            unset($expected_statuses[$id]);
                        }
                    }
                }
            } elseif (isset($request["body"])) {
                foreach ((array)json_decode($request["body"], true) as $log) {
                    if (isset($log["debugger"]["snapshot"])) {
                        $snapshots[$log["debugger"]["snapshot"]["probe"]["id"]][] = $log;
                        --$num_snapshots;
                    }
                }
            }
        }
    }
    foreach ($statuses as &$list) {
        uksort($list, function ($a, $b) use ($rank) { return $rank[$a] - $rank[$b]; });
        $list = array_values($list);
    }
    unset($list);
    ksort($statuses);
    ksort($snapshots);
    if ($num_snapshots > 0) {
        echo "Missing $num_snapshots snapshot(s)\n";
    }
    if ($expected_statuses) {
        echo "Missing debugger diagnostic statuses:\n";
        var_dump($expected_statuses);
    }
    return ["snapshots" => $snapshots, "statuses" => $statuses];
}

// The line of the first `// line:<name>` marker in $file.
function marker_line($file, $name) {
    foreach (file($file) as $i => $line) {
        if (strpos($line, "// line:$name") !== false) {
            return $i + 1;
        }
    }
    throw new RuntimeException("No marker $name in $file");
}

// The parts of a snapshot a line probe test asserts on, with line numbers replaced by their marker names from $names (line => name).
function line_snapshot_summary($log, $names = []) {
    $name = function ($line) use ($names) {
        return isset($names[$line]) ? $names[$line] : $line;
    };
    $snapshot = $log["debugger"]["snapshot"];
    $summary = [];
    if (isset($log["message"])) {
        $summary["message"] = $log["message"];
    }
    $location = $snapshot["probe"]["location"];
    if (isset($location["lines"])) {
        foreach ($location["lines"] as &$entry) {
            $entry = implode("-", array_map($name, explode("-", $entry)));
        }
        unset($entry);
    }
    $summary["location"] = $location;
    foreach (isset($snapshot["captures"]) ? $snapshot["captures"] : [] as $kind => $captures) {
        foreach ($kind == "lines" ? $captures : [$kind => $captures] as $key => $capture) {
            $vars = [];
            foreach (["arguments", "locals", "captureExpressions"] as $section) {
                if (isset($capture[$section])) {
                    $keys = array_keys($capture[$section]);
                    sort($keys);
                    $vars[$section] = implode(", ", $keys);
                }
            }
            $summary["captures"][$kind == "lines" ? "line " . $name($key) : $kind] = $vars;
        }
    }
    if (isset($summary["captures"])) {
        ksort($summary["captures"]); // serialized from a hash map, in no particular order
    }
    if (isset($snapshot["stack"][0])) {
        $summary["top frame"] = $snapshot["stack"][0]["function"] . ":" . $name($snapshot["stack"][0]["lineNumber"]);
    }
    if (isset($snapshot["evaluationErrors"])) {
        foreach ($snapshot["evaluationErrors"] as $error) {
            $summary["errors"][] = "{$error["expr"]}: {$error["message"]}";
        }
    }
    return $summary;
}
