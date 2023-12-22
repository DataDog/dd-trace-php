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
     */
    public function benchWithoutHook()
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->dummyFunction();
        }
    }

    public function setUp()
    {
        \DDTrace\trace_method('Benchmarks\API\HookBench', 'dummyFunction', function () { });
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     */
    public function benchHookOverhead()
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->dummyFunction();
        }
    }

    public function dummyFunction(): int
    {
        return time();
    }
}
