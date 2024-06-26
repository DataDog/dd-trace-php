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

    public function __construct() {
        require_once __DIR__ . '/../includes/request_replayer.inc';
        $this->rr = new RequestReplayer;
    }

    public function waitForDebuggerDataAndReplay()
    {
        $i = 0;
        do {
            if ($i++ == 100) {
                throw new Exception("wait for replay timeout");
            }
            usleep($this->rr->flushInterval * 1000);
        } while (empty($data = $this->replayDebuggerData()));
        return $data;
    }

    public function replayDebuggerData()
    {
        // Request replayer now returns as many requests as were sent during a session.
        // For the scope of the tests, we are returning the very first one.
        $allLogs = json_decode(file_get_contents($this->rr->endpoint . '/replay'), true);
        if ($allLogs) {
            $allLogs = array_values(array_filter($allLogs, function ($v) { return strpos($v["uri"], '/debugger/v1/input') === 0; }));
        }
        return $allLogs ? $allLogs[0] : [];
    }

    public function waitForDiagnosticsDataAndReplay()
    {
        $i = 0;
        do {
            if ($i++ == 100) {
                throw new Exception("wait for replay timeout");
            }
            usleep($this->rr->flushInterval * 1000);
        } while (empty($data = $this->replayDiagnosticsData()));
        return $data;
    }

    public function replayDiagnosticsData()
    {
        // Request replayer now returns as many requests as were sent during a session.
        // For the scope of the tests, we are returning the very first one.
        $allLogs = json_decode(file_get_contents($this->rr->endpoint . '/replay'), true);
        if ($allLogs) {
            $allLogs = array_values(array_filter($allLogs, function ($v) { return strpos($v["uri"], '/debugger/v1/diagnostics') === 0; }));
        }
        return $allLogs ? $allLogs[0] : [];
    }
}

function await_probe_installation($trigger) {
    ini_set("datadog.trace.hook_limit", "0");

    $last_id = \DDtrace\install_hook("_dummy", function () {});
    $ret = $trigger();

    for ($i = 0; $i < 6000; ++$i) {
        usleep(1000);

        $id = \DDtrace\install_hook("_dummy", function () {});
        if ($last_id + 1 != $id) {
            return $ret;
        }
        $last_id = $id;
    }

    return $ret;
}