<?php
// Test file I/O upscaling with operations below the 100 KiB sampling interval.

const OPERATION_SIZE = 10 * 1024;
const OPERATION_COUNT = 163840;

function write_operations($handle, $data) {
    for ($i = 0; $i < OPERATION_COUNT; $i++) {
        $offset = 0;
        do {
            $written = fwrite($handle, $offset ? substr($data, $offset) : $data);
            if (!$written) {
                throw new RuntimeException('File write did not make progress');
            }
            $offset += $written;
        } while ($offset < OPERATION_SIZE);
        rewind($handle);
        // Leave room for the consumer of the profiler's bounded sample queue.
        // Every batch still supplies several sub-interval I/O samples.
        if (($i & 63) === 63) usleep(1000);
    }
}

function read_operations($handle) {
    for ($i = 0; $i < OPERATION_COUNT; $i++) {
        $remaining = OPERATION_SIZE;
        do {
            $data = fread($handle, $remaining);
            if ($data === false || $data === '') {
                throw new RuntimeException('File read did not make progress');
            }
            $remaining -= strlen($data);
        } while ($remaining > 0);
        if (($i & 63) === 63) usleep(1000);
    }
}

function main() {
    $write_handle = tmpfile();
    $read_handle = fopen('/dev/zero', 'rb');

    write_operations($write_handle, str_repeat('A', OPERATION_SIZE));
    read_operations($read_handle);

    fclose($write_handle);
    fclose($read_handle);
}
main();
