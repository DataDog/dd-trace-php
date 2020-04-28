<?php

namespace DDTrace\Tests\Nginx;

use Symfony\Component\Process\Process;

final class NginxServer
{
    /**
     * @var Process
     */
    private $process;

    /**
     * @var string
     */
    private $configFile;

    /**
     * @var string
     */
    private $rootPath;

    /**
     * @param string $indexFile
     * @param string $serverHost
     * @param string $hostPort
     * @param string $fastCGIHost
     * @param int $fastCGIPort
     * @throws \Exception
     */
    public function __construct($indexFile, $serverHost, $hostPort, $fastCGIHost, $fastCGIPort)
    {
        $this->rootPath = dirname($indexFile);
        $replacements = [
            '{{root_path}}' => $this->rootPath,
            '{{index_file}}' => basename($indexFile),
            '{{server_host}}' => $serverHost,
            '{{server_port}}' => $hostPort,
            '{{fcgi_host}}' => $fastCGIHost,
            '{{fcgi_port}}' => $fastCGIPort,
        ];
        $configContent = str_replace(
            array_keys($replacements),
            array_values($replacements),
            file_get_contents(__DIR__ . '/nginx-default.conf')
        );

        $this->configFile = sys_get_temp_dir() . uniqid('/nginx-', true);
        if (false === file_put_contents($this->configFile, $configContent)) {
            throw new \Exception('Error creating temp nginx config file: ' . $this->configFile);
        }
    }

    public function start()
    {
        $processCmd = sprintf(
            'exec nginx -c %s -p %s',
            $this->configFile,
            $this->rootPath
        );
        $this->process = new Process($processCmd);
        $this->process->start();
    }

    public function stop()
    {
        if ($this->process) {
            $this->process->stop(0);
        }
    }

    public function __destruct()
    {
        if ($this->configFile) {
            unlink($this->configFile);
        }
    }
}
