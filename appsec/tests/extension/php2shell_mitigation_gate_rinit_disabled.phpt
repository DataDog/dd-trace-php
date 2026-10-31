--TEST--
PHP2Shell mitigation (CVE-2026-109618): an appsec RINIT that resolves to fully disabled closes the gate; the startup parse before it is still covered
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
// The tracer is disabled only in the request's environment: php-cgi runs as a
// FastCGI responder and DD_TRACE_ENABLED=0 is a FastCGI param, as a php-fpm
// pool env[] directive would be. The zend_extension startup hook therefore
// sees nothing explicitly disabled and installs the hooks, and the
// "mitigation allowed" flag starts out true. appsec's RINIT then resolves to
// fully disabled (the tracer is off) and clears the flag. A tracer disabled in
// the process environment would instead be seen at startup, and no hook would
// be installed (php2shell_mitigation_gated_disabled.phpt covers that for
// appsec). datadog.appsec.testing must be off: with it on, appsec ignores the
// tracer state.
//  - The script runs after RINIT, so it sees the mitigation inactive.
//  - The same with variables_order=S: no GET, POST or COOKIE parse calls
//    treat_data, so the per-process config is first resolved in RINIT, by
//    dd_php2shell_set_allowed(false). It resolves the config before it
//    stores, so the later resolution (MINFO) cannot store true over it.
//  - A body crossing memory_limit is parsed before RINIT, while the flag is
//    still true: it ends in the deferred fatal, not the engine's own error.
require __DIR__ . '/inc/php2shell_cgi.php';
require __DIR__ . '/inc/php2shell_fcgi.php';
$ini = ['datadog.appsec.testing' => '0'];
$params = ['DD_TRACE_ENABLED' => '0'];
foreach (p2s_child_lines(p2s_fcgi_run($ini, [], $params)) as $line) {
    echo "after RINIT: active={$line['active']}\n";
}
foreach (p2s_child_lines(p2s_fcgi_run($ini + ['variables_order' => 'S'], [], $params)) as $line) {
    echo "variables_order=S, after RINIT: active={$line['active']}\n";
}
$body = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$r = p2s_fcgi_run(['memory_limit' => '256M'] + $ini, [], $params, $body);
$peak = p2s_peak($r);
if ($peak === null) {
    echo "calibration failed\n";
    p2s_dump($r);
    exit;
}
$soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
$soft = $soft_mib * P2S_MIB;
$r = p2s_fcgi_run(['memory_limit' => "{$soft_mib}M"] + $ini, [], $params, $body);
$outcome = p2s_outcome($r, $soft);
echo "startup parse: outcome=$outcome\n";
if ($outcome !== 'deferred') {
    p2s_dump($r, "peak=$peak soft=$soft");
}
--EXPECT--
after RINIT: active=false
variables_order=S, after RINIT: active=false
startup parse: outcome=deferred
