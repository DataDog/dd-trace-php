<?php

declare(strict_types=1);

namespace Benchmarks\API;

class TraceAnnotationsBench
{
    #[\DDTrace\Trace]
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
    public function benchTraceAnnotationOverhead()
    {
        $this->dummyFunction();
    }
}
