<?php

declare(strict_types=1);

namespace Benchmarks\API;

class HookBench
{
    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchWithoutHook()
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->dummyMethod1();
        }
    }

    /**
     * @BeforeMethods("setUpTraceMethod")
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchHookOverheadTraceMethod()
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->dummyMethod1();
        }
    }

    /**
     * @BeforeMethods("setUpTraceFunction")
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchHookOverheadTraceFunction()
    {
        for ($i = 0; $i < 1000; $i++) {
            dummyFunction1();
        }
    }

    /**
     * @BeforeMethods("setUpInstallHookOnMethod")
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchHookOverheadInstallHookOnMethod()
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->dummyMethod2();
        }
    }

    /**
     * @BeforeMethods("setUpInstallHookOnFunction")
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchHookOverheadInstallHookOnFunction()
    {
        for ($i = 0; $i < 1000; $i++) {
            dummyFunction2();
        }
    }

    public function setUpTraceMethod()
    {
        \DDTrace\trace_method('Benchmarks\API\HookBench', 'dummyMethod1', function () { });
    }

    public function setUpTraceFunction()
    {
        \DDTrace\trace_function('Benchmarks\API\dummyFunction1', function () { });
    }

    public function setUpInstallHookOnMethod()
    {
        \DDTrace\install_hook('Benchmarks\API\HookBench::dummyMethod2', function () { });
    }

    public function setUpInstallHookOnFunction()
    {
        \DDTrace\install_hook('Benchmarks\API\dummyFunction2', function () { });
    }

    public function dummyMethod1(): int
    {
        return time();
    }

    public function dummyMethod2(): int
    {
        return time();
    }

    public function dummyMethod3(): int
    {
        return time();
    }
}

function dummyFunction1(): int
{
    return time();
}

function dummyFunction2(): int
{
    return time();
}
