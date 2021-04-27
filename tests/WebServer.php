<?php

namespace DDTrace\Tests;

use DDTrace\Tests\Nginx\NginxServer;
use DDTrace\Tests\Sapi\CliServer\CliServer;
use DDTrace\Tests\Sapi\PhpCgi\PhpCgi;
use DDTrace\Tests\Sapi\PhpFpm\PhpFpm;
use DDTrace\Tests\Sapi\Sapi;

/**
 * A controllable php server running in a separate process.
 */
final class WebServer
{
    const FCGI_HOST = '0.0.0.0';
    const FCGI_PORT = 9797;

    const ERROR_LOG_NAME = 'dd_php_error.log';

    /**
     * The PHP SAPI to use
     *
     * @var Sapi
     */
    private $sapi;

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
        switch (\getenv('DD_TRACE_TEST_SAPI')) {
            case 'cgi-fcgi':
                $this->sapi = new PhpCgi(
                    self::FCGI_HOST,
                    self::FCGI_PORT,
                    $this->envs,
                    $this->inis
                );
                break;
            case 'fpm-fcgi':
                $this->sapi = new PhpFpm(
                    dirname($this->indexFile),
                    self::FCGI_HOST,
                    self::FCGI_PORT,
                    $this->envs,
                    $this->inis
                );
                break;
            default:
                $this->sapi = new CliServer(
                    $this->indexFile,
                    $this->host,
                    $this->port,
                    $this->envs,
                    $this->inis
                );
                break;
        }

        if ($this->sapi->isFastCgi()) {
            $this->server = new NginxServer(
                $this->indexFile,
                $this->host,
                $this->port,
                self::FCGI_HOST,
                self::FCGI_PORT
            );
            $this->server->start();
        }

        $this->sapi->start();
        usleep(500000);
    }

    /**
     * Teardown promptly.
     */
    public function stop()
    {
        if ($this->sapi) {
            $shouldWaitForBgs = !isset($this->envs['DD_TRACE_BGS_ENABLED']) || !$this->envs['DD_TRACE_BGS_ENABLED'];
            if ($shouldWaitForBgs) {
                // If we don't before stopping the server the main process might die before traces
                // are actually sent to the agent via the BGS.
                \usleep($this->envs['DD_TRACE_AGENT_FLUSH_INTERVAL'] * 2 * 1000);
            }
            $this->sapi->stop();
        }
        if ($this->server) {
            $this->server->stop();
        }
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
    public function mergeInis($inis)
    {
        $this->inis = array_merge($this->defaultInis, $this->inis, $inis);
        return $this;
    }
}
