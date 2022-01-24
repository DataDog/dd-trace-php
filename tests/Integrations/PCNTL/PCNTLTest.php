<?php

namespace DDTrace\Tests\Integrations\PCNTL;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

const ACCEPTABLE_TEST_EXECTION_TIME_S = 0.4;

final class PCNTLTest extends IntegrationTestCase
{
    protected function ddSetUp()
    {
        $this->resetRequestDumper();
        parent::ddSetUp();
    }

    /**
     * @dataProvider dataProviderAllScripts
     */
    public function testDoesNoHangAtShutdownWhenDisabled($scriptPath)
    {
        if ($scriptPath === (__DIR__ . '/scripts/long-running-manual-flush.php')) {
            $this->markTestSkipped('manual tracing cannot be done when the tracer is disabled because the "DDTrace\\*" classes are not available.');
            return;
        }

        $start = \microtime(true);
        $this->executeCli(
            $scriptPath,
            [
                'DD_TRACE_CLI_ENABLED' => 'false',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );
        $end = \microtime(true);
        $this->assertLessThan(ACCEPTABLE_TEST_EXECTION_TIME_S, $end - $start);
    }

    /**
     * @dataProvider dataProviderAllScripts
     */
    public function testDoesNoHangAtShutdownWhenEnabled($scriptPath)
    {
        $start = \microtime(true);
        $this->executeCli(
            $scriptPath,
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );
        $end = \microtime(true);
        $this->assertLessThan(ACCEPTABLE_TEST_EXECTION_TIME_S, $end - $start);
    }

    public function dataProviderAllScripts()
    {
        return [
            [__DIR__ . '/scripts/synthetic.php'],
            [__DIR__ . '/scripts/short-running.php'],
            [__DIR__ . '/scripts/short-running-multiple.php'],
            [__DIR__ . '/scripts/short-running-multiple-nested.php'],
            [__DIR__ . '/scripts/long-running-autoflush.php'],
            [__DIR__ . '/scripts/long-running-manual-flush.php'],
            [__DIR__ . '/scripts/access-tracer-after-fork.php'],
        ];
    }

    public function testCliShortRunningTracingWhenEnabled()
    {
        list($traces) = $this->inCli(
            __DIR__ . '/scripts/synthetic.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('synthetic.php'),
        ]);
    }

    public function testAccessingTracerAfterForkIsUnproblematic()
    {
        list($traces, $output) = $this->inCli(
            __DIR__ . '/scripts/access-tracer-after-fork.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ],
            [],
            "",
            true
        );

        $this->assertSame("", $output);

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('access-tracer-after-fork.php')->withChildren(
                SpanAssertion::exists('parent')
            ),
        ]);
    }

    public function testCliShortRunningMainSpanAreGenerateBeforeAndAfter()
    {
        list($traces) = $this->inCli(
            __DIR__ . '/scripts/short-running.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('short-running.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
            ]),
        ]);
    }

    public function testCliShortRunningMultipleForks()
    {
        list($traces) = $this->inCli(
            __DIR__ . '/scripts/short-running-multiple.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('short-running-multiple.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
            ]),
        ]);
    }

    public function testCliShortRunningMultipleNestedForks()
    {
        list($traces) = $this->inCli(
            __DIR__ . '/scripts/short-running-multiple-nested.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('short-running-multiple-nested.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
            ]),
        ]);
    }

    public function testCliLongRunningMultipleForksAutoFlush()
    {
        if ($this->matchesPhpVersion('5')) {
            $this->markTestSkipped('autoflushing is not implemented on 5');
            return;
        }

        $this->executeCli(
            __DIR__ . '/scripts/long-running-autoflush.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_AGENT_FLUSH_INTERVAL' => 0,
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );
        $requests = $this->parseMultipleRequestsFromDumpedData();
        $this->assertCount(2, $requests);

        // Both requests have 1 trace each
        $this->assertCount(1, $requests[0]);
        $this->assertCount(1, $requests[1]);

        foreach ($requests as $traces) {
            $this->assertFlameGraph($traces, [
                SpanAssertion::exists('long_running_entry_point')->withChildren([
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
                ]),
            ]);
        }
    }

    public function testCliLongRunningMultipleForksManualFlush()
    {
        $this->executeCli(
            __DIR__ . '/scripts/long-running-manual-flush.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'false',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );
        $requests = $this->parseMultipleRequestsFromDumpedData();
        $this->assertCount(1, $requests, 'Traces are buffered into one request');

        $this->assertFlameGraph($requests[0], [
            SpanAssertion::exists('manual_tracing')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
            ]),
            SpanAssertion::exists('manual_tracing')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
            ]),
        ]);
    }
}
