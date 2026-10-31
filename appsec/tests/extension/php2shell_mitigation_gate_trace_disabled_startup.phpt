--TEST--
PHP2Shell mitigation (CVE-2026-109618): a tracer explicitly disabled at startup installs no hook, so not even the first startup parse is armed
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
post_max_size=32M
--FILE--
<?php
// appsec resolves to fully disabled when the tracer is disabled (outside
// datadog.appsec.testing). With DD_TRACE_ENABLED=0 in php-cgi's process
// environment, the zend_extension startup hook already sees that, so it
// installs no hook and the mitigation is off for the process: a body crossing
// memory_limit ends in the engine's own out-of-memory error, even in the first
// startup parse, before any RINIT. With the tracer disabled only in the
// request's environment, the hooks are installed and that first parse is
// armed (php2shell_mitigation_gate_rinit_disabled.phpt).
require __DIR__ . '/inc/php2shell_cgi.php';
require __DIR__ . '/inc/php2shell_fcgi.php';
$ini = ['datadog.appsec.testing' => '0'];
$env = ['DD_TRACE_ENABLED' => '0'];
foreach (p2s_child_lines(p2s_fcgi_run($ini, $env, [])) as $line) {
    echo "script: active={$line['active']} hooks={$line['hooks']}\n";
}
$body = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$r = p2s_fcgi_run(['memory_limit' => '256M'] + $ini, $env, [], $body);
$peak = p2s_peak($r);
if ($peak === null) {
    echo "calibration failed\n";
    p2s_dump($r);
    exit;
}
$soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
$soft = $soft_mib * P2S_MIB;
$r = p2s_fcgi_run(['memory_limit' => "{$soft_mib}M"] + $ini, $env, [], $body);
$outcome = p2s_outcome($r, $soft);
echo "first startup parse: outcome=$outcome\n";
if ($outcome !== 'native') {
    p2s_dump($r, "peak=$peak soft=$soft");
}
--EXPECT--
script: active=false hooks=
first startup parse: outcome=native
