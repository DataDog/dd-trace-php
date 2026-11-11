<?php

// Timing harness for the Kafka and AMQP throughput workloads; BROKER (`kafka`,
// the default, or `rabbitmq`) picks which one.
//
// PHP stand-in for `dotnet timeit --count 25 --warmup 5` / Ruby's
// `run_timeit.rb`. Runs the workload for a fixed number of warmup + timed
// iterations in-process (so the tracer, when enabled, stays active across all
// iterations), then emits a JSON artifact in the shape
// `steps/compare-results.py` expects:
//
//   [
//     {
//       "median": <median duration ms>,
//       "metrics": {
//         "process.internal_duration_ms.median": <ms>,
//         "process.internal_duration_ms.std_err": <ms>,
//         "process.iteration_wall_ms.median": <ms>,
//         "process.rss_bytes.median": <bytes>,
//         "php.memory_usage_bytes.median": <bytes>,
//         "phase.produce_ms.median": <ms>,
//         "phase.consume_ms.median": <ms>,
//         "sidecar.cpu_ms.median": <ms, per timed iteration>,
//         "sidecar.rss_bytes": <bytes, after the timed iterations>,
//         "warmup.iterations": <WARMUP>,
//         "config.commit_every": <COMMIT_EVERY>
//       },
//       "name": <EXPERIMENT_NAME>,
//       "warmUpCount": <WARMUP>,
//       "count": <COUNT>,
//       "data": [
//         {
//           "status": "Passed",
//           "metrics": {
//             "process.internal_duration_ms": <ms>,
//             "process.corrected_duration_ms": <ms, iteration wall time>,
//             "runtime.dotnet.mem.committed": <bytes, process RSS>
//           }
//         },
//         ... one per timed iteration
//       ]
//     }
//   ]
//
// `data` is for steps/convert-to-common-format.sh: bp-analyzer's TimeItSharp
// converter reads only per-iteration values from it, under TimeItSharp's
// metric names (it maps runtime.dotnet.mem.committed to RSS, whatever the
// runtime), and fails on a file without it.
//
// Duration is produce_ms + consume_ms per iteration (setup/teardown --
// client construction, topic creation, close -- excluded); the
// whole-iteration wall time is kept separately as process.iteration_wall_ms,
// for context only. The memory metric is process RSS, read from
// /proc/self/status (the analog of Ruby's get_process_mem and Java's
// /proc/self/status read); the Zend heap (memory_get_usage(true)) is reported
// for context. Medians are used for robustness against outliers, matching the
// other languages' reports.
//
// The sidecar.* metrics have no counterpart on the other branches: the PHP
// tracer aggregates DSM checkpoints in its sidecar process
// (datadog-ipc-helper), not in the PHP process, so that part of DSM's cost
// is not in the process numbers. They are reported separately, for context,
// and omitted when no sidecar is running (the baseline experiment).

const WORKLOADS = [
    'kafka' => [
        'file' => 'kafka_throughput.php',
        'integration' => 'DDTrace\Integrations\Kafka\KafkaIntegration',
        // Kafka was the first integration with DSM, so a build with
        // dsm_set_checkpoint (check_environment) has it.
        'dsm_method' => null,
    ],
    'rabbitmq' => [
        'file' => 'amqp_throughput.php',
        'integration' => 'DDTrace\Integrations\AMQP\AMQPIntegration',
        // AMQP got DSM later (dd-trace-php #4347), so check for it.
        'dsm_method' => 'setDataStreamsProduceCheckpoint',
    ],
];

$broker = getenv('BROKER') ?: 'kafka';
if (!isset(WORKLOADS[$broker])) {
    fwrite(STDERR, "ERROR: unknown BROKER '$broker'\n");
    exit(1);
}
require_once __DIR__ . '/' . WORKLOADS[$broker]['file'];

function fail(string $message): void
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
}

// Fail loudly rather than report a meaningless result if the run isn't measuring
// what its experiment says it is.
function check_environment(string $broker, bool $tracerEnabled, bool $dsmEnabled): void
{
    if ($broker === 'kafka' && !extension_loaded('rdkafka')) {
        fail('the rdkafka extension is not loaded');
    }
    if (!$tracerEnabled) {
        if (extension_loaded('ddtrace')) {
            fail('the baseline experiment loaded the ddtrace extension');
        }
        return;
    }

    if (!extension_loaded('ddtrace')) {
        fail('ENABLE_TRACER=true but the ddtrace extension is not loaded');
    }
    // The Kafka integration only loads for php-rdkafka 6+
    // (KafkaIntegration::init); below that every experiment measures nothing.
    if ($broker === 'kafka' && version_compare(phpversion('rdkafka'), '6', '<')) {
        fail('php-rdkafka ' . phpversion('rdkafka') . ' is too old for the Kafka integration, which needs 6+');
    }
    if (!function_exists('DDTrace\Internal\dsm_set_checkpoint')) {
        fail('dd-trace-php ' . phpversion('ddtrace') . ' has no Data Streams Monitoring support');
    }
    if ((bool) dd_trace_env_config('DD_DATA_STREAMS_ENABLED') !== $dsmEnabled) {
        fail('the tracer sees DD_DATA_STREAMS_ENABLED=' . var_export(dd_trace_env_config('DD_DATA_STREAMS_ENABLED'), true)
            . ', expected ' . var_export($dsmEnabled, true));
    }

    // Checkpoints are only recorded once the agent's /info lists the DSM
    // endpoint. Until then they are hashed and encoded but never reach the
    // sidecar, which would read as "DSM is nearly free". Wait for it in both
    // tracer experiments, so they start from the same state.
    if (!dd_trace_internal_fn('await_agent_info', 10000)) {
        fail('the tracer got no /info response from the agent');
    }
    if ($dsmEnabled) {
        $info = dd_trace_internal_fn('get_agent_info');
        $endpoints = array_map(function ($endpoint) {
            return ltrim($endpoint, '/');
        }, $info['endpoints'] ?? []);
        if (!in_array('v0.1/pipeline_stats', $endpoints, true)) {
            fail('the agent does not accept /v0.1/pipeline_stats');
        }
    }
}

// An integration is loaded the first time the application uses a class it
// hooks. If the broker's hasn't been by the end of warmup, the tracer
// experiments ran uninstrumented. A build can also have DSM for Kafka but not
// for AMQP.
function check_instrumented(string $broker): void
{
    $integration = WORKLOADS[$broker]['integration'];
    if (!class_exists($integration, false)) {
        fail("$integration was not loaded -- would measure nothing");
    }
    $dsmMethod = WORKLOADS[$broker]['dsm_method'];
    if ($dsmMethod !== null && !method_exists($integration, $dsmMethod)) {
        fail("this dd-trace-php build has no Data Streams Monitoring support in $integration");
    }
}

function rss_bytes(string $pid = 'self'): ?int
{
    $status = @file_get_contents("/proc/$pid/status");
    if ($status === false || !preg_match('/^VmRSS:\s+(\d+) kB/m', $status, $matches)) {
        return null;
    }

    return (int) $matches[1] * 1024;
}

/** @return string[] the pids of the running sidecars */
function sidecar_pids(): array
{
    $pids = [];
    // The sidecar runs as `datadog-ipc-helper`, which the kernel truncates to
    // this process name. Exited ones linger as zombies when nothing reaps
    // them (steps/start-benchmark.sh kills the previous experiment's), and
    // have no memory to report.
    foreach (glob('/proc/[0-9]*/comm') as $path) {
        $pid = basename(dirname($path));
        if (trim((string) @file_get_contents($path)) === 'dd-ipc-helper' && rss_bytes($pid) !== null) {
            $pids[] = $pid;
        }
    }

    return $pids;
}

/** @return float user + system CPU time of $pids, in ms */
function cpu_ms(array $pids): float
{
    static $ticksPerSecond = null;
    if ($ticksPerSecond === null) {
        $ticksPerSecond = (int) trim((string) shell_exec('getconf CLK_TCK')) ?: 100;
    }

    $ticks = 0;
    foreach ($pids as $pid) {
        $stat = @file_get_contents("/proc/$pid/stat");
        if ($stat === false) {
            continue;
        }
        // The fields after the parenthesized command name; utime and stime are
        // the 14th and 15th fields of the whole line.
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        $ticks += (int) $fields[11] + (int) $fields[12];
    }

    return $ticks * 1000.0 / $ticksPerSecond;
}

function median(array $values): float
{
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);

    return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2.0 : (float) $values[$mid];
}

function std_err(array $values): float
{
    $n = count($values);
    if ($n <= 1) {
        return 0.0;
    }
    $mean = array_sum($values) / $n;
    $variance = array_sum(array_map(function ($v) use ($mean) {
        return ($v - $mean) ** 2;
    }, $values)) / ($n - 1);

    return sqrt($variance) / sqrt($n);
}

function main(string $broker): void
{
    $warmup = int_from_env('WARMUP', 5, 0);
    $count = int_from_env('COUNT', 25, 1);
    $outputPath = getenv('TIMEIT_OUTPUT') ?: 'results.json';
    // Validated once up front so a bad value fails before any warmup run.
    $commitEvery = commit_every_from_env();
    check_num_threads();

    $tracerEnabled = getenv('ENABLE_TRACER') === 'true';
    $dsmEnabled = getenv('DD_DATA_STREAMS_ENABLED') === 'true';
    check_environment($broker, $tracerEnabled, $dsmEnabled);

    $runId = 0;
    for ($i = 0; $i < $warmup; $i++) {
        run_benchmark($runId++, $commitEvery);
    }
    if ($tracerEnabled) {
        check_instrumented($broker);
    }

    $sidecarPids = sidecar_pids();
    $sidecarCpuBefore = cpu_ms($sidecarPids);

    $durationsMs = [];
    $iterationWallMs = [];
    $rssBytes = [];
    $memoryUsageBytes = [];
    $produceMs = [];
    $consumeMs = [];

    for ($i = 0; $i < $count; $i++) {
        $start = now_ms();
        $phases = run_benchmark($runId++, $commitEvery);
        $iterationWallMs[] = now_ms() - $start;
        $durationsMs[] = $phases['produce_ms'] + $phases['consume_ms'];
        $rssBytes[] = rss_bytes();
        $memoryUsageBytes[] = memory_get_usage(true);
        $produceMs[] = $phases['produce_ms'];
        $consumeMs[] = $phases['consume_ms'];
    }

    $metrics = [
        'process.internal_duration_ms.median' => median($durationsMs),
        'process.internal_duration_ms.std_err' => std_err($durationsMs),
        'process.iteration_wall_ms.median' => median($iterationWallMs),
        'process.rss_bytes.median' => median($rssBytes),
        'php.memory_usage_bytes.median' => median($memoryUsageBytes),
        'phase.produce_ms.median' => median($produceMs),
        'phase.consume_ms.median' => median($consumeMs),
    ];
    // The timed iterations' CPU time, spread evenly over them: the sidecar
    // works asynchronously, so there's no per-iteration sample to take a
    // median of.
    if ($sidecarPids) {
        $metrics['sidecar.cpu_ms.median'] = (cpu_ms($sidecarPids) - $sidecarCpuBefore) / $count;
        $metrics['sidecar.rss_bytes'] = array_sum(array_map('rss_bytes', $sidecarPids));
    }
    $metrics['warmup.iterations'] = $warmup;
    $metrics['config.commit_every'] = $commitEvery;

    $data = [];
    foreach ($durationsMs as $i => $durationMs) {
        $data[] = [
            'status' => 'Passed',
            'metrics' => [
                'process.internal_duration_ms' => $durationMs,
                'process.corrected_duration_ms' => $iterationWallMs[$i],
                'runtime.dotnet.mem.committed' => $rssBytes[$i],
            ],
        ];
    }
    $result = [[
        'median' => $metrics['process.internal_duration_ms.median'],
        'metrics' => $metrics,
        'name' => getenv('EXPERIMENT_NAME') ?: $broker,
        'warmUpCount' => $warmup,
        'count' => $count,
        'data' => $data,
    ]];
    file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT) . "\n");

    printf(
        "Duration median: %.3f ms (+/- %.3f) | Iteration wall median: %.3f ms | RSS median: %.2f MB | "
            . "produce median: %.3f ms | consume+commit median: %.3f ms | sidecar CPU per iteration: %s | "
            . "runs: %d (warmup %d) | commit every %d | %s\n",
        $metrics['process.internal_duration_ms.median'],
        $metrics['process.internal_duration_ms.std_err'],
        $metrics['process.iteration_wall_ms.median'],
        $metrics['process.rss_bytes.median'] / 1000000.0,
        $metrics['phase.produce_ms.median'],
        $metrics['phase.consume_ms.median'],
        isset($metrics['sidecar.cpu_ms.median']) ? sprintf('%.3f ms', $metrics['sidecar.cpu_ms.median']) : 'n/a',
        $count,
        $warmup,
        $commitEvery,
        $broker
    );
}

main($broker);
