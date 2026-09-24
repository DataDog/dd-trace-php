<?php

declare(strict_types=1);

namespace Benchmarks\API;

class TraceSerializationBench
{
    /**
     * @Revs(1)
     * @Iterations(20)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @BeforeMethods({"warmUpSampling", "setUp"})
     */
    public function benchSerializeTrace()
    {
        \dd_trace_serialize_closed_spans();
    }

    public function warmUpSampling()
    {
        if (!\dd_trace_env_config('DD_TRACE_SIDECAR_TRACE_SENDER')) {
            return;
        }

        // PHPBench runs BeforeMethods in each worker, outside the subject timer.
        // A connected sidecar may not have published /info or agent sampling rates yet.
        if (!\dd_trace_internal_fn('await_agent_info', 5000)) {
            throw new \RuntimeException('Trace serialization benchmark requires a ready agent /info response');
        }

        // Sampling rates are published after a trace response. Exercise automatic sampling
        // and the real sender, without changing the priority of the measured trace.
        $span = \DDTrace\start_trace_span();
        $span->name = 'bench.trace_serialization.warmup';
        \DDTrace\close_span();
        \DDTrace\flush();

        $deadline = microtime(true) + 5;
        do {
            // Trace enqueueing is asynchronous, so the first flush can precede it.
            \dd_trace_synchronous_flush(5000);
            $config = \dd_trace_internal_fn('get_agent_sampling_config');
            if (isset($config['rate_by_service']) && is_array($config['rate_by_service'])) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('Trace serialization benchmark requires agent sampling rates');
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
