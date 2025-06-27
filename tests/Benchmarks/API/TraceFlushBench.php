<?php

declare(strict_types=1);

namespace Benchmarks\API;

use DDTrace\Tests\Common\Utils;

class TraceFlushBench
{
    /**
     * @Revs(1)
     * @Iterations(20)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods("setUp")
     */
    public function benchFlushTrace()
    {
        \DDTrace\flush();
    }

    public function setUp()
    {
        \dd_trace_serialize_closed_spans();
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_GENERATE_ROOT_SPAN=0',
            'DD_TRACE_AUTO_FLUSH_ENABLED=0'
        ]);

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
