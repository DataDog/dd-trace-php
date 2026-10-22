--TEST--
PHP2Shell mitigation (CVE-2026-109618): an error left pending with no later VM interrupt is dropped when the request is disarmed, and does not reach the next request of the same process
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// php-cgi -T 2 serves two identical requests in one process, with
// memory_limit=16M. Each one starts with VM safe points, prints REQ RAN, and
// ends with a concatenation that takes the usage to about 18 MiB: above the
// soft limit, below the hard limit (32 MiB). Nothing after it is a delivery
// point (safe points outside the VM are out of scope), so the error stays
// pending until appsec's RSHUTDOWN removes the handlers and drops it: the
// request completes with no error. Request 2 must not inherit it: its first
// safe points raise nothing, and it completes the same way.
require __DIR__ . '/inc/php2shell_cgi.php';
$r = p2s_cgi_run(['memory_limit' => '16M'], ['-T', '2'],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_pending_dropped.php', 'GET', [
        'P2S_HALF' => (string)(5 * P2S_MIB),
    ]));
$all = $r['out'] . $r['err'];
echo "requests_ran=", substr_count($r['out'], 'REQ RAN'), "\n";
echo "fatals=", preg_match_all('/Allowed memory size of \d+ bytes exhausted/', $all), "\n";
echo "mitigation_logs=", substr_count($all, 'PHP2Shell mitigation:'), "\n";
echo "status={$r['status']}\n";
if (substr_count($r['out'], 'REQ RAN') !== 2 || strpos($all, 'Allowed memory size') !== false) {
    p2s_dump($r);
}
--EXPECT--
requests_ran=2
fatals=0
mitigation_logs=0
status=0
