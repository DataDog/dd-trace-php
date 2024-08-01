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

        if (GLOBAL_AUTO_PREPEND_FILE) {
            $this->inis['auto_prepend_file'] = GLOBAL_AUTO_PREPEND_FILE;
        }
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

    public function start()
    {
        if (!file_exists(readlink("/usr/local/bin/frankenphp"))) {
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
        $this->process->start();
    }

    public function stop()
    {
        error_log("[frankenphp] Stopping...");
        $this->process->stop(0);
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
