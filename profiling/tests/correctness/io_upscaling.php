<?php
// Test file I/O upscaling with operations below the 100 KiB sampling interval.

const OPERATION_SIZE = 10 * 1024;
const OPERATION_COUNT = 163840;

function write_operations($handle, $data) {
    for ($i = 0; $i < OPERATION_COUNT; $i++) {
        fwrite($handle, $data);
    }
}

function read_operations($handle) {
    for ($i = 0; $i < OPERATION_COUNT; $i++) {
        fread($handle, OPERATION_SIZE);
    }
}

function main() {
    $write_handle = fopen('/dev/null', 'wb');
    $read_handle = fopen('/dev/zero', 'rb');

    write_operations($write_handle, str_repeat('A', OPERATION_SIZE));
    read_operations($read_handle);

    fclose($write_handle);
    fclose($read_handle);
}
main();
