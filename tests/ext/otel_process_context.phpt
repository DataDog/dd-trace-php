--TEST--
Linux OTel Process Context excludes request-varying service metadata
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70400) die('skip: FFI requires PHP 7.4+');
if (PHP_OS_FAMILY !== 'Linux') die('skip: Linux only');
if (!extension_loaded('ffi')) die('skip: ffi extension required');
if (!is_readable('/proc/self/maps')) die('skip: readable /proc/self/maps required');
?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_SERVICE=configured-service
DD_ENV=configured-env
DD_VERSION=configured-version
DD_HOSTNAME=configured-host
--FILE--
<?php

require __DIR__ . '/includes/otel_thread_context.inc';

function processContextSnapshot(FFI $ffi): array
{
    foreach (file('/proc/self/maps', FILE_IGNORE_NEW_LINES) as $line) {
        if (strpos($line, 'OTEL_CTX') === false) {
            continue;
        }

        $address = $ffi->cast('uintptr_t', intval(strtok($line, '-'), 16));
        $header = $ffi->cast('otel_process_context_header *', $address);
        if (FFI::string($header->signature, 8) !== 'OTEL_CTX') {
            throw new RuntimeException('invalid Process Context signature');
        }

        return [
            'payload' => FFI::string($header->payload_ptr, $header->payload_size),
            'published_at' => $header->monotonic_published_at_ns,
        ];
    }

    throw new RuntimeException('OTel Process Context mapping not found');
}

function payloadContains(string $payload, array $values): bool
{
    foreach ($values as $value) {
        if (strpos($payload, $value) === false) {
            return false;
        }
    }
    return true;
}

$ffi = FFI::cdef(
    <<<'C'
    typedef struct {
        uint8_t signature[8];
        uint32_t version;
        uint32_t payload_size;
        uint64_t monotonic_published_at_ns;
        uint8_t *payload_ptr;
    } otel_process_context_header;
    C,
);

$snapshot = processContextSnapshot($ffi);
$payload = $snapshot['payload'];
echo "Configured service values excluded: "; var_dump(
    strpos($payload, 'configured-service') === false
    && strpos($payload, 'configured-env') === false
    && strpos($payload, 'configured-version') === false
    && strpos($payload, 'configured-host') === false
);
echo "Runtime and host resource fields: "; var_dump(payloadContains($payload, [
    'service.instance.id',
    'telemetry.sdk.language',
    'php',
    'telemetry.sdk.version',
    'telemetry.sdk.name',
    'libdatadog',
    'host.name',
]));
echo "Process and thread metadata: "; var_dump(payloadContains($payload, [
    'datadog.process_tags',
    'threadlocal.schema_version',
    'tlsdesc_v1_dev',
    'threadlocal.attribute_key_map',
    'datadog.local_root_span_id',
    'service.name',
    'deployment.environment.name',
    'service.version',
    'thread.id',
]));
echo "Request-varying keys are thread-only: "; var_dump(
    substr_count($payload, 'service.name') === 1
    && substr_count($payload, 'deployment.environment.name') === 1
    && substr_count($payload, 'service.version') === 1
);

preg_match(
    '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
    $payload,
    $matches
);
$root = DDTrace\start_span();
echo "Runtime ID matches tracer: "; var_dump($root->meta['runtime-id'] === $matches[0]);
DDTrace\close_span();

$publishedAt = $snapshot['published_at'];
$threadContext = new OtelThreadContext();
$root = DDTrace\start_span();
ini_set('datadog.service', 'runtime-service');
ini_set('datadog.env', 'runtime-env');
ini_set('datadog.version', 'runtime-version');

$attributes = $threadContext->attributes();
echo "Runtime values published in Thread Context: "; var_dump(
    $attributes[1] === 'runtime-service'
    && $attributes[2] === 'runtime-env'
    && $attributes[3] === 'runtime-version'
);

$snapshot = processContextSnapshot($ffi);
$payload = $snapshot['payload'];
echo "Runtime values excluded from Process Context: "; var_dump(
    strpos($payload, 'runtime-service') === false
    && strpos($payload, 'runtime-env') === false
    && strpos($payload, 'runtime-version') === false
);
echo "Publication timestamp unchanged: "; var_dump(
    $snapshot['published_at'] === $publishedAt
);
DDTrace\close_span();

?>
--EXPECT--
Configured service values excluded: bool(true)
Runtime and host resource fields: bool(true)
Process and thread metadata: bool(true)
Request-varying keys are thread-only: bool(true)
Runtime ID matches tracer: bool(true)
Runtime values published in Thread Context: bool(true)
Runtime values excluded from Process Context: bool(true)
Publication timestamp unchanged: bool(true)
