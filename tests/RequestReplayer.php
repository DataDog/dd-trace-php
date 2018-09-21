<?php

namespace DDTrace\Tests;

use Symfony\Component\Process\Process;

/**
 * Helper class that uses PHP's built-in webserver and the Symfony Process component
 * to log HTTP requests and allow retrieving the most recent, for testing/debugging/
 * validation.
 */
class RequestReplayer
{
    /**
     * Symfony Process object managing the server
     *
     * @var Process
     */
    private $process;

    /**
     * The port to bind to
     *
     * @var int
     */
    private $port = 8500;

    /**
     * Start up a server and listen for requests to replay.
     */
    public function __construct()
    {
        $this->process = new Process('exec php -S localhost:' . $this->port . ' -t ' . __DIR__ . '/request_replayer');
        $this->process->start();
        usleep(100000);
    }

    /**
     * Return a URL that will be captured for replay
     *
     * @return string
     */
    public function getEndpoint()
    {
        return 'http://localhost:' . $this->port . '/test-request';
    }

    /**
     * Get a JSON object with details of the last request that was made to the replayer.
     *
     * @return object
     */
    public function getLastRequest()
    {
        return json_decode(file_get_contents('http://localhost:' . $this->port . '/replay'), true);
    }

    /**
     * Teardown promptly.
     */
    public function __destruct()
    {
        if ($this->process) {
            $this->process->stop(0);
        }
    }
}
