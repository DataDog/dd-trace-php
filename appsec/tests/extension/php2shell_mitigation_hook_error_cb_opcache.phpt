--TEST--
PHP2Shell mitigation: with opcache enabled, the zend_error_cb hook stays on top (PHP 8.0's opcache wraps zend_error_cb in its post-startup callback)
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_opcache();
--INI--
extension=ddtrace.so
--FILE--
<?php
// On PHP 8.0, opcache's post-startup callback (accel_post_startup) puts its
// persistent_error_cb in zend_error_cb for good, after every zend_extension
// startup hook. Our wrapper must still be on top in every request, so MINFO
// shows it as Installed and no displacement is logged, with opcache enabled
// or not.
require __DIR__ . '/inc/php2shell_cgi.php';
$base = tempnam(sys_get_temp_dir(), 'p2s_error_cb');
$child = $base . '.php';
file_put_contents($child, "<?php require_once " . var_export(__DIR__ . '/inc/php2shell_cgi.php', true) . ";\n"
    . "echo 'ERROR_CB=', var_export(p2s_hook_row(p2s_minfo(), 'zend_error_cb'), true), \"\\n\";\n");
foreach (['0', '1'] as $enable) {
    $ini = p2s_opcache_ini() + [
        'datadog.appsec.log_file' => 'stderr',
        'datadog.appsec.log_level' => 'warning',
        'opcache.enable' => $enable,
        'opcache.enable_cli' => $enable,
    ];
    $r = p2s_cgi_run($ini, ['-T', '2'], p2s_cgi_env($child));
    preg_match_all('/^ERROR_CB=(.*?)\r?$/m', $r['out'], $rows);
    preg_match_all('/PHP2Shell mitigation: (\S+) was replaced after startup/', $r['out'] . $r['err'], $m);
    echo "opcache.enable=$enable: rows=", implode(',', $rows[1]),
        " displacement_log=", implode(',', $m[1]), "\n";
    if (count($rows[1]) !== 2 || $m[1]) {
        p2s_dump($r, "opcache.enable=$enable");
    }
}
@unlink($child);
@unlink($base);
--EXPECT--
opcache.enable=0: rows='Installed','Installed' displacement_log=
opcache.enable=1: rows='Installed','Installed' displacement_log=
