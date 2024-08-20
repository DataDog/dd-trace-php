<?php

class RequestReplayer
{
    /**
     * @var string
     */
    private $endpoint;

    /**
     * @var int
     */
    private $flushInterval;

    public function __construct()
    {
        $this->endpoint = sprintf(
            'http://%s:%d',
            getenv('DD_AGENT_HOST') ?: 'request-replayer',
            getenv('DD_TRACE_AGENT_PORT') ?: '80'
        );

        $this->flushInterval = getenv('DD_TRACE_AGENT_FLUSH_INTERVAL')
            ? (int) getenv('DD_TRACE_AGENT_FLUSH_INTERVAL')
            : 5000;
    }

    public function waitForFlush()
    {
        usleep($this->flushInterval * 2 * 1000);
    }

    public function waitForDataAndReplay($ignoreTelemetry = true)
    {
        $i = 0;
        do {
            if ($i++ == 100) {
                throw new Exception("wait for replay timeout");
            }
            usleep($this->flushInterval * 1000);
        } while (empty($data = $this->replayRequest($ignoreTelemetry)));
        return $data;
    }

    public function replayRequest($ignoreTelemetry = false)
    {
        // Request replayer now returns as many requests as were sent during a session.
        // For the scope of the tests, we are returning the very first one.
        $allRequests = json_decode(file_get_contents($this->endpoint . '/replay', false, stream_context_create([
            "http" => [
                "header" => "X-Datadog-Test-Session-Token: " . ini_get("datadog.trace.agent_test_session_token"),
            ],
        ])), true);
        if ($allRequests && $ignoreTelemetry) {
            $allRequests = array_values(array_filter($allRequests, function ($v) { return $v["uri"] != '/telemetry/proxy/api/v2/apmtelemetry'; }));
        }
        return $allRequests ? $allRequests[0] : [];
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
if (!$client = stream_socket_accept($server, 5)) {
    return;
}
$replayer = stream_socket_client("request-replayer:80");
$all = $read = [$client, $replayer];
foreach ($read as $fp) stream_set_blocking($fp, false);
while (stream_select($read, $w, $e, null)) {
    $data = fread($fp = reset($read), 4096);
    if ($data == "") {
        return;
    }
    fwrite($fp == $replayer ? $client : $replayer, $data);
    $read = $all;
}
');

        static $unix_proxy_process_reference;
        $unix_proxy_process_reference = popen(PHP_BINARY . " -r '$code'", 'r');
        fread($unix_proxy_process_reference, 1); // ready
    }
}
