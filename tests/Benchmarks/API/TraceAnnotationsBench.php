<?php

declare(strict_types=1);

namespace Benchmarks\API;

class TraceAnnotationsBench
{
    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     */
    public function benchTraceAnnotationOverhead()
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->dummyFunction();
        }
    }

    #[\DDTrace\Trace]
    public function dummyFunction(): int
    {
        return time();
    }

    #[\DDTrace\Trace]
    public function dummyFunction(): int
    {
        return time();
    }
}
