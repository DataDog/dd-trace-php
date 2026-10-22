--TEST--
PHP2Shell mitigation (CVE-2026-109618): with appsec disabled at startup no hook is installed, and a per-request environment that enables appsec does not re-enable the mitigation
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
// php-cgi runs as a FastCGI responder with DD_APPSEC_ENABLED=0 in its process
// environment, so the zend_extension startup hook sees appsec explicitly
// disabled and installs no hook: the mitigation is off for the process. The
// FastCGI params of the first request set DD_APPSEC_ENABLED=1, as a php-fpm
// pool env[] directive would; that cannot re-enable it, because the hooks
// were never installed (decision 12.1: a disable seen at startup is final).
// The large field therefore ends in the engine's own out-of-memory error.
// Each run is a new php-cgi process.
require __DIR__ . '/inc/php2shell_cgi.php';
require __DIR__ . '/inc/php2shell_fcgi.php';
$ini = [];
$env = ['DD_APPSEC_ENABLED' => '0'];
$on = ['DD_APPSEC_ENABLED' => '1'];

$r = p2s_fcgi_run($ini, $env, []);
foreach (p2s_child_lines($r) as $line) {
    echo "process env only: active={$line['active']}\n";
}
$r = p2s_fcgi_run($ini, $env, $on);
foreach (p2s_child_lines($r) as $line) {
    echo "request env enabled: active={$line['active']}\n";
}

$body = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$r = p2s_fcgi_run(['memory_limit' => '256M'] + $ini, $env, $on, $body);
$peak = p2s_peak($r);
if ($peak === null) {
    echo "calibration failed\n";
    p2s_dump($r);
    exit;
}
$soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
$soft = $soft_mib * P2S_MIB;
$r = p2s_fcgi_run(['memory_limit' => "{$soft_mib}M"] + $ini, $env, $on, $body);
$outcome = p2s_outcome($r, $soft);
echo "request env enabled, parse: outcome=$outcome\n";
if ($outcome !== 'native') {
    p2s_dump($r, "peak=$peak soft=$soft");
}
--EXPECT--
process env only: active=false
request env enabled: active=false
request env enabled, parse: outcome=native
