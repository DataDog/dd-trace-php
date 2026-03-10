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

    public static function ddSetUpBeforeClass()
    {
        if (!\function_exists('posix_geteuid') || \posix_geteuid() !== 0) {
            self::markTestSkipped('This test requires the test runner to execute as root');
        }

        if (\getenv('DD_TRACE_TEST_SAPI') !== 'fpm-fcgi') {
            self::markTestSkipped('This test requires DD_TRACE_TEST_SAPI=fpm-fcgi');
        }

        self::$workerUser = self::findUnprivilegedUser();
        if (self::$workerUser === null) {
            self::markTestSkipped('No unprivileged user found on this system (tried www-data, daemon, nobody)');
        }

        parent::ddSetUpBeforeClass();
    }

    protected static function configureWebServer(WebServer $server): void
    {
        // Tell FPM to switch worker processes to the unprivileged user after forking.
        $server->setPhpFpmUser(self::$workerUser);
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
     * Verifies that multiple concurrent workers all connect to the single
     * master listener thread instead of each starting their own.
     */
    public function testMultipleWorkersShareSingleMasterListenerThread()
    {
        $traces = $this->tracesFromWebRequest(function () {
            // Send several requests to exercise multiple worker processes
            for ($i = 0; $i < 3; $i++) {
                $this->call(GetSpec::create("Worker request $i", '/simple'));
            }
        });

        $this->assertGreaterThanOrEqual(3, \count($traces), 'Expected at least 3 traces from multiple worker requests');
        foreach ($traces as $trace) {
            $this->assertSame('web.request', $trace[0]['name']);
        }
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
