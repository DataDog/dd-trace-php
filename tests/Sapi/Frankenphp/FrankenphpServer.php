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
    private $workerFile;

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
     * @param string $workerFile
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function __construct($workerFile, $host, $port, array $envs = [], array $inis = [])
    {
        $this->workerFile = $workerFile;

        $logPath = dirname($workerFile) . '/' . self::ERROR_LOG;

        if (getenv('PHPUNIT_COVERAGE')) {
            $inis['auto_prepend_file'] = __DIR__ . '/../../save_code_coverage.php';

            $xdebugExtension = glob(PHP_EXTENSION_DIR . '/xdebug*.so');
            $xdebugExtension = end($xdebugExtension);
            $inis['zend_extension'] = $xdebugExtension;
            $inis['xdebug.mode'] = 'coverage';
        }

        $inis["error_log"] = $logPath;

        $envString = "";
        foreach ($envs as $env => $val) {
            $envString .= "\t\tenv $env $val\n";
        }

        $replacements = [
            '{{frankenphp_host}}' => $host,
            '{{frankenphp_port}}' => $port,
            '{{frankenphp_php}}' => $this->workerFile,
            '{{env}}' => $envString,
        ];
        $configContent = str_replace(
            array_keys($replacements),
            array_values($replacements),
            file_get_contents(__DIR__ . '/caddy.conf')
        );

        $this->configDir = sys_get_temp_dir() . uniqid('/rr-', true);
        $this->configFile = $this->configDir . "/caddy.conf";
        mkdir($this->configDir);

        $iniString = "";
        foreach ($inis as $ini => $val) {
            $iniString .= "$ini = $val\n";
        }
        file_put_contents($this->configDir . "/php.ini", $iniString);

        $this->logFile = fopen($logPath, "a+");

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
            "PHPRC=%s frankenphp --config '%s'",
            $this->configDir,
            $this->configFile
        );
        $processCmd = "exec $cmd";

        // See phpunit_error.log in CircleCI artifacts
        error_log("[frankenphp] Starting: '$cmd'");
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
