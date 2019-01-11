<?php

namespace DDTrace\Tests;

use Symfony\Component\Process\Process;

/**
 * A controllable php server running in a separate process.
 */
class WebServer
{
    /**
     * Symfony Process object managing the server
     *
     * @var Process
     */
    private $process;

    /**
     * @param $routerFile
     * @param string $host
     * @param int $port
     */
    public function __construct($routerFile, $host = '0.0.0.0', $port = 80)
    {
        $this->process = new Process("DD_AGENT_HOST=request_dumper exec php -dlog_errors=on -derror_log='error.log' -S $host:$port $routerFile");
    }

    public function start()
    {
        $this->process->start();
        usleep(500000);
    }

    /**
     * Teardown promptly.
     */
    public function stop()
    {
        if ($this->process) {
            // because traces have to be dumped to the file, we wait some time before exiting.
            usleep(500000);
            $this->process->stop(0);
        }
    }
}
