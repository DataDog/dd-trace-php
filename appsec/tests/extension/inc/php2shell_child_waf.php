<?php
// Child script for the php2shell_mitigation_waf_*.phpt tests: the span tags
// the mitigation sets for a memory error, and whether the trace is kept. A
// shutdown function (it also runs after a fatal error) prints
//   WAF TAGS span=B deferred=V memory_error=V phase=V user_keep=B
// with V the value of the root span's _dd.appsec.php2shell.<name> tag, or -
// when it is absent, and user_keep whether the sampling priority is 2
// (USER_KEEP). Needs ddtrace.so in the child.
//
// The scenario is the script P2S_WAF_SCRIPT, a file of inc/ (default
// php2shell_child_runtime_alloc.php, driven by its own variables). With
// P2S_AS_HEADROOM set (Linux, posix), the address space (RLIMIT_AS) is first
// capped at its current size plus that many bytes, so that a large allocation
// is refused by the operating system ("Out of memory"); the shutdown function
// lifts the cap first.
register_shutdown_function(function () {
    if (getenv('P2S_AS_HEADROOM')) {
        posix_setrlimit(POSIX_RLIMIT_AS, POSIX_RLIMIT_INFINITY, POSIX_RLIMIT_INFINITY);
    }
    $span = function_exists('DDTrace\root_span') ? \DDTrace\root_span() : null;
    $meta = $span !== null && is_array($span->meta) ? $span->meta : [];
    $tag = function ($name) use ($meta) {
        $k = "_dd.appsec.php2shell.$name";
        return isset($meta[$k]) ? $meta[$k] : '-';
    };
    echo "WAF TAGS span=", var_export($span !== null, true),
        " deferred=", $tag('deferred'),
        " memory_error=", $tag('memory_error'),
        " phase=", $tag('phase'),
        " user_keep=", var_export($span !== null && \DDTrace\get_priority_sampling() === 2, true),
        "\n";
});

if (getenv('P2S_AS_HEADROOM')) {
    if (!preg_match('/^VmSize:\s+(\d+) kB$/m', (string)file_get_contents('/proc/self/status'), $m)) {
        echo "NO VMSIZE\n";
        exit;
    }
    $cap = (int)$m[1] * 1024 + (int)getenv('P2S_AS_HEADROOM');
    if (!posix_setrlimit(POSIX_RLIMIT_AS, $cap, POSIX_RLIMIT_INFINITY)) {
        echo "SETRLIMIT FAILED\n";
        exit;
    }
}
$script = getenv('P2S_WAF_SCRIPT');
require __DIR__ . '/' . ($script ? $script : 'php2shell_child_runtime_alloc.php');
