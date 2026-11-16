<?php

declare(strict_types=1);

if (!extension_loaded('ddtrace')) {
    fwrite(STDERR, "ddtrace is not loaded\n");
    exit(1);
}
if (!extension_loaded('datadog-profiling')) {
    fwrite(STDERR, "datadog-profiling is not loaded\n");
    exit(1);
}
if (!function_exists('Datadog\\Profiling\\trigger_time_sample')) {
    fwrite(STDERR, "Datadog\\Profiling\\trigger_time_sample is unavailable\n");
    exit(1);
}

$mode = $argv[1] ?? 'overrides';
$epochs = positiveInt($argv[2] ?? getenv('EPOCHS') ?: '20', 'epochs');
$iterations = positiveInt($argv[3] ?? getenv('ITERATIONS') ?: '50000', 'iterations');
$warmupIterations = positiveInt(
    getenv('WARMUP_ITERATIONS') ?: (string) min(5000, $iterations),
    'warmup iterations',
);

$root = null;
if ($mode === 'matching' || $mode === 'overrides') {
    $root = DDTrace\start_trace_span();
    if ($mode === 'matching') {
        $root->service = 'benchmark-base-service';
        $root->env = 'benchmark-base-environment';
        $root->version = 'benchmark-base-version';
    } else {
        $root->service = 'benchmark-thread-context-service';
        $root->env = 'benchmark-thread-context-environment';
        $root->version = 'benchmark-thread-context-version';
    }
} elseif ($mode !== 'inactive') {
    throw new InvalidArgumentException("unknown mode: $mode");
}

function positiveInt(string $value, string $name): int
{
    if (!ctype_digit($value) || (int) $value < 1) {
        throw new InvalidArgumentException("$name must be a positive integer");
    }
    return (int) $value;
}

function collectSamples(int $iterations): void
{
    for ($i = 0; $i < $iterations; ++$i) {
        Datadog\Profiling\trigger_time_sample();
    }
}

collectSamples($warmupIterations);

for ($epoch = 1; $epoch <= $epochs; ++$epoch) {
    $started = hrtime(true);
    collectSamples($iterations);
    $elapsed = hrtime(true) - $started;

    echo json_encode([
        'benchmark' => 'otel-profiler-context',
        'mode' => $mode,
        'epoch' => $epoch,
        'iterations' => $iterations,
        'elapsed_ns' => $elapsed,
        'ns_per_sample' => $elapsed / $iterations,
        'samples_per_second' => $iterations * 1_000_000_000 / $elapsed,
        'php_version' => PHP_VERSION,
        'ddtrace_version' => phpversion('ddtrace'),
        'profiler_version' => phpversion('datadog-profiling'),
        'git_revision' => getenv('GIT_REVISION') ?: 'unknown',
        'architecture' => php_uname('m'),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}

// Keep the root alive and attached through every measured sample.
unset($root);

// Extension shutdown is outside the measured operation. Each mode runs in a
// disposable container, so skip teardown after all results have been flushed.
fflush(STDOUT);
exec('/bin/kill -9 ' . getmypid());
