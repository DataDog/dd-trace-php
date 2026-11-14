--TEST--
PHP2Shell mitigation (CVE-2026-109618): a second deferred fatal at the request level prints the soft limit from memory_limit, in the fatal's message and in the log line, not the floor F the first raise left
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// As php2shell_mitigation_after_fatal_shutdown_parse_str.phpt: memory_limit=16M,
// a 10 MiB allocation raises the first deferred fatal, which leaves the soft
// limit at F (above 16M); the parse_str() in a shutdown function crosses F and
// raises the second. Both raises must print 16M (16777216 bytes). The
// fatals are read from the error log (log_errors=1, on stderr): PHP 7 does
// not display the one raised in a shutdown function after a fatal.
require __DIR__ . '/inc/php2shell_cgi.php';
$r = p2s_cgi_run(['memory_limit' => '16M', 'max_input_vars' => '1000000', 'log_errors' => '1',
        'datadog.appsec.log_file' => 'stderr'], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_after_fatal.php', 'GET', [
        'P2S_FATAL' => 'alloc', 'P2S_AFTER' => 'shutdown', 'P2S_ALLOC' => (string)(10 * P2S_MIB),
        'P2S_SRC' => (string)(6 * P2S_MIB),
    ]));
preg_match_all('/^PHP Fatal error:  Allowed memory size of (\d+) bytes exhausted \(Datadog AppSec/m', $r['err'], $msg);
preg_match_all('/exceeded memory_limit \((\d+) bytes\)/', $r['err'], $log);
echo "messages=", implode(',', $msg[1]), "\nlog=", implode(',', $log[1]), "\n";
echo "raises=", p2s_raises($r), " native=", var_export(p2s_native_oom($r), true), "\n";
if (count($msg[1]) !== 2 || count($log[1]) !== 2) {
    p2s_dump($r);
}
--EXPECT--
messages=16777216,16777216
log=16777216,16777216
raises=2 native=false
