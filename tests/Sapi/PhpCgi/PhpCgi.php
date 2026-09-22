<?php

namespace DDTrace\Tests\Sapi\PhpCgi;

use DDTrace\Tests\Common\EnvSerializer;
use DDTrace\Tests\Common\IniSerializer;
use DDTrace\Tests\Sapi\Sapi;
use Symfony\Component\Process\Process;

final class PhpCgi implements Sapi
{
    /**
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
     * @var array
     */
    private $envs;

    /**
     * @var array
     */
    private $inis;

    /**
     * @var string|null
     */
    private $ddprofServiceName;

    /**
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function __construct($host, $port, array $envs = [], array $inis = [], $ddprofServiceName = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->envs = $envs;
        $this->inis = $inis;
        $this->ddprofServiceName = $ddprofServiceName;
    }

    public function start()
    {
        $cmd = sprintf(
            'php-cgi %s -b %s:%d',
            new IniSerializer($this->inis),
            $this->host,
            $this->port
        );
        if ($this->ddprofServiceName !== null) {
            $url = file_exists("/var/run/datadog/apm.socket") ? "unix:///var/run/datadog/apm.socket" : "http://localhost:8126";
            $cmd = "ddprof -l debug -U $url -S {$this->ddprofServiceName} $cmd";
        }
        $envs = new EnvSerializer($this->envs);
        $processCmd = "$envs exec $cmd";

        // See phpunit_error.log in CircleCI artifacts
        error_log("[php-cgi] Starting: '$processCmd'");
        if (isset($this->inis['error_log'])) {
            error_log("[php-cgi] Error log: '" . realpath($this->inis['error_log']) . "'");
        }

        $this->process = new Process($processCmd);
        $this->process->start();
    }

    public function waitUntilServerRunning()
    {
        // php-cgi binds the FastCGI port only once module startup (MINIT) is done, which can
        // take seconds; nginx would otherwise proxy to a closed port and serve 502s.
        for ($try = 0; $try < 100; $try++) {
            $socket = @fsockopen($this->host, $this->port);
            if ($socket !== false) {
                fclose($socket);
                return true;
            }
            if (!$this->process->isRunning()) {
                // Died in startup: surface why, instead of polling a port nothing will bind.
                error_log("[php-cgi] Exited before binding: " . $this->process->getErrorOutput());
                return false;
            }
            usleep(50000);
        }

        return false;
    }

    public function stop()
    {
        error_log("[php-cgi] Stopping...");
        // Grace so ddprof, when enabled, has time to submit before the SIGKILL.
        $this->process->stop(1);
    }

    public function isFastCgi()
    {
        return true;
    }

    public function checkErrors()
    {
        $newLogs = $this->process->getIncrementalErrorOutput();
        if (preg_match("(=== Total [0-9]+ memory leaks detected ===)", $newLogs)) {
            return $newLogs;
        }

        if (!$this->process->isRunning()) {
            return "$newLogs\n<Process terminated unexpectedly>";
        }

        return null;
    }
}
