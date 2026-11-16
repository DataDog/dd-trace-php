<?php

namespace DDTrace\Tests\Integrations\Frankenphp;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

/**
 * Covers the tracer's SIGTERM handler (ext/signals.c) under FrankenPHP.
 *
 * With DD_TRACE_FORCE_FLUSH_ON_SIGTERM on and a sidecar connected, dd_sigint_sigterm_handler()
 * starts a cleanup thread that flushes the sidecar and then hands the signal back to whatever
 * handler was installed before -- here, the Go runtime's, which is what makes Caddy shut down.
 * When that hand-off does not happen, FrankenPHP never observes the signal at all and just keeps
 * running until something SIGKILLs it (issue #4163).
 *
 * The failure is completely silent -- no error, no log line -- so the only thing that catches it is
 * asserting that the server actually terminates on SIGTERM alone. This matters most on musl, where
 * clone() rejects CLONE_THREAD outright, which is why the arm64/Alpine CI job runs this suite.
 * Also require exit code 0: the shutdown can finish and still crash while destroying PHP threads
 * or unloading extensions (issue #4349).
 */
class GracefulShutdownTest extends WebFrameworkTestCase
{
    /** Generous enough to absorb a slow sidecar flush, far below any plausible hang. */
    const SHUTDOWN_TIMEOUT_SECONDS = 20;

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Frankenphp/index.php';
    }

    protected static function isFrankenphp()
    {
        return true;
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            // Route traces through the sidecar so a connection is armed for the signal handler and
            // it takes the cleanup-thread branch rather than the inline one.
            'DD_TRACE_SIDECAR_TRACE_SENDER' => '1',
            // Normally defaulted on when pid/ppid is 1 (i.e. in a container); the test server is
            // neither, so ask for it explicitly.
            'DD_TRACE_FORCE_FLUSH_ON_SIGTERM' => '1',
        ]);
    }

    /**
     * The server is stopped by this test, so retrying would hide the shutdown failure.
     * @retryAttempts 0
     */
    public function testShutsDownGracefullyOnSigtermWithSidecar()
    {
        // The sidecar connection is only established while serving, and the branch under test is
        // only reached once it exists -- so a request has to come first.
        $this->call(GetSpec::create('Warm up the sidecar connection', '/simple'));

        $exitedCleanly = self::$appServer->stopGracefully(self::SHUTDOWN_TIMEOUT_SECONDS);

        // The server is gone either way now, so stop ddTearDown() from reporting it as a crash.
        $this->checkWebserverErrors = false;

        $this->assertTrue(
            $exitedCleanly,
            sprintf(
                'FrankenPHP did not exit with code 0 within %d seconds of SIGTERM. '
                    . 'Check frankenphp_error.log and phpunit_error.log for shutdown errors and the exit code.',
                self::SHUTDOWN_TIMEOUT_SECONDS
            )
        );
    }
}
