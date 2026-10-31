--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): the WAF decision of one request applies to the next requests of the process (php-cgi -T): none leaves a runtime crossing native, monitor tags it, block defers it, no decision keeps the initial (armed) state
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_if_partial();
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
// One php-cgi process serves two requests (-T 2) per run, mode auto, runtime
// forced to full (testing override). In the first, the child exchanges
// request_init with the mock helper, whose response carries the decision (or
// none); with datadog.appsec.testing on, the real RINIT skips the helper, so
// the child drives it with the testing rinit() and rshutdown()
// (php2shell_child_waf_helper.php). The second request allocates 56 MiB after
// RINIT with memory_limit=32M (php2shell_child_runtime_alloc.php) and prints
// the root span's PHP2Shell tags (php2shell_child_waf.php). Its RINIT arms the
// request, or not, from the decision the first request left behind.
require __DIR__ . '/inc/php2shell_cgi.php';
foreach (['none', 'monitor', 'block', ''] as $answer) {
    $mark = tempnam(sys_get_temp_dir(), 'p2s_mark');
    unlink($mark);
    $env = p2s_cgi_env(P2S_WAF_HELPER_CHILD, 'GET', [
        'P2S_WAF_MARK' => $mark,
        'P2S_WAF_ANSWER' => $answer,
        'P2S_ALLOC' => (string)(56 * P2S_MIB),
        'P2S_REQUEST_STATUS' => '1',
    ]) + p2s_waf_env('auto', null, 'full');
    $r = p2s_cgi_run(['memory_limit' => '32M'], ['-T', '2'], $env);
    @unlink($mark);
    $label = $answer === '' ? 'no decision' : $answer;
    $asked = preg_match('/^WAF ASKED address=(\S+) decision=(\S+)/m', $r['out'], $m)
        ? "address=$m[1] decision=$m[2]" : 'not asked';
    $armed = preg_match('/^REQUEST_STATUS armed=(\w+)/m', $r['out'], $m) ? $m[1] : '?';
    $outcome = p2s_outcome($r, 32 * P2S_MIB);
    $tags = p2s_waf_tags($r);
    echo "answer $label: request 1: $asked\n";
    echo "  request 2: outcome=$outcome armed=$armed ", p2s_waf_tags_str($tags), "\n";
    if ($tags === null || $armed === '?' || !in_array($outcome, ['deferred', 'native'], true)) {
        p2s_dump($r);
    }
}
--EXPECT--
answer none: request 1: address=full decision=none
  request 2: outcome=native armed=false span=true deferred=- memory_error=- phase=- user_keep=false
answer monitor: request 1: address=full decision=monitor
  request 2: outcome=native armed=false span=true deferred=- memory_error=memory_limit phase=runtime user_keep=true
answer block: request 1: address=full decision=block
  request 2: outcome=deferred armed=true span=true deferred=runtime memory_error=- phase=- user_keep=true
answer no decision: request 1: address=full decision=pending
  request 2: outcome=deferred armed=true span=true deferred=runtime memory_error=- phase=- user_keep=true
