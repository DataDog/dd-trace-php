<?php

namespace DDTrace\Tests;

use DDTrace\Tests\Integrations\CLI\EnvSerializer;
use DDTrace\Tests\Integrations\CLI\IniSerializer;
use DDTrace\Tests\Nginx\NginxServer;
use Symfony\Component\Process\Process;

/**
 * A controllable php server running in a separate process.
 */
final class WebServer
{
    const FCGI_HOST = '0.0.0.0';
    const FCGI_PORT = 9797;

    const ERROR_LOG_NAME = 'dd_php_error.log';

    /**
     * Symfony Process object managing the server
     *
     * @var Process
     */
    private $process;

    /**
     * Separate process for web server when running PHP as FastCGI
     *
     * @var NginxServer
     */
    private $server;

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
        'DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS' => 1,
        // Short flush interval by default or our tests will take all day
        'DD_TRACE_AGENT_FLUSH_INTERVAL' => 333,
    ];

    /**
     * @var array
     */
    private $inis = [];

    private $defaultInis = [
        'log_errors' => 'on',
        'error_log' => 'error.log',
    ];

    /**
     * @param string $indexFile
     * @param string $host
     * @param int $port
     */
    public function __construct($indexFile, $host = '0.0.0.0', $port = 80)
    {
        $this->indexFile = realpath($indexFile);
        $this->defaultInis['error_log'] = dirname($this->indexFile) .  '/' . self::ERROR_LOG_NAME;
        // Enable auto-instrumentation
        $this->defaultInis['ddtrace.request_init_hook'] = realpath(__DIR__ .  '/../bridge/dd_wrap_autoloader.php');
        $this->host = $host;
        $this->port = $port;
    }

    public function start()
    {
        if (\getenv('DD_TRACE_TEST_SAPI') === 'cgi-fcgi') {
            $this->server = new NginxServer(
                $this->indexFile,
                $this->host,
                $this->port,
                self::FCGI_HOST,
                self::FCGI_PORT
            );
            $this->server->start();

            $cmd = sprintf(
                'php-cgi %s -b %s:%d',
                $this->getSerializedIniForCli(),
                self::FCGI_HOST,
                self::FCGI_PORT
            );
        } else {
            $cmd = sprintf(
                'php %s -S %s:%d -t %s %s',
                $this->getSerializedIniForCli(),
                $this->host,
                $this->port,
                dirname($this->indexFile),
                $this->indexFile
            );
        }

        $envs = $this->getSerializedEnvsForCli();
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
            $shouldWaitForBgs = !isset($this->envs['DD_TRACE_BGS_ENABLED']) || !$this->envs['DD_TRACE_BGS_ENABLED'];
            if ($shouldWaitForBgs) {
                // If we don't before stopping the server the main process might die before traces
                // are actually sent to the agent via the BGS.
                \usleep($this->envs['DD_TRACE_AGENT_FLUSH_INTERVAL'] * 2 * 1000);
            }
            $this->process->stop(0);
        }
        if ($this->server) {
            $this->server->stop();
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
     * @param array $envs
     * @return WebServer
     */
    public function mergeEnvs($envs)
    {
        $this->envs = array_merge($this->defaultEnvs, $this->envs, $envs);
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
     * @param array $inis
     * @return WebServer
     */
    public function mergeInis($inis)
    {
        $this->inis = array_merge($this->defaultInis, $this->inis, $inis);
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
