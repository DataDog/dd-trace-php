--TEST--
PHP2Shell mitigation (CVE-2026-109618): a ddtrace hard-disabled with ddtrace.disable=1 installs no hook, even in mode on, so not even the first startup parse is armed
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
post_max_size=32M
--FILE--
<?php
// With ddtrace.disable=1, ddtrace still runs AppSec's MINIT and the
// zend_extension startup, but AppSec always resolves to fully disabled, under
// datadog.appsec.testing too. The startup capture must see that and install
// no hook, whatever the mode: otherwise the first startup parse of the
// process would be armed (and deferred) although AppSec never runs. The body
// crossing memory_limit must therefore end in the engine's own out-of-memory
// error. The mode is on, so that the result does not depend on the runtime's
// patch state.
require __DIR__ . '/inc/php2shell_cgi.php';
require __DIR__ . '/inc/php2shell_fcgi.php';
$ini = ['ddtrace.disable' => '1', 'datadog.appsec.enabled' => '1'];
$env = ['DD_APPSEC_PHP2SHELL_MITIGATION' => 'on'];
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
