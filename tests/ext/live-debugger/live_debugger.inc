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
    $json = [
        "id" => (string)++$probeId,
        "language" => "php",
        "evaluateAt" => "EXIT",
    ] + $data;
    return put_live_debugger_file($json);
}

function put_live_debugger_file($json) {
    $data = json_encode($json);
    $path = "datadog/2/LIVE_DEBUGGING/" . sha1($data) . "/config";
    put_rc_file($path, $data);
    return $path;
}

class DebuggerLogReplayer {
    private $rr;
    private $outstandingLogs = [];
    private $outstandingDiagnostics = [];

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
            $allDiagnostics = array_values(array_filter($allDiagnostics, function ($v) { return strpos($v["uri"], '/debugger/v1/diagnostics') === 0 && !isset($v["body"]); }));
        }
        if ($allDiagnostics) {
            $this->outstandingDiagnostics = $allDiagnostics;
            return array_shift($this->outstandingDiagnostics);
        }
        return [];
    }
}

function await_probe_installation($trigger, $num = 1) {
    ini_set("datadog.trace.hook_limit", "0");

    $ret = $trigger();

    // Wait until the expected number of live-debugger probes are actually
    // installed AND the count has *stabilised*. Remote config delivery is not
    // atomic: when the request replayer is reset and the probe configs are
    // (re-)uploaded, the sidecar can transiently publish an empty / partial
    // config set, which makes the extension remove all probes and then re-add
    // them ("churn"). Counting install events (as this helper used to do) can
    // return in the middle of that churn, before the last probe (e.g. a
    // targetSpan=ROOT probe used at the very end of a test) has been
    // re-installed — leaving that probe inactive when the test body runs.
    //
    // Instead we poll the *net* number of installed probes and only return once
    // it has been >= $num continuously for a stability window, so any churn dip
    // (count dropping below $num) resets the wait.
    $stable_since = null;
    for ($i = 0; $i < 30000; ++$i) {
        // Actively drive RC application each iteration instead of relying on a
        // VM-interrupt checkpoint happening to land inside this loop (usleep()
        // alone doesn't guarantee a pending update gets applied promptly).
        dd_trace_internal_fn("process_remote_config_now");
        usleep(1000);

        $count = (int)\dd_trace_internal_fn('live_debugger_installed_probe_count');
        if ($count >= $num) {
            if ($stable_since === null) {
                $stable_since = microtime(true);
            } elseif (microtime(true) - $stable_since >= 1.0) {
                return $ret;
            }
        } else {
            // churn in progress (a probe was (transiently) removed): keep waiting
            $stable_since = null;
        }
    }

    return $ret;
}