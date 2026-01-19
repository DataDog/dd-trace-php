<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

/**
 * Integration test for thread-based sidecar connection with PHP-FPM
 *
 * This test explicitly forces thread mode (DD_TRACE_SIDECAR_CONNECTION_MODE=thread)
 * to validate that the thread-based sidecar implementation works correctly with
 * PHP-FPM's master/worker process architecture.
 *
 * Note: Default behavior (auto mode) tries subprocess first, which typically succeeds
 * in PHP-FPM environments. This test forces thread mode to specifically validate the
 * thread implementation works as a fallback option.
 *
 * This test requires DD_TRACE_TEST_SAPI=fpm-fcgi
 */
final class SidecarThreadModeTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'sidecar-thread-mode-test',
            // Explicitly force thread mode to test the thread implementation
            'DD_TRACE_SIDECAR_CONNECTION_MODE' => 'thread',
            'DD_TRACE_DEBUG' => '0',
        ]);
    }

    public function testThreadModeTracesAreSubmitted()
    {
        if (\getenv('DD_TRACE_TEST_SAPI') !== 'fpm-fcgi') {
            $this->markTestSkipped('This test requires DD_TRACE_TEST_SAPI=fpm-fcgi');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Thread mode not supported on Windows');
        }

        // This test validates that when thread mode is explicitly configured,
        // traces are successfully submitted through the thread-based sidecar
        $traces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Thread mode trace', '/simple?key=value');
            $this->call($spec);
        });

        // Verify traces were submitted
        $this->assertNotEmpty($traces, 'Expected traces to be submitted through thread-based sidecar');
        $this->assertCount(1, $traces[0], 'Expected one span in the trace');

        $span = $traces[0][0];
        $this->assertSame('web.request', $span['name']);
        $this->assertSame('sidecar-thread-mode-test', $span['service']);
    }

    public function testThreadModeMultipleRequests()
    {
        if (\getenv('DD_TRACE_TEST_SAPI') !== 'fpm-fcgi') {
            $this->markTestSkipped('This test requires DD_TRACE_TEST_SAPI=fpm-fcgi');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Thread mode not supported on Windows');
        }

        // This test validates that multiple PHP-FPM workers can successfully
        // connect to the same master listener thread and submit traces
        $traces = $this->tracesFromWebRequest(function () {
            for ($i = 0; $i < 3; $i++) {
                $spec = GetSpec::create("Request $i", "/simple?request=$i");
                $this->call($spec);
            }
        });

        // Verify all traces were submitted
        $this->assertGreaterThanOrEqual(3, count($traces), 'Expected at least 3 traces from multiple requests');

        foreach ($traces as $trace) {
            $this->assertNotEmpty($trace);
            $this->assertSame('web.request', $trace[0]['name']);
        }
    }
}
