--TEST--
PHP2Shell mitigation: opcache.preload's temporary NULL sapi_module.deactivate is not logged as a displaced hook
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70400);
if (PHP_OS_FAMILY === 'Windows') die('skip: opcache.preload is not supported on Windows');
p2s_skip_if_patched();
p2s_skip_unless_cgi();
if (!extension_loaded('Zend OPcache') && !is_file(ini_get('extension_dir') . '/opcache.so')) {
    die('skip: opcache.so is not available');
}
--INI--
extension=ddtrace.so
--FILE--
<?php
// With opcache.preload, opcache's post-startup hook
// (accel_finish_startup_preload) sets sapi_module.deactivate to NULL, runs the
// preload script in a request, and then restores the slot. That request's
// shutdown runs ddappsec's post_deactivate while the slot is NULL. It must
// not be logged as a displaced hook: the slot holds our wrapper again for
// every real request, so MINFO shows it as Installed and no warning is
// logged.
require __DIR__ . '/inc/php2shell_cgi.php';
$marker = tempnam(sys_get_temp_dir(), 'p2s_preload_marker');
@unlink($marker);
$preload = tempnam(sys_get_temp_dir(), 'p2s_preload') . '.php';
file_put_contents($preload,
    "<?php file_put_contents(" . var_export($marker, true) . ", 'ran');\n");

$ini = [
    'datadog.appsec.log_file' => 'stderr',
    'datadog.appsec.log_level' => 'warning',
    'opcache.enable' => '1',
    'opcache.enable_cli' => '1',
    'opcache.preload' => $preload,
];
if (!extension_loaded('Zend OPcache')) {
    $ini = ['zend_extension' => ini_get('extension_dir') . '/opcache.so'] + $ini;
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $ini['opcache.preload_user'] = 'root';
}

$r = p2s_cgi_run($ini, ['-T', '2'], p2s_cgi_env(P2S_CHILD_SCRIPT, 'GET', ['QUERY_STRING' => 'p2s_tag=abc']));
echo "preloaded=", is_file($marker) ? file_get_contents($marker) : 'no', "\n";
$lines = p2s_child_lines($r);
foreach ($lines as $i => $line) {
    preg_match('/(?:^|,)deactivate:([^,]*)/', $line['hooks'], $m);
    echo "request ", $i + 1, ": tag={$line['tag']} deactivate=", isset($m[1]) ? $m[1] : '-', "\n";
}
preg_match_all('/PHP2Shell mitigation: (\S+) was replaced after startup/', $r['out'] . $r['err'], $m);
echo "displacement_log=", implode(',', $m[1]), "\n";
if (count($lines) !== 2 || $m[1]) {
    p2s_dump($r, 'T2');
}
@unlink($marker);
@unlink($preload);
--EXPECT--
preloaded=ran
request 1: tag=abc deactivate=Installed
request 2: tag=abc deactivate=Installed
displacement_log=
