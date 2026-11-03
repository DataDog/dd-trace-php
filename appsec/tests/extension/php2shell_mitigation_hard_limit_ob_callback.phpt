--TEST--
PHP2Shell mitigation (CVE-2026-109618): the hard-limit exit logs nothing, so a failing output callback cannot run and bail out of the allocation
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// _hard_abort runs inside an allocation, possibly in the middle of a hash
// insertion. It records the hard-exit metric before _exit(137). At debug log
// level with display_errors on, a log line there would be displayed, run the
// chunked output callback below and, as the callback fails, bail out of the
// half-done insertion (exit status 255, shutdown functions run). The metric is
// recorded without a log line, so the process ends with status 137.
require __DIR__ . '/inc/php2shell_cgi.php';
$child = sys_get_temp_dir() . '/php2shell_hard_limit_ob_callback_child.php';
file_put_contents($child, <<<'PHP'
<?php
ob_start(function ($buf) {
    file_put_contents("php://stderr", "OUTPUT CALLBACK RAN\n");
    trigger_error('bail from output callback', E_USER_ERROR);
}, 1);
register_shutdown_function(function () {
    file_put_contents("php://stderr", "SHUTDOWN FUNCTION RAN\n");
});
$s = str_repeat('Z', 6 * 1024 * 1024) . '=1';
$need = 15 * 1024 * 1024 - memory_get_usage(true);
$fill = $need > 0 ? str_repeat('f', $need) : '';
parse_str($s, $r);
echo "AFTER PARSE_STR ", count($r), strlen($fill), "\n";
PHP
);
$env = p2s_cgi_env($child) + p2s_waf_env('on');
$r = p2s_cgi_run(['memory_limit' => '16M', 'datadog.appsec.log_level' => 'debug'], [], $env);
$all = $r['out'] . $r['err'];
echo "status: {$r['status']}\n";
echo "hard-limit message: ",
    var_export(strpos($all, 'PHP2Shell mitigation: hard memory limit of') !== false, true), "\n";
foreach (['OUTPUT CALLBACK RAN', 'SHUTDOWN FUNCTION RAN', 'AFTER PARSE_STR'] as $s) {
    echo "$s: ", var_export(strpos($all, $s) !== false, true), "\n";
}
if ($r['status'] !== 137) {
    p2s_dump($r);
}
@unlink($child);
--EXPECT--
status: 137
hard-limit message: true
OUTPUT CALLBACK RAN: false
SHUTDOWN FUNCTION RAN: false
AFTER PARSE_STR: false
