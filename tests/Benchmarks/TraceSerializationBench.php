<?php

declare(strict_types=1);

class TraceSerializationBench
{
    /**
     * @Revs(1)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     * @BeforeMethods("setUp")
     */
    public function benchSerializeTrace()
    {
        \dd_trace_serialize_closed_spans();
    }

    public function setUp()
    {
        for ($i = 0; $i < 100; $i++) {
            $span = \DDTrace\start_span();
            $span->name = 'bench.trace_serialization';
            $span->meta['foo'] = 'bar';
            $span->metrics['bar'] = 1;
        }

        for ($i = 0; $i < 100; $i++) {
            \DDTrace\close_span();
        }
    }
}
