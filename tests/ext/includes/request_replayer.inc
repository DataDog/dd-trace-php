<?php

include __DIR__ . '/dummy_filesystem_integration.inc'; //This file uses wrapped function file_get_contents

ini_set("datadog.trace.httpstream_enabled", 0);

class ProxyContainer {
    public $proc;
    private $pipes;

    public function __construct($proc, array $pipes) {
        $this->proc = $proc;
        $this->pipes = $pipes;
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

    /**
     * Serves $socketPath and forwards everything to the request-replayer, so
     * that the tracer's unix socket agent transport can be tested.
     *
     * Launches unix_socket_proxy.php.
     */
    public static function launchUnixProxy($socketPath, $upstream = "request-replayer:80") {
        @unlink($socketPath);

        // -n: run without any php.ini, so the proxy does not itself load
        // ddtrace and send agent requests into the socket it is serving.
        $cmd = PHP_BINARY . " -n " . escapeshellarg(__DIR__ . "/unix_socket_proxy.php")
            . " " . escapeshellarg($socketPath) . " " . escapeshellarg($upstream);
        // stdin is a pipe on purpose: the proxy exits once it hits EOF there,
        // which happens as soon as this process is gone. That avoids both a
        // leaked proxy and the previous inactivity timeout, which killed the
        // proxy while a test was still running.
        $proc = proc_open($cmd, [["pipe", "r"], ["pipe", "w"], STDERR], $pipes);
        if (!is_resource($proc)) {
            throw new Exception("failed to launch unix proxy for $socketPath");
        }

        if (fread($pipes[1], 1) !== "1") { // ready marker
            fclose($pipes[0]);
            proc_close($proc);
            throw new Exception("unix proxy for $socketPath failed to start");
        }

        return new ProxyContainer($proc, [$pipes[0]]);
    }
}
