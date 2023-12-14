<?php

declare(strict_types=1);

namespace DDTrace\Benchmarks\Core;

class HookOverheadBench
{
    public function dummyFunction(): int
    {
        return time();
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     */
    public function benchWithoutHook()
    {
        $this->dummyFunction();
    }

    public function setUp()
    {
        \DDTrace\trace_method('Benchmarks\Core\HookOverheadBench', 'dummyFunction', function () { });
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     */
    public function benchHookOverhead()
    {
        $this->dummyFunction();
    }
}
