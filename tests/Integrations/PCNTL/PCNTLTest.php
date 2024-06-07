<?php

namespace DDTrace\Tests\Integrations\PCNTL;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

const ACCEPTABLE_TEST_EXECTION_TIME_S = 1.4;

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
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped('xdebug is enabled, which causes the tracer to slow down dramatically.');
        }

        $start = \microtime(true);
        $this->executeCli(
            $scriptPath,
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ],
            [],
            '',
            false,
            true
        );
        $end = \microtime(true);
        $this->assertLessThan(ACCEPTABLE_TEST_EXECTION_TIME_S, $end - $start);
        if (\dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
            \dd_trace_synchronous_flush();
        }
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
        list($requests) = $this->inCli(
            __DIR__ . '/scripts/synthetic.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            ]
        );

        $this->assertCount(2, $requests);
        $this->assertFlameGraph([$requests[1]], [
            SpanAssertion::exists('synthetic.php')->withChildren([
                SpanAssertion::exists('pcntl_fork'),
            ]),
        ]);

        $this->assertFlameGraph([$requests[0]], [
            SpanAssertion::exists('synthetic.php'),
        ]);

        $childSpan = $requests[0][0];
        $parentSpan = $requests[1][1];
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
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
                'DD_TRACE_DEBUG' => 'false',
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
        list($requests) = $this->inCli(
            __DIR__ . '/scripts/short-running.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            ]
        );

        $this->assertCount(2, $requests);
        $this->assertFlameGraph([$requests[0]], [ // child
            SpanAssertion::exists('short-running.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
            ]),
        ]);
        $this->assertFlameGraph([$requests[1]], [
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
        list($requests) = $this->inCli(
            __DIR__ . '/scripts/short-running-multiple.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            ]
        );

        $this->assertCount(6, $requests);

        $this->assertFlameGraph([array_pop($requests)], [ // main trace
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
            $this->assertFlameGraph([$traces], [
                SpanAssertion::exists('short-running-multiple.php')->withChildren([
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
                ]),
            ]);
        }
    }

    public function testCliShortRunningMultipleNestedForks()
    {
        list($requests) = $this->inCli(
            __DIR__ . '/scripts/short-running-multiple-nested.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            ]
        );

        $this->assertFlameGraph([array_pop($requests)], [ // main trace
            SpanAssertion::exists('short-running-multiple-nested.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
                SpanAssertion::exists('pcntl_fork'),
            ]),
        ]);

        $this->assertFlameGraph([array_shift($requests)], [
            SpanAssertion::exists('short-running-multiple-nested.php')->withChildren([
                SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
            ]),
        ]);

        foreach ($requests as $traces) {
            $this->assertFlameGraph([$traces], [
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
        list($requests) = $this->inCli(
            __DIR__ . '/scripts/long-running-autoflush.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_AGENT_FLUSH_INTERVAL' => 0,
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );
        $this->assertCount(4, $requests);

        for ($i = 0; $i < 4; $i += 2) {
            $this->assertFlameGraph([$requests[$i]], [
                SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
            ]);

            $this->assertFlameGraph([$requests[$i + 1]], [
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
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped('xdebug is enabled, which causes the tracer to slow down dramatically.');
        }

        list($requests) = $this->inCli(
            __DIR__ . '/scripts/long-running-manual-flush.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'false',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => 5000,
            ]
        );
        $this->assertCount(6, $requests);

        usort($requests, function ($a, $b) { return count($a) <=> count($b); });

        for ($i = 0; $i < 3; ++$i) {
            $this->assertFlameGraph([$requests[$i]], [
                SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
            ]);
        }

        for ($i = 3; $i < 6; ++$i) {
            $this->assertFlameGraph([$requests[$i]], [
                SpanAssertion::exists('manual_tracing')->withChildren([
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/get'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/headers'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
                    SpanAssertion::exists('pcntl_fork'),
                ]),
            ]);
        }
    }
}
