--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): a request armed at RINIT (pending or block) whose WAF decision becomes monitor mid-request keeps its arming; its deferred fatal gets only the deferred span tag, not the monitor tags (memory_error, phase) as well
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
// One php-cgi child per row, mode auto, runtime forced to full (testing
// override). The decision the process starts with (testing override) arms the
// request at RINIT. Mid-request, the child exchanges request_init with the
// mock helper, whose response carries the new decision (with
// datadog.appsec.testing on, the testing rinit() and rshutdown() drive it;
// php2shell_child_waf_helper.php with P2S_WAF_THEN). Outside FrankenPHP's
// worker mode nothing re-arms or disarms the request after RINIT, so it stays
// armed. In the same request it then allocates 56 MiB with memory_limit=32M
// (php2shell_child_runtime_alloc.php) and prints the root span's PHP2Shell
// tags (php2shell_child_waf.php): the crossing is deferred to the next safe
// point and tagged deferred=runtime. The deferred fatal is an E_ERROR that
// starts "Allowed memory size of", as the engine's own; the monitor detection,
// now in force, must recognise it as ours and not tag it as a native
// memory_limit error too.
require __DIR__ . '/inc/php2shell_cgi.php';
$rows = [
    // initial decision (null: pending), answer mid-request
    [null, 'monitor'],
    ['block', 'monitor'],
    ['block', 'block'],
];
foreach ($rows as list($initial, $answer)) {
    $env = p2s_cgi_env(P2S_WAF_HELPER_CHILD, 'GET', [
        'P2S_WAF_ANSWER' => $answer,
        'P2S_WAF_THEN' => '1',
        'P2S_ALLOC' => (string)(56 * P2S_MIB),
        'P2S_REQUEST_STATUS' => '1',
    ]) + p2s_waf_env('auto', $initial, 'full');
    $r = p2s_cgi_run(['memory_limit' => '32M'], [], $env);
    $asked = preg_match('/^WAF ASKED address=(\S+) decision=(\S+)/m', $r['out'], $m)
        ? "decision=$m[2]" : 'not asked';
    $armed = preg_match('/^REQUEST_STATUS armed=(\w+)/m', $r['out'], $m) ? $m[1] : '?';
    $outcome = p2s_outcome($r, 32 * P2S_MIB);
    $tags = p2s_waf_tags($r);
    $label = ($initial === null ? 'pending' : $initial) . " -> $answer";
    echo "$label: $asked outcome=$outcome armed=$armed ", p2s_waf_tags_str($tags), "\n";
    if ($tags === null || $armed === '?' || !in_array($outcome, ['deferred', 'native'], true)) {
        p2s_dump($r);
    }
}
--EXPECT--
pending -> monitor: decision=monitor outcome=deferred armed=true span=true deferred=runtime memory_error=- phase=- user_keep=true
block -> monitor: decision=monitor outcome=deferred armed=true span=true deferred=runtime memory_error=- phase=- user_keep=true
block -> block: decision=block outcome=deferred armed=true span=true deferred=runtime memory_error=- phase=- user_keep=true
