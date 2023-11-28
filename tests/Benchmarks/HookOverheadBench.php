<?php

declare(strict_types=1);

namespace DDTrace\Benchmarks;

class HookOverheadBench
{
    public function dummyFunction(): int
    {
        return time();
    }

    public function setUpHook()
    {
        \DDTrace\trace_method('DDTrace\Benchmarks\HookOverheadBench', 'dummyFunction', function () { });
    }

    /**
     * @BeforeMethods("setUpHook")
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
