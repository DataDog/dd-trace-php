<?php

namespace DDTrace\Tests\Sapi\Frankenphp;

use DDTrace\Tests\Sapi\Sapi;
use Symfony\Component\Process\Process;

final class FrankenphpServer implements Sapi
{
    const ERROR_LOG = 'frankenphp_error.log';

    /**
     * @var Process
     */
    private $process;

    /**
     * @var string
     */
    private $indexFile;

    /**
     * @var string
     */
    private $configFile;

    /**
     * @var string
     */
    private $configDir;

    /**
     * @var resource
     */
    private $logFile;

    /**
     * @var string[]
     */
    private $envs;

    /**
     * @param string $indexFile
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function __construct($indexFile, $host, $port, array $envs = [], array $inis = [])
    {
        $this->indexFile = $indexFile;
        $this->envs = $envs;

        if (getenv('PHPUNIT_COVERAGE')) {
            $xdebugExtension = glob(PHP_EXTENSION_DIR . '/xdebug*.so');
            $xdebugExtension = end($xdebugExtension);
            $inis['zend_extension'] = $xdebugExtension;
            $inis['xdebug.mode'] = 'coverage';
        }

        $replacements = [
            '{{frankenphp_host}}' => $host,
            '{{frankenphp_port}}' => $port,
            '{{frankenphp_php}}' => $this->indexFile,
            '{{frankenphp_dir}}' => dirname($this->indexFile),
        ];
        $configContent = str_replace(
            array_keys($replacements),
            array_values($replacements),
            file_get_contents(__DIR__ . '/Caddyfile')
        );

        $this->configDir = sys_get_temp_dir() . uniqid('/frankenphp-', true);
        $this->configFile = $this->configDir . "/Caddyfile";
        mkdir($this->configDir);

        $iniString = "";
        foreach ($inis as $ini => $val) {
            $iniString .= "$ini = $val\n";
        }
        file_put_contents($this->configDir . "/php.ini", $iniString);

        $this->logFile = fopen(dirname($indexFile) . '/' . self::ERROR_LOG, "a+");

        if (false === file_put_contents($this->configFile, $configContent)) {
            throw new \Exception('Error creating temp frankenphp config file: ' . $this->configFile);
        }
    }

    private static function installFrankenphp()
    {
        exec(__DIR__ . "/../../../tooling/bin/install-frankenphp.sh");
    }

    /**
     * The CI images ship /usr/local/bin/frankenphp as a symlink pointing at the binary that
     * install-frankenphp.sh still has to produce, while the official FrankenPHP images ship a real
     * binary there. readlink() fails on the latter, so only resolve the link when there is one --
     * otherwise we would rebuild FrankenPHP from source in an image that already has it.
     *
     * @return bool
     */
    private static function isFrankenphpInstalled()
    {
        $path = "/usr/local/bin/frankenphp";
        if (is_link($path)) {
            $target = readlink($path);
            return $target !== false && file_exists($target);
        }
        return is_executable($path);
    }

    public function start()
    {
        if (!self::isFrankenphpInstalled()) {
            self::installFrankenphp();
        }

        $cmd = sprintf(
            "frankenphp run --config '%s'",
            $this->configFile
        );
        $envString = "PHPRC=" . $this->configDir;
        foreach ($this->envs as $env => $val) {
            $envString .= " $env=\"$val\"";
        }
        $processCmd = "$envString exec $cmd";


        // See phpunit_error.log in CircleCI artifacts
        error_log("[frankenphp] Starting: '$envString $cmd'");
        if (isset($this->inis['frankenphp'])) {
            error_log("[frankenphp] Error log: '" . realpath($this->inis['error_log']) . "'");
        }

        $this->process = new Process($processCmd);
        // Persist whatever Caddy/FrankenPHP write to stdout/stderr to have CI artifacts.
        $logWriter = fopen(dirname($this->indexFile) . '/' . self::ERROR_LOG, "a");
        $this->process->start(function ($type, $buffer) use ($logWriter) {
            fwrite($logWriter, $buffer);
            fflush($logWriter);
        });
    }

    public function stop()
    {
        error_log("[frankenphp] Stopping...");
        $this->process->stop(0);
    }

    /**
     * Sends SIGTERM and gives the server up to $timeout seconds to shut down by itself, rather than
     * following up with SIGKILL right away like stop() does.
     *
     * This is what exercises the tracer's SIGTERM handler (ext/signals.c): with a sidecar attached
     * it has to hand the signal back to the Go runtime, and if it fails to, FrankenPHP never sees
     * the signal and only dies once something SIGKILLs it.
     *
     * @param int $timeout seconds to wait for a graceful exit
     * @return bool whether the server exited on its own within $timeout
     */
    public function stopGracefully($timeout = 15)
    {
        if (!$this->process || !$this->process->isRunning()) {
            return true;
        }

        error_log("[frankenphp] Sending SIGTERM, waiting up to {$timeout}s for a graceful exit...");
        $this->process->signal(SIGTERM);

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (!$this->process->isRunning()) {
                error_log("[frankenphp] Exited with code " . var_export($this->process->getExitCode(), true));
                return true;
            }
            usleep(50 * 1000);
        }

        error_log("[frankenphp] Still running {$timeout}s after SIGTERM; killing it.");
        $this->process->stop(0);
        return false;
    }

    public function isFastCgi()
    {
        return false;
    }

    public function checkErrors()
    {
        $newLogs = stream_get_contents($this->logFile);
        if (preg_match("(=== Total [0-9]+ memory leaks detected ===)", $newLogs)) {
            return $newLogs;
        }

        $newLogs = $this->process->getIncrementalErrorOutput();
        if (!$this->process->isRunning()) {
            return "$newLogs\n<Process terminated unexpectedly>";
        }

        return null;
    }
}
