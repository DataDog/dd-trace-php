<?php

namespace DDTrace\Tests;

use DDTrace\Tests\Nginx\NginxServer;
use DDTrace\Tests\Sapi\CliServer\CliServer;
use DDTrace\Tests\Sapi\Frankenphp\FrankenphpServer;
use DDTrace\Tests\Sapi\OctaneServer\OctaneServer;
use DDTrace\Tests\Sapi\PhpApache\PhpApache;
use DDTrace\Tests\Sapi\PhpCgi\PhpCgi;
use DDTrace\Tests\Sapi\PhpFpm\PhpFpm;
use DDTrace\Tests\Sapi\Roadrunner\RoadrunnerServer;
use DDTrace\Tests\Sapi\Sapi;
use DDTrace\Tests\Sapi\SwooleServer\SwooleServer;
use PHPUnit\Framework\Assert;

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
        'DD_TRACE_AGENT_PORT' => 9126,
        'DD_AGENT_HOST' => 'test-agent',
        'DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS' => 1,
        // Short flush interval by default or our tests will take all day
        'DD_TRACE_AGENT_FLUSH_INTERVAL' => 111,
    ];

    /**
     * @var array
     */
    private $inis = [];

    private $roadrunnerVersion = null;
    private $isOctane = false;
    private $isFrankenphp = false;
    private $isSwoole = false;

    private $defaultInis = [
        'log_errors' => 'on',
        'datadog.trace.client_ip_header_disabled' => 'true',
    ];

    private $errorLogSize = 0;

    /**
     * Persisted apache instance for the lifetime of the testsuite - we use reload instead of restart to apply changes.
     * The primary use case is verifying repeated MINIT+MSHUTDOWN invocations within a same process.
     *
     * @var PhpApache
     */
    private static $apache = null;

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
        $this->defaultInis['datadog.trace.sources_path'] = realpath(__DIR__ .  '/../src');
        $this->host = $host;
        $this->port = $port;
    }

    public function setRoadrunner($version)
    {
        $this->roadrunnerVersion = $version;
    }

    public function setOctane()
    {
        $this->isOctane = true;
    }

    public function setSwoole()
    {
        $this->isSwoole = true;
    }

    public function setFrankenphp()
    {
        $this->isFrankenphp = true;
    }

    public function start()
    {
        if (!isset($this->envs['DD_TRACE_DEBUG'])) {
            $this->inis['datadog.trace.debug'] = 'true';
        }

        $this->errorLogSize = (int)@filesize($this->defaultInis['error_log']);

        if ($this->roadrunnerVersion) {
            $this->sapi = new RoadrunnerServer(
                $this->roadrunnerVersion,
                $this->indexFile,
                $this->host,
                $this->port,
                $this->envs,
                $this->inis
            );
        } elseif ($this->isOctane) {
            $this->sapi = new OctaneServer(
                $this->indexFile,
                $this->host,
                $this->port,
                $this->envs,
                $this->inis
            );
        } elseif ($this->isSwoole) {
            $this->sapi = new SwooleServer(
                $this->indexFile,
                $this->port,
                $this->envs,
                $this->inis
            );
        } elseif ($this->isFrankenphp) {
            $this->sapi = new FrankenphpServer(
                $this->indexFile,
                $this->host,
                $this->port,
                $this->envs,
                $this->inis
            );
        } else {
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
                case 'apache2handler':
                    if (!self::$apache) {
                        self::$apache = new PhpApache();
                        register_shutdown_function(static function () {
                            self::$apache->stop();
                            self::$apache = null;
                        });
                    }
                    self::$apache->loadConfig(
                        dirname($this->indexFile),
                        $this->host,
                        $this->port,
                        $this->envs,
                        $this->inis
                    );
                    $this->sapi = self::$apache;
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

    public function reload()
    {
        if (\method_exists($this->sapi, "reload")) {
            $this->sapi->reload();
        } else {
            Assert::markTestSkipped("Webserver reload not supported");
        }
    }

    /**
     * Teardown promptly.
     */
    public function stop()
    {
        if ($this->sapi) {
            // If we don't before stopping the server the main process might die before traces
            // are actually sent to the agent via the BGS.
            \usleep($this->envs['DD_TRACE_AGENT_FLUSH_INTERVAL'] * 2 * 1000);
            if ($this->sapi !== self::$apache) {
                $this->sapi->stop();
            }
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

    /**
     * @return string|null
     */
    public function checkErrors()
    {
        $diff = @file_get_contents($this->defaultInis['error_log'], false, null, $this->errorLogSize);
        $out = "";
        foreach (explode("\n", $diff) as $line) {
            if (preg_match("(\[ddtrace] \[(error|warn|deprecated|warning)])", $line)) {
                $out .= $line;
            }
        }
        if ($out) {
            return $out . ($this->sapi ? $this->sapi->checkErrors() : "");
        }
        return $this->sapi->checkErrors();
    }
}
