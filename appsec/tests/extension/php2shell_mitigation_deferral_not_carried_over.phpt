--TEST--
PHP2Shell mitigation (CVE-2026-109618): a deferral in one request does not fire again in a later request of the same process whose window does not arm (memory_limit=-1)
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80400);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// php-cgi -T 2 serves two requests in one process. Request 1 defers inside
// request_parse_body() (a 24 MiB field, memory_limit=16M) and writes a
// .user.ini that sets memory_limit=-1 for request 2. With no finite limit,
// request 2's startup parse runs unarmed; it must not raise the deferred
// fatal recorded in request 1.
require __DIR__ . '/inc/php2shell_cgi.php';
$dir = sys_get_temp_dir() . '/p2s_carry_' . getmypid();
@mkdir($dir);
@unlink("$dir/marker");
@unlink("$dir/.user.ini");
copy(__DIR__ . '/inc/php2shell_child_carry_over.php', "$dir/child.php");
$body = 'f=' . str_repeat('Z', 24 * P2S_MIB) . '&g=1';
$r = p2s_cgi_run(
    ['memory_limit' => '16M', 'post_max_size' => '64M',
     'user_ini.filename' => '.user.ini', 'user_ini.cache_ttl' => '0'],
    ['-T', '2'],
    p2s_cgi_env("$dir/child.php", 'PUT', [
        'DOCUMENT_ROOT' => $dir,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'CONTENT_LENGTH' => (string)strlen($body),
    ]),
    $body);
$out = $r['out'];
echo "req1_deferred=", var_export(preg_match('/request_parse_body\(\): .*PHP2Shell mitigation: request data parse exceeded/', $out) === 1, true), "\n";
echo "req1_parse_returned=", var_export(strpos($out, 'REQ1 parse returned') !== false, true), "\n";
echo "req2: ", preg_match('/^REQ2 RAN .*$/m', $out, $m) ? $m[0] : 'did not run', "\n";
echo "deferred_warnings=", substr_count($out, 'request data parse exceeded'), "\n";
echo "deferred_fatals=", substr_count($out, 'mitigation deferred this'), "\n";
if (!$m) {
    p2s_dump($r);
}
@unlink("$dir/marker");
@unlink("$dir/.user.ini");
@unlink("$dir/child.php");
@rmdir($dir);
--EXPECT--
req1_deferred=true
req1_parse_returned=false
req2: REQ2 RAN memory_limit=-1
deferred_warnings=1
deferred_fatals=1
