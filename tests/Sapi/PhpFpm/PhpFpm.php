<?php

namespace DDTrace\Tests\Sapi\PhpFpm;

use DDTrace\Tests\Sapi\Sapi;
use Symfony\Component\Process\Process;

final class PhpFpm implements Sapi
{
    const ERROR_LOG = 'php_fpm_error.log';

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
     * @var resource
     */
    private $logFile;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var int
     */
    private $maxChildren;

    /**
     * @param string $rootPath
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     * @param int $maxChildren
     */
    public function __construct($rootPath, $host, $port, array $envs = [], array $inis = [], $maxChildren = 1)
    {
        $this->envs = $envs;
        $this->inis = $inis;
        $this->host = $host;
        $this->port = $port;
        $this->maxChildren = $maxChildren;

        $logPath = $rootPath . '/' . self::ERROR_LOG;

        $replacements = [
            '{{fcgi_host}}' => $host,
            '{{fcgi_port}}' => $port,
            '{{max_children}}' => $maxChildren,
            '{{envs}}' => $this->envsForConfFile(),
            '{{inis}}' => $this->inisForConfFile(),
            '{{error_log}}' => $logPath,
        ];
        $configContent = str_replace(
            array_keys($replacements),
            array_values($replacements),
            file_get_contents(__DIR__ . '/www.conf')
        );

        $this->configFile = sys_get_temp_dir() . uniqid('/www-conf-', true);
        $this->logFile = fopen($logPath, "a+");

        // This gets logged to phpunit_error.log (check CircleCI artifacts)
        error_log("[php-fpm] Generated config file '{$this->configFile}'");
        error_log("[php-fpm] Error log: '" . $replacements['{{error_log}}'] . "'");
        error_log("[php-fpm]\n{$configContent}");

        if (false === file_put_contents($this->configFile, $configContent)) {
            throw new \Exception('Error creating temp PHP-FPM config file: ' . $this->configFile);
        }
    }

    public function start()
    {
        $allowRoot = '';
        // Check if running as root and add --allow-to-run-as-root flag
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $allowRoot = ' --allow-to-run-as-root';
        }

        $cmd = sprintf(
            'php-fpm -p %s --fpm-config %s -F%s',
            __DIR__,
            $this->configFile,
            $allowRoot
        );
        $processCmd = "exec $cmd";

        // See phpunit_error.log in CircleCI artifacts
        error_log("[php-fpm] Starting: '{$cmd}'");

        $this->process = new Process($processCmd);
        $this->process->start();

        if (!$this->waitUntilServerRunning()) {
            error_log("[php-fpm] Server never came up...");
            return;
        }
        error_log("[php-fpm] Server is up and responding...");
    }

    public function waitUntilServerRunning()
    {
        //Let's wait until PHP-FPM is accepting connections
        for ($try = 0; $try < 40; $try++) {
            $socket = @fsockopen($this->host, $this->port);
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
        error_log("[php-fpm] Stopping...");
        // In case of SEGFAULT nginx takes a few more millisecond to return an appropriate 502 code and then exit.
        $this->process->stop(1);
        // Waiting 200 ms for PHP-FPM to give the socket back
        \usleep(200 * 1000);
    }

    public function isFastCgi()
    {
        return true;
    }

    private function envsForConfFile()
    {
        $lines = [];
        foreach ($this->envs as $name => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if ('' !== $value) {
                $lines[] = sprintf("env[%s] = '%s'", $name, str_replace("'", "\'", $value));
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
                    $lines[] = sprintf('php_admin_flag[%s] = %s', $name, $value);
                    break;
                default:
                    $lines[] = sprintf('php_admin_value[%s] = %s', $name, $value);
                    break;
            }
        }
        return implode(PHP_EOL, $lines);
    }

    public function __destruct()
    {
        if ($this->configFile) {
            unlink($this->configFile);
        }
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
