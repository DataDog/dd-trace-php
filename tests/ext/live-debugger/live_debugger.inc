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

    // The hook-install counter only proves \DDtrace\install_hook() allocated an
    // ID (synchronous, request-local bookkeeping); it does not prove the
    // RC-pushed probe was actually applied end-to-end (RC entry loaded +
    // hook wired). Cross-check against the RC-applied probe count so we don't
    // race ahead of the probe becoming observably active.
    $count_loaded_probes = function () {
        $configs = dd_trace_internal_fn("get_loaded_remote_configs");
        $count = 0;
        foreach ($configs as $entry) {
            if (($entry["type"] ?? null) === "probe") {
                ++$count;
            }
        }
        return $count;
    };

    $last_id = \DDtrace\install_hook("_dummy", function () {});
    $probes_before = $count_loaded_probes();
    $ret = $trigger();

    $installed = 0;
    for ($i = 0; $i < 15000; ++$i) {
        // Actively drive RC application each iteration instead of relying on
        // a VM-interrupt checkpoint happening to land inside this loop (the
        // same class of race as the blind-sleep issue in
        // dd_trace_internal_fn("await_remote_config"): usleep() alone doesn't
        // guarantee a pending update gets applied promptly).
        dd_trace_internal_fn("process_remote_config_now");
        usleep(1000);

        $id = \DDtrace\install_hook("_dummy", function () {});
        if ($last_id + 1 != $id) {
            $installed += $id - $last_id - 1;
            $num -= $id - $last_id - 1;
        }
        $last_id = $id;

        if ($num <= 0) {
            // The hook counter says all expected probes were requested; confirm
            // the RC-applied state reflects the same number of newly-loaded
            // probes before declaring installation complete. Give it a short
            // additional grace window if it hasn't caught up yet. This settle
            // window is best-effort and self-limiting (bounded to 2000 * 1ms):
            // it cannot recover from a probe that is genuinely never applied.
            for ($j = 0; $j < 2000; ++$j) {
                if ($count_loaded_probes() - $probes_before >= $installed) {
                    return $ret;
                }
                dd_trace_internal_fn("process_remote_config_now");
                usleep(1000);
            }
            return $ret;
        }
    }

    return $ret;
}