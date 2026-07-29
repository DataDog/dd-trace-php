--TEST--
Linux OTel Process Context is MADV_DONTFORK and republished after fork
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70400) die('skip: FFI requires PHP 7.4+');
if (PHP_OS_FAMILY !== 'Linux') die('skip: Linux only');
if (!extension_loaded('ffi')) die('skip: ffi extension required');
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (!is_readable('/proc/self/smaps')) die('skip: readable /proc/self/smaps required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function processContextMappings(): array
{
    $mappings = [];
    $mapping = null;

    foreach (file('/proc/self/smaps', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^[0-9a-f]+-[0-9a-f]+\s/', $line)) {
            if ($mapping !== null) {
                $mappings[] = $mapping;
            }
            $mapping = strpos($line, 'OTEL_CTX') === false
                ? null
                : ['header' => $line, 'flags' => []];
        } elseif ($mapping !== null && strpos($line, 'VmFlags:') === 0) {
            $mapping['flags'] = preg_split('/\s+/', trim(substr($line, strlen('VmFlags:'))));
        }
    }

    if ($mapping !== null) {
        $mappings[] = $mapping;
    }

    return $mappings;
}

function processContextRuntimeId(FFI $ffi, array $mapping): string
{
    $address = intval(strtok($mapping['header'], '-'), 16);
    $address = $ffi->cast('uintptr_t', $address);
    $header = $ffi->cast('otel_process_context_header *', $address);
    if (FFI::string($header->signature, 8) !== 'OTEL_CTX') {
        throw new RuntimeException('invalid Process Context signature');
    }

    $payload = FFI::string($header->payload_ptr, $header->payload_size);
    if (!preg_match(
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
        $payload,
        $matches
    )) {
        throw new RuntimeException('runtime ID not found in Process Context payload');
    }

    return $matches[0];
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

$parentMappings = processContextMappings();
echo "Parent has one mapping: "; var_dump(count($parentMappings) === 1);
echo "Parent mapping is MADV_DONTFORK: "; var_dump(
    in_array('dc', $parentMappings[0]['flags'], true)
);
$parentRuntimeId = processContextRuntimeId($ffi, $parentMappings[0]);
echo "Parent has runtime ID: "; var_dump($parentRuntimeId !== '');
$parentRoot = DDTrace\start_span();
echo "Parent runtime ID matches tracer: "; var_dump(
    $parentRoot->meta['runtime-id'] === $parentRuntimeId
);
DDTrace\close_span();

$pid = pcntl_fork();
if ($pid === -1) {
    throw new RuntimeException('pcntl_fork failed');
}
if ($pid === 0) {
    $childMappings = processContextMappings();
    echo "Child has one mapping: "; var_dump(count($childMappings) === 1);
    echo "Child mapping is MADV_DONTFORK: "; var_dump(
        in_array('dc', $childMappings[0]['flags'], true)
    );

    $childRuntimeId = processContextRuntimeId($ffi, $childMappings[0]);
    $childRoot = DDTrace\start_span();
    echo "Child runtime ID matches tracer: "; var_dump(
        $childRoot->meta['runtime-id'] === $childRuntimeId
    );
    echo "Child mapping was republished: "; var_dump(
        $childRuntimeId !== $parentRuntimeId
    );
    DDTrace\close_span();
    exit;
}

pcntl_waitpid($pid, $status);
echo "Child exited successfully: "; var_dump(
    pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0
);

?>
--EXPECT--
Parent has one mapping: bool(true)
Parent mapping is MADV_DONTFORK: bool(true)
Parent has runtime ID: bool(true)
Parent runtime ID matches tracer: bool(true)
Child has one mapping: bool(true)
Child mapping is MADV_DONTFORK: bool(true)
Child runtime ID matches tracer: bool(true)
Child mapping was republished: bool(true)
Child exited successfully: bool(true)
