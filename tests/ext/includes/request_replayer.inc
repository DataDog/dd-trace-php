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

    /**
     * @var float Wall-clock seconds before a wait loop gives up and emits diagnostic.
     * Iteration-count alone is unreliable under valgrind where each iteration takes
     * 10-20x longer than nominal; without a wall-clock cap the process is killed by
     * run-tests.php's external per-test timeout before our diagnostic can fire.
     */
    public $maxWallTimeSeconds;

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

        $this->maxIteration = 500;
        $this->maxWallTimeSeconds = 60.0;
    }

    public function waitForFlush()
    {
        usleep($this->flushInterval * 2);
    }

    public function waitForRequest($matcher)
    {
        $i = 0;
        $start = microtime(true);
        do {
            $elapsed = microtime(true) - $start;
            if ($i++ == $this->maxIteration || $elapsed > $this->maxWallTimeSeconds) {
                $this->diagnosticDump('/replay', 'waitForRequest', $i - 1, $elapsed);
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
        $start = microtime(true);
        do {
            $elapsed = microtime(true) - $start;
            if ($i++ == $this->maxIteration || $elapsed > $this->maxWallTimeSeconds) {
                $this->diagnosticDump('/replay-rc-requests', 'waitForRcRequest', $i - 1, $elapsed);
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
        $start = microtime(true);
        do {
            $elapsed = microtime(true) - $start;
            if ($i++ == $this->maxIteration || $elapsed > $this->maxWallTimeSeconds) {
                $this->diagnosticDump('/replay', 'waitForDataAndReplay', $i - 1, $elapsed);
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
        return json_decode(@file_get_contents($this->endpoint . '/replay', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ])), true);
    }

    public function replayAllStats()
    {
        return json_decode(@file_get_contents($this->endpoint . '/replay-stats', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ])), true);
    }

    public function waitForStats($matcher = null)
    {
        $i = 0;
        $start = microtime(true);
        do {
            $elapsed = microtime(true) - $start;
            if ($i++ == $this->maxIteration || $elapsed > $this->maxWallTimeSeconds) {
                $this->diagnosticDump('/replay-stats', 'waitForStats', $i - 1, $elapsed);
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
        return json_decode(@file_get_contents($this->endpoint . '/replay-rc-requests', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ])), true);
    }

    /**
     * Parse the HTTP status code from the magic $http_response_header array.
     * Returns null if the array is empty (e.g. connection refused / timeout / DNS fail).
     */
    private static function parseStatus($headers)
    {
        if (is_array($headers) && !empty($headers) && preg_match('#HTTP/\S+\s+(\d+)#', $headers[0], $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Emit a diagnostic dump to stdout when a wait-loop times out. Captures the
     * HTTP status code returned by the replayer for the test's session token, then
     * cross-checks against the same endpoint without the token, so we can tell if
     * the data we were waiting for landed in a different session bucket.
     */
    /**
     * Summarise a JSON-decoded /replay response by URI to spot data that's there
     * but filtered out by the test's logic (e.g. telemetry vs trace).
     */
    private static function summariseUris($body)
    {
        if (!is_string($body) || $body === '') {
            return '(empty body)';
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '(non-array body)';
        }
        if (count($decoded) === 0) {
            return '(empty array)';
        }
        $counts = [];
        foreach ($decoded as $entry) {
            $uri = is_array($entry) && isset($entry['uri']) ? $entry['uri'] : '(no uri)';
            $counts[$uri] = ($counts[$uri] ?? 0) + 1;
        }
        ksort($counts);
        $parts = [];
        foreach ($counts as $u => $c) {
            $parts[] = "$u=$c";
        }
        return implode(', ', $parts);
    }

    private function diagnosticDump($path, $caller, $attempts, $elapsed = null)
    {
        $token = ini_get("datadog.trace.agent_test_session_token");
        $url = $this->endpoint . $path;

        $t0 = microtime(true);
        $bodyToken = @file_get_contents($url, false, stream_context_create([
            "http" => ["header" => "X-Datadog-Test-Session-Token: $token"],
        ]));
        $tTokenMs = (microtime(true) - $t0) * 1000;
        $statusToken = self::parseStatus($http_response_header ?? null);
        $lenToken = is_string($bodyToken) ? strlen($bodyToken) : -1;
        $urisToken = self::summariseUris($bodyToken);

        $t1 = microtime(true);
        $bodyNoToken = @file_get_contents($url, false, stream_context_create([]));
        $tNoTokenMs = (microtime(true) - $t1) * 1000;
        $statusNoToken = self::parseStatus($http_response_header ?? null);
        $lenNoToken = is_string($bodyNoToken) ? strlen($bodyNoToken) : -1;

        $elapsedStr = $elapsed !== null ? sprintf(" elapsed=%.2fs", $elapsed) : "";
        echo "[RR-DIAG] $caller timeout after $attempts attempts$elapsedStr; token=" . var_export($token, true) . "\n";
        echo "[RR-DIAG]   $path with token:    status=" . var_export($statusToken, true) . " body_len=$lenToken fetch_ms=" . round($tTokenMs, 1) . "\n";
        echo "[RR-DIAG]   $path with token uris: $urisToken\n";
        echo "[RR-DIAG]   $path without token: status=" . var_export($statusNoToken, true) . " body_len=$lenNoToken fetch_ms=" . round($tNoTokenMs, 1) . "\n";
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
