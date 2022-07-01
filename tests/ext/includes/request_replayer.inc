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

    public function replayRequest()
    {
        // Request replayer now returns as many requests as were sent during a session.
        // For the scope of the tests, we are returning the very first one.
        $allRequests = json_decode(file_get_contents($this->endpoint . '/replay'), true);
        return count($allRequests) == 0 ? [] : $allRequests[0];
    }

    public function replayHeaders($showOnly = [])
    {
        $request = $this->replayRequest();
        if (!isset($request['headers'])) {
            return [];
        }

        ksort($request['headers']);

        if (!$showOnly) {
            return $request['headers'];
        }

        $headers = [];
        foreach ($request['headers'] as $name => $value) {
            if (!in_array($name, $showOnly, true)) {
                continue;
            }
            $headers[$name] = $value;
        }
        return $headers;
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
