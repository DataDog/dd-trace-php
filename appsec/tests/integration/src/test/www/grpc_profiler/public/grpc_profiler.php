<?php

header('Content-Type: application/json');

const GRPC_PROFILER_IDLE_SECONDS = 25;

function grpc_profiler_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function grpc_profiler_round_trip(int $iteration): array
{
    $certificate = file_get_contents('/tmp/grpc-server.crt');
    grpc_profiler_check(
        $certificate !== false,
        'could not read gRPC certificate',
    );
    $target = getenv('GRPC_TEST_TARGET');
    grpc_profiler_check($target !== false, 'gRPC target is not configured');

    $credentials = Grpc\ChannelCredentials::createComposite(
        Grpc\ChannelCredentials::createSsl($certificate),
        Grpc\CallCredentials::createFromPlugin(
            static function ($context): array {
                // Keep this native gRPC thread in PHP for a wall-time sample.
                $deadline = hrtime(true) + 50_000_000;
                $value = strlen((string) $context->method_name);
                do {
                    $value = (($value * 33) ^ 0x5a5a) & 0xffff;
                } while (hrtime(true) < $deadline);

                return ['x-dd-grpc-plugin' => ["minimal-$value"]];
            },
        ),
    );
    $channel = new Grpc\Channel($target, [
        'grpc.ssl_target_name_override' => 'grpc.test',
        'grpc.default_authority' => 'grpc.test',
        'credentials' => $credentials,
        'force_new' => true,
    ]);
    $call = new Grpc\Call(
        $channel,
        '/appsec.CrashProbe/Check',
        Grpc\Timeval::now()->add(new Grpc\Timeval(10_000_000)),
        'grpc.test',
    );
    $payload = "request:minimal-$iteration";

    $call->startBatch([
        Grpc\OP_SEND_INITIAL_METADATA => [],
        Grpc\OP_SEND_MESSAGE => ['message' => $payload],
        Grpc\OP_SEND_CLOSE_FROM_CLIENT => true,
    ]);
    $response = $call->startBatch([
        Grpc\OP_RECV_INITIAL_METADATA => true,
        Grpc\OP_RECV_MESSAGE => true,
        Grpc\OP_RECV_STATUS_ON_CLIENT => true,
    ]);
    grpc_profiler_check(
        $response->message === "response:minimal-$iteration",
        'client received the wrong payload',
    );
    grpc_profiler_check(
        $response->status->code === Grpc\STATUS_OK,
        'gRPC call returned a non-OK status',
    );
    $channel->close();

    return ['completed' => true];
}

if (($_GET['mode'] ?? '') === 'config') {
    echo json_encode([
        'worker_pid' => getmypid(),
        'php_version' => PHP_VERSION,
        'php_zts' => PHP_ZTS,
        'grpc_version' => phpversion('grpc'),
        'profiler_version' => phpversion('datadog-profiling'),
        'profiler_enabled' => ini_get('datadog.profiling.enabled'),
        'allocation_enabled' => ini_get(
            'datadog.profiling.allocation_enabled',
        ),
        'appsec_enabled' => ini_get('datadog.appsec.enabled'),
        'trace_enabled' => ini_get('datadog.trace.enabled'),
        'trace_sender' => dd_trace_env_config(
            'DD_TRACE_SIDECAR_TRACE_SENDER',
        ),
        'fork_support' => ini_get('grpc.enable_fork_support'),
    ], JSON_THROW_ON_ERROR);
    return;
}

$workerPid = getmypid();
$before = grpc_profiler_round_trip(0);
sleep(GRPC_PROFILER_IDLE_SECONDS);

echo json_encode([
    'worker_pid' => $workerPid,
    'grpc_version' => phpversion('grpc'),
    'idle_seconds' => GRPC_PROFILER_IDLE_SECONDS,
    'before' => $before,
    'after' => grpc_profiler_round_trip(1),
], JSON_THROW_ON_ERROR);
