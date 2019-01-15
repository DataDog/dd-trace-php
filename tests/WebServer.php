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
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $indexFile;

    /**
     * @var array
     */
    private $envs = [];

    private $defaultEnvs = [
        'DD_TRACE_AGENT_PORT' => 80,
        'DD_AGENT_HOST' => 'request_replayer',
    ];

    /**
     * @param string $indexFile
     * @param string $host
     * @param int $port
     */
    public function __construct($indexFile, $host = '0.0.0.0', $port = 80)
    {
        $this->indexFile = $indexFile;
        $this->host = $host;
        $this->port = $port;
    }

    /**
     *
     */
    public function start()
    {
        $host = $this->host;
        $port = $this->port;
        $indexFile = $this->indexFile;
        $indexFileDir = dirname($indexFile);
        $cmd = "php -dlog_errors=on -derror_log='$indexFileDir/error.log' -S $host:$port $indexFile";
        $envs = $this->getSerializedEnvsForCli();
        $processCmd = "$envs exec $cmd";
        $this->process = new Process($processCmd);
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
        }
    }

    /**
     * @param array $envs
     * @return WebServer
     */
    public function setEnvs($envs)
    {
        $this->envs = $envs;
        return $this;
    }

    /**
     * Returns the CLI compatible version of an associative array representing env variables.
     *
     * @return string
     */
    private function getSerializedEnvsForCli()
    {
        $all = array_merge($this->defaultEnvs, $this->envs);
        $forCli = [];
        foreach ($all as $name => $value) {
            $forCli[] = "$name='$value'";
        }
        return implode(' ', $forCli);
    }
}
