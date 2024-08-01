<?php

namespace DDTrace\Tests\Sapi\Roadrunner;

use DDTrace\Tests\Common\EnvSerializer;
use DDTrace\Tests\Common\IniSerializer;
use DDTrace\Tests\Sapi\Sapi;
use Symfony\Component\Process\Process;

final class RoadrunnerServer implements Sapi
{
    const ERROR_LOG = 'roadrunner_error.log';

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
    private $version;

    /**
     * @var string
     */
    private $target;

    /**
     * @var string
     */
    private $configFile;

    /**
     * @var resource
     */
    private $logFile;

    /**
     * @param string $version
     * @param string $workerFile
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function __construct($version, $workerFile, $host, $port, array $envs = [], array $inis = [])
    {
        $this->version = $version;
        $this->workerFile = $workerFile;

        $logPath = dirname($workerFile) . '/' . self::ERROR_LOG;

        switch (php_uname('m')) {
            case "arm64":
            case "aarch64":
                $this->target = "arm64";
                break;

            default:
                $this->target = "amd64";
        }

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
            '{{roadrunner_host}}' => $host,
            '{{roadrunner_port}}' => $port,
            '{{roadrunner_php}}' => sprintf("%s %s %s", PHP_BINARY, new IniSerializer($inis), $this->workerFile),
            '{{env}}' => json_encode($envs + ['DD_TRACE_CLI_ENABLED' => 1]),
            '{{error_log}}' => $logPath,
        ];
        $configContent = str_replace(
            array_keys($replacements),
            array_values($replacements),
            file_get_contents(__DIR__ . '/.rr.yaml')
        );

        $this->configFile = sys_get_temp_dir() . uniqid('/rr-', true) . ".yaml";
        $this->logFile = fopen($logPath, "a+");

        if (false === file_put_contents($this->configFile, $configContent)) {
            throw new \Exception('Error creating temp roadrunner config file: ' . $this->configFile);
        }
    }

    private static function downloadRoadrunner($target, $version)
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        exec("curl -L https://github.com/roadrunner-server/roadrunner/releases/download/v$version/roadrunner-$version-linux-$target.tar.gz | tar xz -O -f - roadrunner-$version-linux-$target/rr > '" . __DIR__ . "/rr-$version-$target'; chmod +x '" . __DIR__ . "/rr-$version-$target'");
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    public function start()
    {
        if (!file_exists(__DIR__ . "/rr-{$this->version}-{$this->target}")) {
            self::downloadRoadrunner($this->target, $this->version);
        }

        $cmd = sprintf(
            "'" . __DIR__ . "/rr-{$this->version}-{$this->target}' serve -c '%s' -w '%s'",
            $this->configFile,
            dirname($this->workerFile)
        );
        $processCmd = "exec $cmd";

        // See phpunit_error.log in CircleCI artifacts
        error_log("[roadrunner] Starting: '$processCmd'");
        if (isset($this->inis['roadrunner'])) {
            error_log("[roadrunner] Error log: '" . realpath($this->inis['error_log']) . "'");
        }

        $this->process = new Process($processCmd);
        $this->process->start();
    }

    public function stop()
    {
        error_log("[roadrunner] Stopping...");
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
