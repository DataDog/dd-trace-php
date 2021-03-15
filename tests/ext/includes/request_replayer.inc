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
        return json_decode(file_get_contents($this->endpoint . '/replay'), true);
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
}
