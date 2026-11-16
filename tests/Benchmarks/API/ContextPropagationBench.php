<?php

declare(strict_types=1);

namespace Benchmarks\API;

// The subjects warm up for 1000 calls, so that opcache's tracing JIT has compiled them before they
// are timed. Each takes about 1µs, and with a single warmup call, compiling a subject's trace
// (~100µs) can land in the 100 timed revs and add about 1µs per call. Whether it does depends on
// which of the JIT's 128 shared hot counters a subject gets, and those are handed out in turn as
// code is loaded. So adding a benchmark file or a composer package moved the extra ~1µs between
// subjects, and check-big-regressions reported ±80% changes in code that hadn't changed.
class ContextPropagationBench
{
    public static $traceContext128Bit = [
        'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        'tracestate' => 'rojo=00f067aa0ba902b7,dd=t.dm:-1;t.congo:t61rcWkgMzE'
    ];

    public static $traceContext64Bit = [
        'traceparent' => '00-0000000000000000sc151df7d6ee5e2d6-a3978fb9b92502a8-01',
        'tracestate' => 'rojo=00f067aa0ba902b7,dd=t.dm:-1;t.congo:t61rcWkgMzE'
    ];

    public static $headers128Bit = [
        'x-datadog-trace-id' => '0af7651916cd43dd8448eb211c80319c',
        'x-datadog-parent-id' => 'b7ad6b7169203331',
        'x-datadog-sampling-priority' => 3,
        'x-datadog-origin' => 'datadog'
    ];

    public static $headers64Bit = [
        "x-datadog-trace-id" => 42,
        "x-datadog-parent-id" => 10,
        "x-datadog-origin" => "datadog",
        "x-datadog-sampling-priority" => 3,
    ];

    /**
     * @Revs(100)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("resetContext")
     * @Warmup(1000)
     */
    public function benchExtractTraceContext128Bit()
    {
        \DDTrace\consume_distributed_tracing_headers(self::$traceContext128Bit);
    }

    /**
     * @Revs(100)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("resetContext")
     * @Warmup(1000)
     */
    public function benchExtractTraceContext64Bit()
    {
        \DDTrace\consume_distributed_tracing_headers(self::$traceContext64Bit);
    }

    /**
     * @Revs(100)
     * @Iterations(15)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("resetContext")
     * @Warmup(1000)
     */
    public function benchExtractHeaders128Bit()
    {
        \DDTrace\consume_distributed_tracing_headers(self::$headers128Bit);
    }

    /**
     * @Revs(100)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("resetContext")
     * @Warmup(1000)
     */
    public function benchExtractHeaders64Bit()
    {
        \DDTrace\consume_distributed_tracing_headers(self::$headers64Bit);
    }

    /**
     * @Revs(100)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("setUp128BitContext")
     * @Warmup(1000)
     */
    public function benchInject128Bit()
    {
        \DDTrace\generate_distributed_tracing_headers();
    }

    /**
     * @Revs(100)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("setUp64BitContext")
     * @Warmup(1000)
     */
    public function benchInject64Bit()
    {
        \DDTrace\generate_distributed_tracing_headers();
    }

    public function setUp128BitContext()
    {
        $this->resetContext();
        \DDTrace\consume_distributed_tracing_headers(self::$traceContext128Bit);
    }

    public function setUp64BitContext()
    {
        $this->resetContext();
        \DDTrace\consume_distributed_tracing_headers(self::$traceContext64Bit);
    }

    public function resetContext()
    {
        \DDTrace\set_distributed_tracing_context("0", "0");
    }
}
