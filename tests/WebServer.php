<?php

namespace DDTrace\Tests;

use DDTrace\Tests\Integrations\CLI\EnvSerializer;
use DDTrace\Tests\Integrations\CLI\IniSerializer;
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
        'DD_AGENT_HOST' => 'request-replayer',
    ];

    /**
     * @var array
     */
    private $inis = [];

    private $defaultInis = [
        'log_errors' => 'on',
        'error_log' => null,
    ];

    /**
     * @param string $indexFile
     * @param string $host
     * @param int $port
     */
    public function __construct($indexFile, $host = '0.0.0.0', $port = 80)
    {
        $this->indexFile = $indexFile;
        $this->defaultInis['error_log'] = dirname($indexFile) .  '/error.log';
        // Enable auto-instrumentation
        $this->defaultInis['ddtrace.request_init_hook'] = __DIR__ .  '/../bridge/dd_autoloader.php';
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
        $indexDirectory = dirname($this->indexFile);
        $envs = $this->getSerializedEnvsForCli();
        $inis = $this->getSerializedIniForCli();
        $cmd = "php $inis -S $host:$port -t $indexDirectory $indexFile";
        $processCmd = "$envs exec $cmd";
        $this->process = new Process($processCmd);
        $this->process->start();
        usleep(500000);
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
     * @param array $inis
     * @return WebServer
     */
    public function setInis($inis)
    {
        $this->inis = $inis;
        return $this;
    }

    /**
     * Returns the CLI compatible version of an associative array representing env variables.
     *
     * @return string
     */
    private function getSerializedEnvsForCli()
    {
        $serializer = new EnvSerializer(
            array_merge($this->defaultEnvs, $this->envs)
        );
        return (string) $serializer;
    }

    /**
     * Returns the CLI compatible version of an associative array representing ini configuration values.
     *
     * @return string
     */
    private function getSerializedIniForCli()
    {
        $serializer = new IniSerializer(
            array_merge($this->defaultInis, $this->inis)
        );
        return (string) $serializer;
    }
}
