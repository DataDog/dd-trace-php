<?php

include __DIR__ . '/dummy_filesystem_integration.inc'; //This file uses wrapped function file_get_contents

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

        $this->maxIteration = 500;
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
