<?php

namespace DDTrace\Tests\Integrations\PCNTL;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

final class PCNTLTest extends IntegrationTestCase
{
    private static $acceptable_test_execution_time;

    protected function ddSetUp()
    {
        if (!\dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
            self::$acceptable_test_execution_time = 1.4;
        } elseif (time() < 1743515423) {
            // We'll revisit it once we move off circleci. Relaxing the time check until 2025-04-01.
            self::$acceptable_test_execution_time = 4;
        } else {
            self::$acceptable_test_execution_time = 2.5;
        }

        $this->resetRequestDumper();
        parent::ddSetUp();
    }

    public static function getTestedLibrary()
    {
        return 'ext-pcntl';
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
        $this->assertLessThan(self::$acceptable_test_execution_time, $end - $start);
    }

    public function testCliShortRunningTracingWhenEnabled()
    {
        list($requests) = $this->inCli(
            __DIR__ . '/scripts/synthetic.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            ],
            [],
            '',
            false,
            $this->untilNumberOfTraces(2)
        );

        try {
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
        } catch (\Exception $e) {
            echo "Raw requests:\n";
            var_dump($requests);
            throw $e;
        }
    }

    public function testAccessingTracerAfterForkIsUnproblematic()
    {
        list($traces, $output) = $this->inCli(
            __DIR__ . '/scripts/access-tracer-after-fork.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
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
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            ],
            [],
            '',
            false,
            $this->untilNumberOfTraces(2)
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
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
            ],
            [],
            '',
            false,
            $this->untilNumberOfTraces(6)
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
        $this->inCli(
            __DIR__ . '/scripts/long-running-autoflush.php',
            [
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                'DD_TRACE_AGENT_FLUSH_INTERVAL' => 333,
            ],
            [],
            '',
            false,
            $this->until(
                $this->untilSpan(SpanAssertion::exists('curl_exec', '/httpbin_integration/child-0')),
                $this->untilSpan(SpanAssertion::exists('curl_exec', '/httpbin_integration/child-1')),
                $this->untilSpan(SpanAssertion::exists('long_running_entry_point')->withChildren([
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/entry_point-0'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/main_process-0'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/end_entry_point-0'),
                    SpanAssertion::exists('pcntl_fork'),
                ])),
                $this->untilSpan(SpanAssertion::exists('long_running_entry_point')->withChildren([
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/entry_point-1'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/main_process-1'),
                    SpanAssertion::exists('curl_exec', '/httpbin_integration/end_entry_point-1'),
                    SpanAssertion::exists('pcntl_fork'),
                ]))
            )
        );
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
            ],
            [],
            '',
            false,
            $this->untilNumberOfTraces(9)
        );
        // Individual root spans must be their own traces! No merging allowed.
        $this->assertCount(9, $requests);

        usort($requests, function ($a, $b) { return $a[0]["resource"] <=> $b[0]["resource"]; });

        for ($i = 0; $i < 3; ++$i) {
            $this->assertFlameGraph([$requests[$i]], [
                SpanAssertion::exists('curl_exec', '/httpbin_integration/ip'),
            ]);
        }

        for ($i = 3; $i < 6; ++$i) {
            $this->assertFlameGraph([$requests[$i]], [
                SpanAssertion::exists('curl_exec', '/httpbin_integration/user-agent'),
            ]);
        }

        for ($i = 6; $i < 9; ++$i) {
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
        $this->assertLessThan(self::$acceptable_test_execution_time, $end - $start);
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
}
