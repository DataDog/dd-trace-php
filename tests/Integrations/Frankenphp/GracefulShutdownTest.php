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
            // Route traces through the sidecar so datadog_sidecar_for_signal is non-NULL and the
            // handler takes the cleanup-thread branch rather than the inline one.
            'DD_TRACE_SIDECAR_TRACE_SENDER' => '1',
            // Normally defaulted on when pid/ppid is 1 (i.e. in a container); the test server is
            // neither, so ask for it explicitly.
            'DD_TRACE_FORCE_FLUSH_ON_SIGTERM' => '1',
        ]);
    }

    public function testShutsDownGracefullyOnSigtermWithSidecar()
    {
        // The sidecar connection is only established while serving, and the branch under test is
        // only reached once it exists -- so a request has to come first.
        $this->call(GetSpec::create('Warm up the sidecar connection', '/simple'));

        $exitedOnItsOwn = self::$appServer->stopGracefully(self::SHUTDOWN_TIMEOUT_SECONDS);

        // The server is gone either way now, so stop ddTearDown() from reporting it as a crash.
        $this->checkWebserverErrors = false;

        $this->assertTrue(
            $exitedOnItsOwn,
            sprintf(
                'FrankenPHP was still running %d seconds after SIGTERM. The tracer most likely '
                    . 'swallowed the signal instead of passing it to the Go runtime -- see '
                    . 'dd_sigint_sigterm_handler() in ext/signals.c.',
                self::SHUTDOWN_TIMEOUT_SECONDS
            )
        );
    }
}
