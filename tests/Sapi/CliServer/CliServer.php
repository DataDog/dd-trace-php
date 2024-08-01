<?php

namespace DDTrace\Tests\Sapi\CliServer;

use DDTrace\Tests\Common\EnvSerializer;
use DDTrace\Tests\Common\IniSerializer;
use DDTrace\Tests\Sapi\Sapi;
use Symfony\Component\Process\Process;

final class CliServer implements Sapi
{
    /**
     * @var Process
     */
    private $process;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $indexFile;

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
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function __construct($indexFile, $host, $port, array $envs = [], array $inis = [])
    {
        $this->indexFile = $indexFile;
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

        /**
         * If a router is provided to the built-in web server (the index file),
         * the request init hook (which hooks auto_prepend_file) will not run.
         * If there is no router, the script is run with php_execute_script():
         * @see https://heap.space/xref/PHP-7.4/sapi/cli/php_cli_server.c?r=58b17906#2077
         * This runs the auto_prepend_file as expected. However, if a router is present,
         * zend_execute_scripts() will be used instead:
         * @see https://heap.space/xref/PHP-7.4/sapi/cli/php_cli_server.c?r=58b17906#2202
         * As a result auto_prepend_file (and the request init hook) is not executed.
         */
        $cmd = sprintf(
            PHP_BINARY . ' %s -S %s:%d -t %s', // . ' %s'
            new IniSerializer($this->inis),
            $this->host,
            $this->port,
            dirname($this->indexFile)
            //$this->indexFile
        );
        $envs = new EnvSerializer($this->envs);
        $processCmd = "$envs exec $cmd";

        // See phpunit_error.log in CircleCI artifacts
        error_log("[cli-server] Starting: '$envs $processCmd'");
        if (isset($this->inis['error_log'])) {
            error_log("[cli-server] Error log: '" . realpath($this->inis['error_log']) . "'");
        }

        $this->process = new Process($processCmd);
        $this->process->start();
    }

    public function stop()
    {
        error_log("[cli-server] Stopping...");
        $this->process->stop(0);
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
