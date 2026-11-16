<?php
// Test I/O profiling correctness.
//
// We write and read temporary files in known proportions to verify that
// file-io-write-size and file-io-read-size samples are captured correctly.
//
// Function `big_write` writes 2x more data than `small_write`, so we expect
// ~67% vs ~33% of file-io-write-size. Similarly for reads.
//
// The write functions return the file path so that read functions can reuse
// the same files without creating their own (which would pollute the write
// profile). Files are cleaned up in the main loop.

function big_write() {
    $tmp = tempnam(sys_get_temp_dir(), 'io_test');
    // Write 2MB
    file_put_contents($tmp, str_repeat('A', 1024 * 1024 * 2));
    return $tmp;
}

function small_write() {
    $tmp = tempnam(sys_get_temp_dir(), 'io_test');
    // Write 1MB
    file_put_contents($tmp, str_repeat('B', 1024 * 1024));
    return $tmp;
}

function big_read($path) {
    // Read 2MB
    file_get_contents($path, false, null, 0, 1024 * 1024 * 2);
}

function small_read($path) {
    // Read 1MB
    file_get_contents($path, false, null, 0, 1024 * 1024);
}

function main() {
    $duration = $_ENV["EXECUTION_TIME"] ?? 10;
    $end = microtime(true) + $duration;
    while (microtime(true) < $end) {
        $big = big_write();
        $small = small_write();
        big_read($big);
        small_read($small);
        unlink($big);
        unlink($small);
    }
}
main();
