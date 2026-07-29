--TEST--
Linux OTel Process Context publishes configured defaults and runtime updates
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
--FILE--
<?php

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
echo "Configured resource defaults: "; var_dump(payloadContains($payload, [
    'service.name',
    'configured-service',
    'deployment.environment.name',
    'configured-env',
    'service.version',
    'configured-version',
]));
echo "Runtime and host resource fields: "; var_dump(payloadContains($payload, [
    'service.instance.id',
    'telemetry.sdk.language',
    'php',
    'telemetry.sdk.version',
    'telemetry.sdk.name',
    'libdatadog',
    'host.name',
    'container.id',
]));
echo "Process and thread metadata: "; var_dump(payloadContains($payload, [
    'datadog.process_tags',
    'threadlocal.schema_version',
    'tlsdesc_v1_dev',
    'threadlocal.attribute_key_map',
    'datadog.local_root_span_id',
    'thread.id',
]));

preg_match(
    '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
    $payload,
    $matches
);
$root = DDTrace\start_span();
echo "Runtime ID matches tracer: "; var_dump($root->meta['runtime-id'] === $matches[0]);
DDTrace\close_span();

$publishedAt = $snapshot['published_at'];
ini_set('datadog.service', 'runtime-service');
ini_set('datadog.env', 'runtime-env');
ini_set('datadog.version', 'runtime-version');

$snapshot = processContextSnapshot($ffi);
$payload = $snapshot['payload'];
echo "Runtime defaults republished: "; var_dump(payloadContains($payload, [
    'runtime-service',
    'runtime-env',
    'runtime-version',
]));
echo "Publication timestamp advanced: "; var_dump(
    $snapshot['published_at'] > $publishedAt
);

?>
--EXPECT--
Configured resource defaults: bool(true)
Runtime and host resource fields: bool(true)
Process and thread metadata: bool(true)
Runtime ID matches tracer: bool(true)
Runtime defaults republished: bool(true)
Publication timestamp advanced: bool(true)
