<?php

namespace DDTrace\Tests\Integrations\PCNTL;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

const ACCEPTABLE_TEST_EXECTION_TIME_S = 1.2;

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
        $this->executeCli(
            __DIR__ . '/scripts/synthetic.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );
        $requests = $this->parseMultipleRequestsFromDumpedData();

        $this->assertCount(2, $requests);
        $this->assertFlameGraph($requests[1], [
            SpanAssertion::exists('synthetic.php')->withChildren([
                SpanAssertion::exists('pcntl_fork'),
            ]),
        ]);

        $this->assertFlameGraph($requests[0], [
            SpanAssertion::exists('synthetic.php'),
        ]);

        $childSpan = $requests[0][0][0];
        $parentSpan = $requests[1][0][1];
        $this->assertSame($childSpan["trace_id"], $parentSpan["trace_id"]);
        $this->assertSame($childSpan["parent_id"], $parentSpan["span_id"]);
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
            SpanAssertion::exists('access-tracer-after-fork.php')->withChildren([
                SpanAssertion::exists('parent'),
                SpanAssertion::exists('pcntl_fork'),
            ]),
        ]);
    }

    public function testCliShortRunningMainSpanAreGenerateBeforeAndAfter()
    {
        $this->executeCli(
            __DIR__ . '/scripts/short-running.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );
        $requests = $this->parseMultipleRequestsFromDumpedData();

        $this->assertCount(2, $requests);
        $this->assertFlameGraph($requests[0], [ // child
            SpanAssertion::exists('short-running.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
            ]),
        ]);
        $this->assertFlameGraph($requests[1], [
            SpanAssertion::exists('short-running.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
                SpanAssertion::exists('pcntl_fork'),
            ]),
        ]);
    }

    public function testCliShortRunningMultipleForks()
    {
        $this->executeCli(
            __DIR__ . '/scripts/short-running-multiple.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );
        $requests = $this->parseMultipleRequestsFromDumpedData();

        $this->assertCount(6, $requests);

        $this->assertFlameGraph(array_pop($requests), [ // main trace
            SpanAssertion::exists('short-running-multiple.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
                SpanAssertion::exists('pcntl_fork'),
                SpanAssertion::exists('pcntl_fork'),
                SpanAssertion::exists('pcntl_fork'),
                SpanAssertion::exists('pcntl_fork'),
                SpanAssertion::exists('pcntl_fork'),
            ]),
        ]);

        foreach ($requests as $traces) {
            $this->assertFlameGraph($traces, [
                SpanAssertion::exists('short-running-multiple.php')->withChildren([
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
                ]),
            ]);
        }
    }

    public function testCliShortRunningMultipleNestedForks()
    {
        $this->executeCli(
            __DIR__ . '/scripts/short-running-multiple-nested.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );
        $requests = $this->parseMultipleRequestsFromDumpedData();

        $this->assertFlameGraph(array_pop($requests), [ // main trace
            SpanAssertion::exists('short-running-multiple-nested.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
                SpanAssertion::exists('pcntl_fork'),
            ]),
        ]);

        $this->assertFlameGraph(array_shift($requests), [
            SpanAssertion::exists('short-running-multiple-nested.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
            ]),
        ]);

        foreach ($requests as $traces) {
            $this->assertFlameGraph($traces, [
                SpanAssertion::exists('short-running-multiple-nested.php')->withChildren([
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
                    SpanAssertion::exists('pcntl_fork'),
                ]),
            ]);
        }
    }

    public function testCliLongRunningMultipleForksAutoFlush()
    {
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
        $this->assertCount(4, $requests);

        for ($i = 0; $i < 4; $i += 2) {
            $this->assertCount(1, $requests[$i]);
            $this->assertCount(1, $requests[$i + 1]);

            $this->assertFlameGraph($requests[$i], [
                SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
            ]);

            $this->assertFlameGraph($requests[$i + 1], [
                SpanAssertion::exists('long_running_entry_point')->withChildren([
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
                    SpanAssertion::exists('pcntl_fork'),
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
        $this->assertCount(4, $requests);

        $this->assertFlameGraph($requests[0], [
            SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
            SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
        ]);

        $this->assertFlameGraph($requests[1], [
            SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
            SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
        ]);

        $this->assertFlameGraph($requests[2], [
            SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
            SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
        ]);

        $this->assertFlameGraph($requests[3], [
            SpanAssertion::exists('manual_tracing')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
                SpanAssertion::exists('pcntl_fork'),
            ]),
            SpanAssertion::exists('manual_tracing')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
                SpanAssertion::exists('pcntl_fork'),
            ]),
        ]);
    }
}
