--TEST--
PHP2Shell mitigation (CVE-2026-109618): a deferred fatal that ext/soap swallows (raised inside SoapClient::__doRequest(), turned into a SoapFault) leaves the request armed, so a later crossing is deferred again
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80000);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_unless_cgi_ext('soap');
--FILE--
<?php
// memory_limit=16M; a user __doRequest() allocates 10 MiB, which raises the
// deferred fatal inside the SoapClient call; ext/soap catches the bailout and
// throws a SoapFault, which the script catches. The script then parses a
// 6 MiB string with keys of the form kNNNNN<pad>[x] (see
// php2shell_mitigation_after_fatal_shutdown_parse_str.phpt), which crosses
// the limit again: deferred, and the array is complete.
require __DIR__ . '/inc/php2shell_cgi.php';
$r = p2s_cgi_run(['memory_limit' => '16M', 'max_input_vars' => '1000000', 'datadog.appsec.log_file' => 'stderr'], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_after_fatal.php', 'GET', [
        'P2S_FATAL' => 'soap', 'P2S_AFTER' => 'main', 'P2S_ALLOC' => (string)(10 * P2S_MIB),
        'P2S_SRC' => (string)(6 * P2S_MIB),
    ]));
$caught = strpos($r['err'], 'CAUGHT ' . p2s_deferred_msg(16 * P2S_MIB)) !== false;
$raises = p2s_raises($r);
$native = p2s_native_oom($r);
$consistent = preg_match('/^CHECK count=(\d+) iter=(\d+) bad=0$/m', $r['err'], $m) && $m[1] === $m[2] && $m[1] > 0;
$parsed = strpos($r['err'], 'PARSED') !== false;
echo "caught=", var_export($caught, true), " raises=$raises native=", var_export($native, true),
    " parse_ended=", var_export(!$parsed, true), " consistent=", var_export($consistent, true),
    " status={$r['status']}\n";
if (!$caught || $raises !== 2 || $native || $parsed || !$consistent) {
    p2s_dump($r);
}
--EXPECT--
caught=true raises=2 native=false parse_ended=true consistent=true status=255
