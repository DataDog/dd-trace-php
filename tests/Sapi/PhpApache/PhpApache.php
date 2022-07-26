<?php

namespace DDTrace\Tests\Sapi\PhpApache;

use DDTrace\Tests\Sapi\Sapi;
use Symfony\Component\Process\Process;

final class PhpApache implements Sapi
{
    const ERROR_LOG = 'apache_error.log';

    /**
     * @var Process
     */
    private $process;

    /**
     * @var array
     */
    private $envs;

    /**
     * @var array
     */
    private $inis;

    /**
     * @var string
     */
    private $configFile;

    /**
     * @var string
     */
    private $logFilePath;

    /**
     * @var resource
     */
    private $logFile;

    /**
     * @var bool
     */
    private $configChanged = false;

    /**
     * @var string
     */
    private $configContent;

    /**
     * @var string
     */
    private $runDir;

    /**
     * @param string $rootPath
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function loadConfig($rootPath, $host, $port, array $envs = [], array $inis = [])
    {
        $defaultInis = [
            "opcache.enable" => "1",
            "opcache.jit_buffer_size" => "100M",
            "opcache.jit" => "1255",
        ];

        $this->envs = $envs;
        $this->inis = $defaultInis + $inis;

        $logPath = $rootPath . '/' . self::ERROR_LOG;

        if (!$this->runDir) {
            $this->runDir = sys_get_temp_dir() . uniqid('/apache2-rundir-', true);
            mkdir($this->runDir);
        }

        $replacements = [
            '{{document_root}}' => $rootPath,
            '{{vhost_host}}' => $host === "0.0.0.0" ? "*" : $host,
            '{{vhost_port}}' => $port,
            '{{envs}}' => $this->envsForConfFile(),
            '{{inis}}' => $this->inisForConfFile(),
            '{{error_log}}' => $logPath,
            '{{run_dir}}' => $this->runDir,
        ];
        $configContent = str_replace(
            array_keys($replacements),
            array_values($replacements),
            file_get_contents(__DIR__ . '/apache2.conf')
        );

        if ($this->configContent === $configContent) {
            error_log("[apache] Skipping config file regeneration, identical configs.");
        } else {
            if (!$this->configFile) {
                $this->configFile = sys_get_temp_dir() . uniqid('/apache2-conf-', true);
            }
            $this->configChanged = true;
            if ($this->logFilePath !== $logPath) {
                $this->logFilePath = $logPath;
                $this->logFile = fopen($logPath, "a+");
            }

            // This gets logged to phpunit_error.log (check CircleCI artifacts)
            error_log("[apache] Generated config file '{$this->configFile}'");
            error_log("[apache] Error log: '" . $replacements['{{error_log}}'] . "'");
            error_log("[apache]\n{$configContent}");

            $this->configContent = $configContent;
            if (false === file_put_contents($this->configFile, $configContent)) {
                throw new \Exception('Error creating temp apache config file: ' . $this->configFile);
            }
        }
    }

    public function start()
    {
        if ($this->process) {
            if ($this->configChanged) {
                $this->process->signal(SIGUSR1);
                error_log("[apache] Reloading {$this->process->getPid()}");
            }
        } else {
            $cmd = sprintf(
                'apache2 -D FOREGROUND -f %s -E %s',
                $this->configFile,
                __DIR__ . "/" . self::ERROR_LOG
            );
            // setsid as apache broadcasts termination signals to its whole process group
            $processCmd = "exec setsid $cmd";

            // See phpunit_error.log in CircleCI artifacts
            error_log("[apache] Starting: '{$cmd}'");

            $this->process = new Process($processCmd);
            $this->process->start();
        }
        $this->configChanged = false;
    }

    public function stop()
    {
        // I do not understand why we get a SIGTERM when we try to send a SIGTERM to apache.
        // Somewhere something goes wrong, but I could not figure out where.
        error_log("[apache] Stopping...");
        $this->process->stop(1);
    }

    public function __destruct()
    {
        $this->stop();
        if ($this->configFile) {
            unlink($this->configFile);
        }
        if ($this->runDir) {
            rmdir($this->runDir);
        }
    }

    public function isFastCgi()
    {
        return false;
    }

    private function envsForConfFile()
    {
        $lines = [];
        foreach ($this->envs as $name => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if ('' !== $value) {
                $lines[] = sprintf('SetEnv %s "%s"', $name, str_replace('"', '\"', $value));
            }
        }
        return implode(PHP_EOL, $lines);
    }

    private function inisForConfFile()
    {
        $lines = [];
        foreach ($this->inis as $name => $value) {
            switch ($value) {
                case '0':
                case '1':
                case 'true':
                case 'false':
                case 'on':
                case 'off':
                case 'yes':
                case 'no':
                    $lines[] = sprintf('php_admin_flag %s "%s"', $name, $value);
                    break;
                default:
                    $lines[] = sprintf('php_admin_value %s "%s"', $name, $value);
                    break;
            }
        }
        return implode(PHP_EOL, $lines);
    }

    public function checkErrors()
    {
        $newLogs = stream_get_contents($this->logFile);
        if (preg_match("(=== Total [0-9]+ memory leaks detected ===)", $newLogs)) {
            return $newLogs;
        }

        if (preg_match("(child [0-9]+ exited on signal)", $newLogs)) {
            return $newLogs;
        }

        return null;
    }
}
