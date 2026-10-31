--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): under the WAF decision monitor, an allocation the operating system refuses ("Out of memory") is reported on the span as memory_error=out_of_memory, phase=runtime, and the trace is kept; not under none
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_unless_ext('posix');
if (!is_readable('/proc/self/status')) {
    die("skip: needs /proc/self/status (Linux)");
}
$lim = posix_getrlimit();
if (!isset($lim['hard totalmem']) || $lim['hard totalmem'] !== 'unlimited') {
    die("skip: needs an unlimited hard RLIMIT_AS");
}
--INI--
extension=ddtrace.so
memory_limit=-1
--FILE--
<?php
// The child caps its address space (RLIMIT_AS) 40 MiB above its current size,
// then allocates 256 MiB after RINIT (php2shell_child_waf.php running
// php2shell_child_runtime_alloc.php). With memory_limit=-1 the engine does
// not refuse it itself; the mmap() fails and zend_mm raises "Out of memory
// (allocated N bytes)", the second kind of memory error (the mitigation
// cannot defer it, so block mode leaves it alone). Mode auto, runtime forced
// to full; the decision is the testing override the process starts with.
require __DIR__ . '/inc/php2shell_cgi.php';
foreach (['monitor', 'none'] as $decision) {
    $env = p2s_cgi_env(P2S_WAF_CHILD, 'GET', [
        'P2S_AS_HEADROOM' => (string)(40 * P2S_MIB),
        'P2S_ALLOC' => (string)(256 * P2S_MIB),
    ]) + p2s_waf_env('auto', $decision, 'full');
    $r = p2s_cgi_run([], [], $env);
    // "Out of memory (allocated N bytes)"; PHP 7.0 debug builds omit "bytes".
    $oom = (bool)preg_match('/Out of memory \(allocated \d+/', $r['out'] . $r['err']);
    $tags = p2s_waf_tags($r);
    echo "$decision: os_refusal=", var_export($oom, true), " ", p2s_waf_tags_str($tags), "\n";
    if (!$oom || $tags === null) {
        p2s_dump($r);
    }
}
--EXPECT--
monitor: os_refusal=true span=true deferred=- memory_error=out_of_memory phase=runtime user_keep=true
none: os_refusal=true span=true deferred=- memory_error=- phase=- user_keep=false
