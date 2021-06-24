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
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function __construct($host, $port, array $envs = [], array $inis = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->envs = $envs;
        $this->inis = $inis;
    }

    public function start()
    {
        $cmd = sprintf(
            'php-cgi %s -b %s:%d',
            new IniSerializer($this->inis),
            $this->host,
            $this->port
        );
        $envs = new EnvSerializer($this->envs);
        $processCmd = "$envs exec $cmd";

        // See phpunit_error.log in CircleCI artifacts
        error_log("[php-cgi] Starting: '$envs $processCmd'");
        if (isset($this->inis['error_log'])) {
            error_log("[php-cgi] Error log: '" . realpath($this->inis['error_log']) . "'");
        }

        $this->process = new Process($processCmd);
        $this->process->start();
    }

    public function stop()
    {
        error_log("[php-cgi] Stopping...");
        $this->process->stop(0);
    }

    public function isFastCgi()
    {
        return true;
    }
}
