--TEST--
PHP2Shell mitigation (CVE-2026-109618): a deferred fatal from request_parse_body() (PHP 8.4+) inside the armed request returns to the armed request level, so a crossing in a shutdown function is deferred again
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80400);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// memory_limit=16M; the script builds a 6 MiB string, then
// request_parse_body() parses a PUT body with a 10 MiB value, which crosses
// the limit inside the window nested in the armed request: deferred at its
// end. A shutdown function then parses the string, with keys of the form
// kNNNNN<pad>[x] (see php2shell_mitigation_after_fatal_shutdown_parse_str.phpt),
// which crosses the limit again: deferred, and the array is complete.
require __DIR__ . '/inc/php2shell_cgi.php';
$r = p2s_cgi_run(['memory_limit' => '16M', 'post_max_size' => '64M', 'max_input_vars' => '1000000',
        'datadog.appsec.log_file' => 'stderr'], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_after_fatal.php', 'PUT', [
        'P2S_FATAL' => 'rpb', 'P2S_AFTER' => 'shutdown', 'P2S_SRC' => (string)(6 * P2S_MIB),
    ]), 'f=' . str_repeat('Z', 10 * P2S_MIB));
$outcome = p2s_outcome($r, 16 * P2S_MIB);
$rpb = strpos($r['err'], 'raising deferred out-of-memory error at a safe point (phase:request_parse_body)') !== false;
$raises = p2s_raises($r);
$native = p2s_native_oom($r);
$consistent = preg_match('/^CHECK count=(\d+) iter=(\d+) bad=0$/m', $r['err'], $m) && $m[1] === $m[2] && $m[1] > 0;
$ran = strpos($r['err'], 'SCRIPT RAN') !== false;
echo "outcome=$outcome rpb_raise=", var_export($rpb, true), " script_ran=", var_export($ran, true),
    " raises=$raises native=", var_export($native, true), " consistent=", var_export($consistent, true),
    " status={$r['status']}\n";
if ($outcome !== 'deferred' || !$rpb || $ran || $raises !== 2 || $native || !$consistent) {
    p2s_dump($r);
}
--EXPECT--
outcome=deferred rpb_raise=true script_ran=false raises=2 native=false consistent=true status=255
