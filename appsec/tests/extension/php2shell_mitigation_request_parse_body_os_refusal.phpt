--TEST--
PHP2Shell mitigation (CVE-2026-109618): after another fatal inside an armed request_parse_body() (PHP 8.4+), shutdown code runs under a finite memory limit
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80400);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_unless_ext('posix');
if (!is_readable('/proc/self/status')) {
    die("skip: needs /proc/self/status (Linux)");
}
$lim = posix_getrlimit();
if (!isset($lim['hard totalmem']) || $lim['hard totalmem'] !== 'unlimited') {
    die("skip: needs an unlimited hard RLIMIT_AS");
}
--INI--
extension=ddtrace.so
memory_limit=512M
post_max_size=128M
--FILE--
<?php
// Another fatal than the deferred one can end an armed parse: here the
// operating system refuses an allocation (RLIMIT_AS, set by the child just
// before request_parse_body()), and zend_mm bails out with "Out of memory".
// The soft limit (memory_limit=8M) has already been crossed, so usage is
// above it and the engine cannot go back to the configured limit
// (zend_set_memory_limit() refuses a limit below the usage). The window's
// bailout path must still leave the sentinel for a finite limit, as the
// deferred-fatal path does: the shutdown function, which runs after the
// bailout, then gets "Allowed memory size ... exhausted" for a 64 MiB string
// instead of allocating it with no limit at all.
require __DIR__ . '/inc/php2shell_cgi.php';
$soft = 8 * P2S_MIB;
$body = 'f=' . str_repeat('Z', 64 * P2S_MIB);
$env = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_rpb_os_refusal.php', 'PUT', [
    'P2S_AS_HEADROOM' => (string)(40 * P2S_MIB),
    'P2S_SHUTDOWN_ALLOC' => (string)(64 * P2S_MIB),
]);
$r = p2s_cgi_run(['memory_limit' => '8M'], [], $env, $body);
$all = $r['out'] . $r['err'];

$os_refusal = (bool)preg_match('/^LAST ERROR Out of memory \(allocated \d+ bytes\)/m', $r['out']);
$parse_ended = strpos($r['out'], 'BEFORE PARSE') !== false && strpos($r['out'], 'SCRIPT RAN') === false;
echo "os_refusal_in_parse=", var_export($os_refusal && $parse_ended, true), "\n";
preg_match('/^SHUTDOWN usage=(\d+)/m', $r['out'], $u);
$usage = isset($u[1]) ? (int)$u[1] : 0;
echo "usage_above_soft=", var_export($usage > $soft, true), "\n";
$allocated = strpos($r['out'], 'SHUTDOWN ALLOCATED') !== false;
echo "shutdown_allocated=", var_export($allocated, true), "\n";
$limited = preg_match('/Allowed memory size of (\d+) bytes exhausted/', $all, $m) ? (int)$m[1] : null;
echo "shutdown_limit_finite=", var_export($limited !== null, true), "\n";
echo "shutdown_limit_above_usage=", var_export($limited !== null && $limited > $usage, true), "\n";
if (!$os_refusal || !$parse_ended || $allocated || $limited === null || $usage <= $soft) {
    p2s_dump($r, "usage=$usage limit=" . var_export($limited, true));
}
--EXPECT--
os_refusal_in_parse=true
usage_above_soft=true
shutdown_allocated=false
shutdown_limit_finite=true
shutdown_limit_above_usage=true
