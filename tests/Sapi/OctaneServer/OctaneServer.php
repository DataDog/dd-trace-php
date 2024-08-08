<?php

namespace DDTrace\Tests\Sapi\OctaneServer;

use DDTrace\Tests\Common\EnvSerializer;
use DDTrace\Tests\Common\IniSerializer;
use DDTrace\Tests\Sapi\Sapi;
use Symfony\Component\Process\Process;

final class OctaneServer implements Sapi
{
    /**
     * @var Process
     */
    private $process;

    /**
     * @var string
     */
    private $artisanFile;

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
     * @param string $artisanFile
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function __construct($artisanFile, $host, $port, array $envs = [], array $inis = [])
    {
        $this->artisanFile = $artisanFile;
        $this->host = $host;
        $this->port = $port;
        $this->envs = $envs;
        $this->inis = $inis;
    }

    public function start()
    {
        if (GLOBAL_AUTO_PREPEND_FILE) {
            $this->inis['auto_prepend_file'] = GLOBAL_AUTO_PREPEND_FILE;
        }
        if (getenv('PHPUNIT_COVERAGE')) {
            $xdebugExtension = glob(PHP_EXTENSION_DIR . '/xdebug*.so');
            $xdebugExtension = end($xdebugExtension);
            $this->inis['zend_extension'] = $xdebugExtension;
            $this->inis['xdebug.mode'] = 'coverage';
        }
        $token = ini_get('datadog.trace.agent_test_session_token');
        if ($token != "") {
            $this->envs["DD_TRACE_AGENT_TEST_SESSION_TOKEN"] = $token;
        }

        $cmd = sprintf(
            PHP_BINARY . ' %s %s octane:start --server=swoole --host=%s --port=%d',
            new IniSerializer($this->inis),
            $this->artisanFile,
            $this->host,
            $this->port
        );
        $envs = new EnvSerializer($this->envs);
        $processCmd = "$envs exec $cmd";

        // See phpunit_error.log in CircleCI artifacts
        error_log("[octane-server] Starting: '$envs $processCmd'");
        if (isset($this->inis['error_log'])) {
            error_log("[octane-server] Error log: '" . realpath($this->inis['error_log']) . "'");
        }

        $this->process = new Process($processCmd);
        $this->process->start();
        sleep(1);
        echo $this->process->getErrorOutput();
    }

    public function stop()
    {
        error_log("[octane-server] Stopping...");
        $this->process->stop(0);

        $cmd = sprintf(
            PHP_BINARY . ' -d extension=swoole %s octane:stop',
            $this->artisanFile
        );
        $process = new Process($cmd);
        $process->run();
    }

    public function isFastCgi()
    {
        return false;
    }

    public function checkErrors()
    {
        $newLogs = $this->process->getIncrementalErrorOutput();
        if (preg_match("(=== Total [0-9]+ memory leaks detected ===|AddressSanitizer:)", $newLogs)) {
            return $newLogs;
        }

        if (!$this->process->isRunning()) {
            return "$newLogs\n<Process terminated unexpectedly>";
        }

        return null;
    }
}
