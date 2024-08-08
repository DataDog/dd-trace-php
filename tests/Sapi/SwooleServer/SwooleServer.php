<?php

namespace DDTrace\Tests\Sapi\SwooleServer;

use DDTrace\Tests\Common\EnvSerializer;
use DDTrace\Tests\Common\IniSerializer;
use DDTrace\Tests\Sapi\Sapi;
use Symfony\Component\Process\Process;

final class SwooleServer implements Sapi
{
    /**
     * @var Process
     */
    private $process;

    /**
     * @var string
     */
    private $indexFile;

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
     * @param string $indexFile
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function __construct($indexFile, $port, array $envs = [], array $inis = [])
    {
        $this->indexFile = $indexFile;
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

        $cmd = sprintf(
            PHP_BINARY . ' %s %s %d',
            new IniSerializer($this->inis),
            $this->indexFile,
            $this->port
        );
        $envs = new EnvSerializer($this->envs);
        $processCmd = "$envs exec $cmd";

        // See phpunit_error.log in CircleCI artifacts
        error_log("[swoole-server] Starting: '$envs $processCmd'");
        if (isset($this->inis['error_log'])) {
            error_log("[swoole-server] Error log: '" . realpath($this->inis['error_log']) . "'");
        }

        $this->process = new Process($processCmd);
        $this->process->start();
    }

    public function stop()
    {
        error_log("[swoole-server] Stopping...");
        $this->process->stop(0, SIGTERM);
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
