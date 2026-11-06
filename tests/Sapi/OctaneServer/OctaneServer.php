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
        //Avoid previous tests
        $this->waitUntilServerIsNotRunning();

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

        // Pin the pool like the Swoole and RoadRunner harnesses: Octane's "auto" boots 2 x node vCPUs
        // Laravel workers (256 on a 128-vCPU CI node), which OOMs the job.
        $cmd = sprintf(
            PHP_BINARY . ' %s %s octane:start --server=swoole --host=%s --port=%d --workers=2 --task-workers=1',
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

        // octane:start boots artisan, then spawns the swoole server that binds the port: seconds on a loaded CI pod
        if (!$this->waitUntilServerRunning()) {
            $message = sprintf(
                "[octane-server] Server never came up (%s)...\nstdout: %s\nstderr: %s",
                $this->process->isRunning() ? 'still running' : 'exit code ' . $this->process->getExitCode(),
                $this->process->getOutput(),
                $this->process->getErrorOutput()
            );
            error_log($message);
            throw new \RuntimeException($message);
        }
        error_log("[octane-server] Server is up and responding...");
    }

    public function waitUntilServerIsNotRunning()
    {
        //Let's wait until server is not accepting connections
        for ($try = 0; $try < 40; $try++) {
            $socket = @fsockopen($this->host, $this->port);
            if ($socket == false) {
                return true;
            }
            usleep(50000);
        }

        return false;
    }

    public function waitUntilServerRunning($timeout = 30)
    {
        $deadline = microtime(true) + $timeout;
        do {
            $socket = @fsockopen($this->host, $this->port);
            if ($socket !== false) {
                fclose($socket);
                return true;
            }
            if (!$this->process->isRunning()) {
                return false;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        return false;
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
        $this->waitUntilServerIsNotRunning();
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
