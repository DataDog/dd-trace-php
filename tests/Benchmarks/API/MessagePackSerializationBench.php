<?php

declare(strict_types=1);

namespace Benchmarks\API;

class MessagePackSerializationBench
{
    /**
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @ParamProviders({"provideTraceArrays"})
     * @Warmup(1)
     */
    public function benchMessagePackSerialization($traceArray)
    {
        \dd_trace_serialize_msgpack($traceArray);
    }

    public function provideTraceArrays()
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

        $traceArray = \dd_trace_serialize_closed_spans();

        return [
            [$traceArray],
        ];
    }
}
