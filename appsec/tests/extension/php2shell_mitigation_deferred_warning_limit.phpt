--TEST--
PHP2Shell mitigation (CVE-2026-109618): the deferred-fatal warning is emitted under a finite memory limit, so an output buffer callback it runs cannot allocate without bound (PHP 8.4+)
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80400);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// request_parse_body() defers a 24 MiB field at memory_limit=16M. The
// warning logged before the deferred fatal is displayed (display_errors=1),
// which runs the output buffer callback, i.e. user code. By then the engine
// must be off the sentinel limit: the limit becomes the larger of
// memory_limit and the current usage plus an 8 MiB margin. A 4 MiB allocation
// in the callback fits, and the request still ends in the deferred fatal. A
// 64 MiB one does not: the request dies in the callback (the engine's
// out-of-memory error cannot be displayed from an output handler, which turns
// it into another fatal error).
require __DIR__ . '/inc/php2shell_cgi.php';
$body = 'f=' . str_repeat('Z', 24 * P2S_MIB) . '&g=1';
foreach (['0', '4', '64'] as $alloc_mib) {
    $log = tempnam(sys_get_temp_dir(), 'p2s_cb');
    $r = p2s_cgi_run(['memory_limit' => '16M', 'post_max_size' => '64M'], [],
        p2s_cgi_env(__DIR__ . '/inc/php2shell_child_rpb_ob_callback.php', 'PUT',
            ['P2S_LOG' => $log, 'P2S_ALLOC_MIB' => $alloc_mib]),
        $body);
    $deferred = p2s_outcome($r) === 'deferred';
    echo "alloc=$alloc_mib MiB status={$r['status']} deferred_fatal=", var_export($deferred, true),
        " callback: ", str_replace("\n", '; ', trim((string)file_get_contents($log))), "\n";
    if ($deferred !== ($alloc_mib !== '64')) {
        p2s_dump($r);
    }
    @unlink($log);
}
--EXPECT--
alloc=0 MiB status=255 deferred_fatal=true callback: callback saw warning
alloc=4 MiB status=255 deferred_fatal=true callback: callback saw warning; callback allocated 4 MiB
alloc=64 MiB status=255 deferred_fatal=false callback: callback saw warning
