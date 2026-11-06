<?php
// Child script for php2shell_mitigation_request_parse_body_os_refusal.phpt
// (PHP 8.4+, Linux, posix extension). The request is a PUT with a large
// urlencoded body. Before request_parse_body(), the script caps its address
// space (RLIMIT_AS) at its current size plus P2S_AS_HEADROOM bytes, so the
// operating system refuses one of the parse's allocations after the soft
// limit has been crossed. That is the engine's "Out of memory" bailout, not
// the mitigation's deferred fatal. The shutdown function then lifts the cap
// and allocates P2S_SHUTDOWN_ALLOC bytes: the engine's memory limit decides
// whether that allocation succeeds.
register_shutdown_function(function () {
    $e = error_get_last();
    echo "LAST ERROR ", $e === null ? 'none' : $e['message'], "\n";
    posix_setrlimit(POSIX_RLIMIT_AS, POSIX_RLIMIT_INFINITY, POSIX_RLIMIT_INFINITY);
    echo "SHUTDOWN usage=", memory_get_usage(true), "\n";
    $s = str_repeat('x', (int)getenv('P2S_SHUTDOWN_ALLOC'));
    echo "SHUTDOWN ALLOCATED ", strlen($s), "\n";
});

if (!preg_match('/^VmSize:\s+(\d+) kB$/m', (string)file_get_contents('/proc/self/status'), $m)) {
    echo "NO VMSIZE\n";
    exit;
}
$cap = (int)$m[1] * 1024 + (int)getenv('P2S_AS_HEADROOM');
if (!posix_setrlimit(POSIX_RLIMIT_AS, $cap, POSIX_RLIMIT_INFINITY)) {
    echo "SETRLIMIT FAILED\n";
    exit;
}
echo "BEFORE PARSE usage=", memory_get_usage(true), "\n";
request_parse_body();
echo "SCRIPT RAN\n";
