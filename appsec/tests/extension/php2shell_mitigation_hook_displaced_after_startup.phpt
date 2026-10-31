--TEST--
PHP2Shell mitigation: hooks overwritten after ddappsec's startup hook are reported as displaced, logged once per slot, and fail open
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// p2s_stub.so, loaded after ddappsec with p2s_stub.startup=1, registers a
// zend_extension in its MINIT whose startup hook writes the treat_data and
// input_filter slots without chaining, and the read_post and
// php_rfc1867_callback slots (forwarding to the ddappsec wrappers it finds
// there). That hook runs after ddappsec's, which captured the slots, so they
// are displaced, and the mitigation is off for treat_data and input_filter
// (out of scope: hooks displaced after startup are detected, not repaired):
//  - MINFO reports the four rows as Displaced, in both requests of -T 2;
//  - one warning per slot is logged, at the first request shutdown only;
//  - a single large field crossing memory_limit gets the engine's own error.
require __DIR__ . '/inc/php2shell_cgi.php';
$ini = ['p2s_stub.startup' => '1', 'datadog.appsec.log_file' => 'stderr'];
$after = ['ext_after' => true];

$r = p2s_cgi_run($ini, ['-T', '2'], p2s_cgi_env(P2S_CHILD_SCRIPT, 'GET', ['QUERY_STRING' => 'p2s_tag=abc']), null, $after);
$lines = p2s_child_lines($r);
foreach ($lines as $i => $line) {
    echo "request ", $i + 1, ": tag={$line['tag']} hooks={$line['hooks']}\n";
}
if (count($lines) !== 2) {
    p2s_dump($r, 'T2');
}
preg_match_all('/PHP2Shell mitigation: (\S+) was replaced after startup/', $r['out'] . $r['err'], $m);
$counts = array_count_values($m[1]);
ksort($counts);
echo "displacement_log:";
foreach ($counts as $slot => $n) {
    echo " $slot=$n";
}
echo "\n";

$body = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$peak = p2s_calibrate($ini, [], $body, $after);
$soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
$soft = $soft_mib * P2S_MIB;
$r = p2s_cgi_run($ini + ['memory_limit' => "{$soft_mib}M", 'post_max_size' => '32M'], [], [], $body, $after);
$outcome = p2s_outcome($r, $soft);
echo "single_field: outcome=$outcome\n";
if ($outcome !== 'native') {
    p2s_dump($r, "peak=$peak soft=$soft");
}
--EXPECTF--
request 1: tag=Xbc hooks=treat_data:Displaced,input_filter:Displaced,rfc1867:Displaced,deactivate:Installed%Aread_post:Displaced
request 2: tag=Xbc hooks=treat_data:Displaced,input_filter:Displaced,rfc1867:Displaced,deactivate:Installed%Aread_post:Displaced
displacement_log: php_rfc1867_callback=1 sapi_module.input_filter=1 sapi_module.read_post=1 sapi_module.treat_data=1
single_field: outcome=native
