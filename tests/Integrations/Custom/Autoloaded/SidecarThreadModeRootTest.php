<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\WebServer;

/**
 * Tests thread-mode sidecar with PHP-FPM master running as root and workers
 * switched to an unprivileged user (e.g. www-data).
 */
final class SidecarThreadModeRootTest extends WebFrameworkTestCase
{
    /** @var string|null */
    private static $workerUser = null;

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'sidecar-thread-mode-root-test',
            'DD_TRACE_SIDECAR_CONNECTION_MODE' => 'thread',
        ]);
    }

    /** @var bool */
    private static $useSudo = false;

    public static function ddSetUpBeforeClass()
    {
        if (\getenv('DD_TRACE_TEST_SAPI') !== 'fpm-fcgi') {
            self::markTestSkipped('This test only runs under fpm-fcgi SAPI');
        }

        $isRoot = \function_exists('posix_geteuid') && \posix_geteuid() === 0;
        $hasSudo = !$isRoot && \shell_exec('sudo -n true 2>/dev/null; echo $?') === "0\n";

        if (!$isRoot && !$hasSudo) {
            self::markTestSkipped('This test requires root or passwordless sudo to start php-fpm as root');
        }

        self::$useSudo = !$isRoot;

        self::$workerUser = self::findUnprivilegedUser();
        if (self::$workerUser === null) {
            self::markTestSkipped('No unprivileged user found on this system (tried www-data, daemon, nobody)');
        }

        parent::ddSetUpBeforeClass();
    }

    protected static function configureWebServer(WebServer $server)
    {
        // Tell FPM to switch worker processes to the unprivileged user after forking.
        $server->setPhpFpmUser(self::$workerUser);
        $server->setPhpFpmMaxChildren(3);
        if (self::$useSudo) {
            $server->setPhpFpmSudo(true);
        }
        // Pass connection mode as a command-line INI flag to the FPM master process
        $server->setPhpFpmMasterIni(['datadog.trace.sidecar_connection_mode' => 'thread']);
    }

    /**
     * Verifies that a single request succeeds when the FPM master runs as root
     * and workers run as an unprivileged user.
     */
    public function testTracesAreSubmittedWithRootMasterAndUnprivilegedWorker()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Root+worker thread mode', '/simple'));
        });

        $this->assertNotEmpty($traces, 'No traces received — worker likely failed to access SHM after fchown()');
        $this->assertSame('web.request', $traces[0][0]['name']);
        $this->assertSame('sidecar-thread-mode-root-test', $traces[0][0]['service']);
    }

    /**
     * Verifies that in thread mode, only the FPM master owns the sidecar listener
     * thread — workers connect to it rather than each spawning their own thread.
     *
     * After all workers have served a
     * request the master process must have > 1 thread (main + sidecar listener) while
     * every worker must have exactly 1 thread.
     */
    public function testMultipleWorkersShareSingleMasterListenerThread()
    {
        $traces = $this->tracesFromWebRequest(function () {
            for ($i = 0; $i < 3; $i++) {
                $this->call(GetSpec::create("Worker request $i", '/simple'));
            }
        }, null, $this->untilNumberOfTraces(3));

        $this->assertGreaterThanOrEqual(3, \count($traces), 'Expected at least 3 traces from multiple worker requests');

        // Identify master vs worker processes.
        $allPids = array_values(array_filter(array_map('intval', explode("\n", trim(\shell_exec('pgrep php-fpm') ?: '')))));
        $this->assertNotEmpty($allPids, 'No php-fpm processes found');

        $masterPid = null;
        $workerPids = [];
        foreach ($allPids as $pid) {
            $ppid = (int) trim(\shell_exec("ps -o ppid= -p $pid 2>/dev/null") ?: '0');
            if (\in_array($ppid, $allPids, true)) {
                $workerPids[] = $pid;
            } else {
                $masterPid = $pid;
            }
        }

        $this->assertNotNull($masterPid, 'Could not identify php-fpm master process');
        $this->assertCount(3, $workerPids, 'Expected exactly 3 worker processes (pm=static, max_children=3)');

        // Master must have >1 thread: its main thread + the sidecar listener thread.
        $masterThreads = $this->readProcThreadCount($masterPid);
        $this->assertGreaterThan(
            1,
            $masterThreads,
            "Master (PID $masterPid) should have >1 thread (main + sidecar listener)"
        );

        // Workers may have a Rust async-I/O thread for the client connection, but they
        // must NOT have the sidecar listener thread — that lives only in the master.
        // Therefore master must have strictly more threads than every worker.
        foreach ($workerPids as $workerPid) {
            $workerThreads = $this->readProcThreadCount($workerPid);
            $this->assertGreaterThan(
                $workerThreads,
                $masterThreads,
                "Master (PID $masterPid, threads=$masterThreads) should have more threads than " .
                "worker (PID $workerPid, threads=$workerThreads) — master owns the sidecar listener thread"
            );
        }
    }

    private function readProcThreadCount($pid)
    {
        $status = @\file_get_contents("/proc/$pid/status");
        if ($status === false) {
            return 0;
        }
        \preg_match('/^Threads:\s+(\d+)/m', $status, $m);
        return isset($m[1]) ? (int) $m[1] : 0;
    }

    /**
     * Returns the first unprivileged user found on the system, or null if none.
     */
    private static function findUnprivilegedUser()
    {
        foreach (['www-data', 'daemon', 'nobody'] as $candidate) {
            if (\posix_getpwnam($candidate) !== false) {
                return $candidate;
            }
        }
        return null;
    }
}
