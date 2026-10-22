--TEST--
PHP2Shell mitigation (CVE-2026-109618): the telemetry flush of a deferred fatal raised in request startup leaves nothing dangling for the next request of the process
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_if_no_zend_mm();
if (!function_exists('proc_open') || !function_exists('fsockopen'))
    die("skip: proc_open/fsockopen not available");
--INI--
post_max_size=32M
--FILE--
<?php
// The built-in web server (php -S) keeps serving after a request whose
// startup failed, so it can run, in one process, a request that ends before
// RINIT followed by normal ones.
//
// Request #1 is a urlencoded POST whose parse exceeds memory_limit: the
// mitigation raises its deferred out-of-memory error from the treat_data
// hook, before any module RINIT, and flushes its metric to the sidecar. With
// telemetry on, that flush first registers the telemetry application, which
// stores the service and env it sent. With no DD_SERVICE or DD_ENV, those are
// defaults allocated on the request heap, and only the datadog RSHUTDOWN or
// post_deactivate releases them. The request startup failed, so no RSHUTDOWN
// runs; post_deactivate must release them before the heap is reset, or the
// next request's RINIT frees them again (heap corruption, a crash or an ASAN
// report). Requests #2 and #3 must then be served normally.
require __DIR__ . '/inc/php2shell_cgi.php';

$big = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$ini = ['datadog.instrumentation_telemetry_enabled' => '1'];
$env = ['DD_APPSEC_PHP2SHELL_MITIGATION' => 'on', 'DD_SERVICE' => '', 'DD_ENV' => ''];

// Calibrate memory_limit from the peak of the parse in this build (see
// php2shell_mitigation_oom_cgi.phpt), so that request #1 lands in the deferred
// band.
$srv = p2s_server_start(['memory_limit' => '256M'] + $ini, $env);
$cal = p2s_child_lines(p2s_http($srv, $big));
p2s_server_stop($srv);
if (!$cal) {
    echo "calibration failed\n";
    exit;
}
$soft_mib = max(4, (int)round((int)$cal[0]['peak'] * 0.7 / P2S_MIB));

$srv = p2s_server_start(['memory_limit' => "{$soft_mib}M"] + $ini, $env);
$r1 = p2s_http($srv, $big);
$r2 = p2s_child_lines(p2s_http($srv, 'v=1'));
$r3 = p2s_child_lines(p2s_http($srv, 'v=1'));
$fin = p2s_server_stop($srv);

echo "req1_outcome=", p2s_outcome(['out' => $r1, 'err' => $fin['log'], 'status' => 0], $soft_mib * P2S_MIB), "\n";
foreach (['req2' => $r2, 'req3' => $r3] as $label => $lines) {
    echo "$label: ", $lines ? "post_fields={$lines[0]['post_fields']}" : 'no output', "\n";
}
echo "server_survived=", var_export($fin['was_running'], true), "\n";
if (!$r2 || !$r3 || !$fin['was_running'] || strpos($fin['log'], 'AddressSanitizer') !== false) {
    echo "server log: {$fin['log']}\n";
}
--EXPECT--
req1_outcome=deferred
req2: post_fields=1
req3: post_fields=1
server_survived=true
