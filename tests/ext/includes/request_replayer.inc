<?php

include __DIR__ . '/dummy_filesystem_integration.inc'; //This file uses wrapped function file_get_contents

ini_set("datadog.trace.httpstream_enabled", 0);

class ProxyContainer {
    public $proc;
    public function __construct($proc) {
        $this->proc = $proc;
    }

    public function __destruct() {
        proc_terminate($this->proc, 9);
    }
}

class RequestReplayer
{
    /**
     * @var string
     */
    public $endpoint;

    /**
     * @var int
     */
    public $flushInterval;

    /**
     * @var int
     */
    public $maxIteration;

    public function __construct()
    {
        $this->endpoint = sprintf(
            'http://%s:%d',
            getenv('DD_AGENT_HOST') ?: 'request-replayer',
            getenv('DD_TRACE_AGENT_PORT') ?: '80'
        );

        $this->flushInterval = getenv('DD_TRACE_AGENT_FLUSH_INTERVAL')
            ? (int) getenv('DD_TRACE_AGENT_FLUSH_INTERVAL') * 100
            : 50000;

        // 500 iterations (~16.65s at the 33.3ms flush interval used by tests that
        // set DD_TRACE_AGENT_FLUSH_INTERVAL=333) is not a synchronization bug --
        // the mock agent single-threadedly serves all concurrent test-worker
        // sessions, and under test_extension_ci's valgrind lane the sidecar's
        // trace flusher has been observed to stall ~18s across all sessions
        // before draining its backlog, arriving ~1s after the old budget
        // expired. Data provably arrives, just later than 16.65s under
        // contention. Widen the budget to comfortably exceed that observed
        // worst case (~17.7s) with margin.
        $this->maxIteration = 900; // ~30s at the 33.3ms flush interval
    }

    public function waitForFlush()
    {
        usleep($this->flushInterval * 2);
    }

    public function waitForRequest($matcher)
    {
        $i = 0;
        do {
            if ($i++ == $this->maxIteration) {
                throw new Exception("wait for replay timeout");
            }
            usleep($this->flushInterval);

            $requests = $this->replayAllRequests();
            if (is_array($requests)) {
                foreach ($requests as $request) {
                    if ($matcher($request)) {
                        return $request;
                    }
                }
            }
        } while (true);
    }

    public function waitForRcRequest($matcher)
    {
        $i = 0;
        do {
            if ($i++ == $this->maxIteration) {
                throw new Exception("wait for replay timeout");
            }
            usleep($this->flushInterval);

            $requests = $this->replayAllRcRequests();
            if (is_array($requests)) {
                foreach ($requests as $request) {
                    if ($matcher($request)) {
                        return $request;
                    }
                }
            }
        } while (true);
    }

    public function waitForDataAndReplay($ignoreTelemetry = true)
    {
        $i = 0;
        do {
            if ($i++ == $this->maxIteration) {
                throw new Exception("wait for replay timeout");
            }
            usleep($this->flushInterval);
        } while (empty($data = $this->replayRequest($ignoreTelemetry)));
        return $data;
    }

    public function replayRequest($ignoreTelemetry = false)
    {
        // Request replayer now returns as many requests as were sent during a session.
        // For the scope of the tests, we are returning the very first one.
        $allRequests = $this->replayAllRequests();
        if ($allRequests && $ignoreTelemetry) {
            $allRequests = array_values(array_filter($allRequests, function ($v) { return $v["uri"] != '/telemetry/proxy/api/v2/apmtelemetry'; }));
        }
        return $allRequests ? $allRequests[0] : [];
    }

    public function replayAllRequests()
    {
        return json_decode(file_get_contents($this->endpoint . '/replay', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ])), true);
    }

    public function replayAllStats()
    {
        return json_decode(file_get_contents($this->endpoint . '/replay-stats', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ])), true);
    }

    public function waitForStats($matcher = null)
    {
        $i = 0;
        do {
            if ($i++ == $this->maxIteration) {
                throw new Exception("wait for stats timeout");
            }
            usleep($this->flushInterval);
            $requests = $this->replayAllStats();
            if (is_array($requests)) {
                foreach ($requests as $request) {
                    if ($matcher === null || $matcher($request)) {
                        return $request;
                    }
                }
            }
        } while (true);
    }

    public function replayAllRcRequests()
    {
        return json_decode(file_get_contents($this->endpoint . '/replay-rc-requests', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ])), true);
    }

    public function clearDumpedData()
    {
        file_get_contents($this->endpoint . '/clear-dumped-data', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ]));
    }

    public function replayHeaders($showOnly = [])
    {
        $request = $this->waitForDataAndReplay();
        if (!isset($request['headers'])) {
            return [];
        }

        ksort($request['headers']);

        $headers = [];
        foreach ($request['headers'] as $name => $value) {
            $name = strtolower($name);
            if ($showOnly && !in_array($name, $showOnly, true)) {
                continue;
            }
            $headers[$name] = $value;
        }
        return $headers;
    }

    public function setResponse($array) {
        file_get_contents($this->endpoint . '/next-response', false, stream_context_create([
            "http" => [
                "method" => "POST",
                "content" => json_encode($array),
                "header" => [
                    "Content-Type: application/json",
                    "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
                ]
            ],
        ]));
    }

    public static function launchUnixProxy($socketPath) {
        @unlink($socketPath);
        $code = str_replace("\n", "", '
ignore_user_abort(true); /* prevent bailout... */
$server = stream_socket_server("unix://' . $socketPath . '");
print "1\n"; /* ready marker */
while ($client = stream_socket_accept($server, 5)) {
    file_put_contents("/tmp/unix-proxy-' . basename($socketPath) . '", "connected\n", FILE_APPEND);
    $replayer = stream_socket_client("request-replayer:80");
    $all = $read = [$client, $replayer];
    foreach ($read as $fp) stream_set_blocking($fp, false);
    while (stream_select($read, $w, $e, null)) {
        $data = fread($fp = reset($read), 4096);
        if ($data == "") {
            file_put_contents("/tmp/unix-proxy-' . basename($socketPath) . '", "end\n", FILE_APPEND);
            break;
        }
        file_put_contents("/tmp/unix-proxy-' . basename($socketPath) . '", "$data\n", FILE_APPEND);
        fwrite($fp == $replayer ? $client : $replayer, $data);
        $read = $all;
    }
}
');

        $proc = proc_open(PHP_BINARY . " -r '$code'", [STDIN, ["pipe", "w"], STDERR], $pipes);
        fread($pipes[1], 1); // ready
        return new ProxyContainer($proc);
    }
}
