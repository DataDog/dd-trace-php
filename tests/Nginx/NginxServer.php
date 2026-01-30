<?php

namespace DDTrace\Tests\Nginx;

use Symfony\Component\Process\Process;

final class NginxServer
{
    const ACCESS_LOG = 'nginx_access.log';
    const ERROR_LOG = 'nginx_error.log';

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
     * @var string
     */
    private $serverHost;

    /**
     * @var int
     */
    private $hostPort;

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
        $this->serverHost = $serverHost;
        $this->hostPort = $hostPort;
        $replacements = [
            '{{root_path}}' => $this->rootPath,
            '{{index_file}}' => basename($indexFile),
            '{{server_host}}' => $serverHost,
            '{{server_port}}' => $hostPort,
            '{{fcgi_host}}' => $fastCGIHost,
            '{{fcgi_port}}' => $fastCGIPort,
            '{{access_log}}' => $this->rootPath . '/' . self::ACCESS_LOG,
            '{{error_log}}' => $this->rootPath . '/' . self::ERROR_LOG,
        ];
        $configContent = str_replace(
            array_keys($replacements),
            array_values($replacements),
            file_get_contents(__DIR__ . '/nginx-default.conf')
        );

        $this->configFile = sys_get_temp_dir() . uniqid('/nginx-', true);

        // This gets logged to phpunit_error.log (check CircleCI artifacts)
        error_log("[nginx] Generated config file '{$this->configFile}' for '{$indexFile}'");
        error_log("[nginx] Error log: '" . $replacements['{{error_log}}'] . "'");
        //error_log($configContent);

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

        // See phpunit_error.log in CircleCI artifacts
        error_log("[nginx] Starting: '{$processCmd}'");

        $this->process = new Process($processCmd);
        $this->process->start();

        if (!$this->waitUntilServerRunning()) {
            error_log("[nginx] Server never came up...");
            return;
        }
        error_log("[nginx] Server is up and responding...");
    }

    public function waitUntilServerRunning()
    {
        //Let's wait until nginx is accepting connections
        for ($try = 0; $try < 40; $try++) {
            $socket = @fsockopen($this->serverHost, $this->hostPort);
            if ($socket !== false) {
                fclose($socket);
                return true;
            }
            usleep(50000);
        }

        return false;
    }

    public function stop()
    {
        if ($this->process) {
            error_log("[nginx] Stopping...");
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
