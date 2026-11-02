<?php

declare(strict_types=1);

namespace Benchmarks;

use DDTrace\Tests\Common\Utils;

/**
 * Turns Data Streams Monitoring on for a benchmark, such that its checkpoints are recorded as they
 * are in production: written to the shared-memory tables the sidecar reads.
 */
trait DataStreamsTrait
{
    /**
     * @return string the pathway context of a produce checkpoint, as a consumer would receive it
     */
    public function enableDataStreams(): string
    {
        Utils::putEnvAndReloadConfig([
            'DD_DATA_STREAMS_ENABLED=1',
            'DD_SERVICE=dsm-bench',
            'DD_ENV=bench',
        ]);

        // Checkpoints are only recorded once the agent's /info lists the DSM endpoint. Until then
        // they are hashed and encoded but never reach the sidecar, and the subjects would quietly
        // time only part of a checkpoint.
        if (!\dd_trace_internal_fn('await_agent_info', 5000)) {
            throw new \RuntimeException('Data Streams benchmarks require a ready agent /info response');
        }
        $info = \dd_trace_internal_fn('get_agent_info');
        $endpoints = array_map(function ($endpoint) {
            return ltrim($endpoint, '/');
        }, $info['endpoints'] ?? []);
        if (!in_array('v0.1/pipeline_stats', $endpoints, true)) {
            throw new \RuntimeException('Data Streams benchmarks require an agent that accepts /v0.1/pipeline_stats');
        }

        // A process sends its first checkpoint to the sidecar over IPC, and the sidecar creates the
        // shared-memory tables that later checkpoints and backlogs are written to. The send does
        // not wait for the tables, and until the process has them open everything goes over IPC,
        // at about ten times the cost. So give the sidecar time to create them, and open them with
        // a second checkpoint, before anything is timed.
        $context = \DDTrace\Internal\dsm_set_checkpoint(['direction:out', 'topic:orders', 'type:kafka']);
        if (!is_string($context)) {
            throw new \RuntimeException('Data Streams benchmarks require Data Streams Monitoring and the sidecar to be enabled');
        }
        usleep(50000);
        \DDTrace\Internal\dsm_set_checkpoint(['direction:out', 'topic:orders', 'type:kafka']);

        return $context;
    }
}
