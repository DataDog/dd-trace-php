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
     * @param string $rootPath
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function __construct($rootPath, $host, $port, array $envs = [], array $inis = [])
    {
        $this->envs = $envs;
        $this->inis = $inis;

        $replacements = [
            '{{fcgi_host}}' => $host,
            '{{fcgi_port}}' => $port,
            '{{envs}}' => $this->envsForConfFile(),
            '{{inis}}' => $this->inisForConfFile(),
            '{{error_log}}' => $rootPath . '/' . self::ERROR_LOG,
        ];
        $configContent = str_replace(
            array_keys($replacements),
            array_values($replacements),
            file_get_contents(__DIR__ . '/www.conf')
        );

        $this->configFile = sys_get_temp_dir() . uniqid('/www-conf-', true);

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
        $cmd = sprintf(
            'exec php-fpm -p %s --fpm-config %s -F',
            __DIR__,
            $this->configFile
        );

        // See phpunit_error.log in CircleCI artifacts
        error_log("[php-fpm] Starting: '{$cmd}'");

        $this->process = new Process($cmd);
        $this->process->start();
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
}
