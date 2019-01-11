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
     * @param $rootDir
     * @param string $host
     * @param int $port
     */
    public function __construct($rootDir, $host = '0.0.0.0', $port = 80)
    {
        $this->process = new Process("exec php -S $host:$port -t $rootDir");
    }

    public function start()
    {
        $this->process->start();
        usleep(100000);
    }

    /**
     * Teardown promptly.
     */
    public function stop()
    {
        if ($this->process) {
            $this->process->stop(0);
            usleep(100000);
        }
    }
}
