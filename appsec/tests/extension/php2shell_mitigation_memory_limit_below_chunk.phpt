--TEST--
PHP2Shell mitigation (CVE-2026-109618): a memory_limit below the 2 MiB chunk size does not defer every startup parse
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
if (PHP_VERSION_ID >= 80100)
    die("skip: memory_limit below one chunk is refused on 8.1+");
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// On PHP 7.x and 8.0 the engine enforces at least one zend_mm chunk (2 MiB)
// as its limit, while PG(memory_limit) keeps the configured 1M (7.4.21+ and
// 8.0.8+ raise the value to one chunk before comparing it with the real
// usage), and the heap's real usage is already one chunk when the startup
// parse runs. Taking 1M as the soft limit would defer every request; stock PHP
// serves a small one. The mode off is the control. PHP 8.1+ refuses
// memory_limit=1M and keeps the previous value, hence the skip; the child
// prints the memory_limit in effect, so a refused value fails the test.
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_minimal.php';
foreach (['off', 'on'] as $mode) {
    $r = p2s_cgi_run(['memory_limit' => '1M'], [],
        p2s_cgi_env(CHILD, 'POST', [
            'QUERY_STRING' => 'a=1&b=2',
            'DD_APPSEC_PHP2SHELL_MITIGATION' => $mode,
        ]),
        'c=3');
    $outcome = p2s_outcome($r);
    echo "mode=$mode outcome=$outcome ",
        preg_match('/memory_limit=\S+ get_fields=\d+ post_fields=\d+/', $r['out'], $m) ? $m[0] : '', "\n";
    if ($outcome !== 'ran') {
        p2s_dump($r);
    }
}
--EXPECT--
mode=off outcome=ran memory_limit=1M get_fields=2 post_fields=1
mode=on outcome=ran memory_limit=1M get_fields=2 post_fields=1
