--TEST--
PHP2Shell mitigation (CVE-2026-109618): after a request whose startup ended before RINIT (deferred out-of-memory error), the next request on the same process resolves appsec's enabled state from a live runtime config
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_if_no_zend_mm();
if (!function_exists('proc_open') || !function_exists('fsockopen'))
    die("skip: proc_open/fsockopen not available");
--INI--
extension=ddtrace.so
ddtrace.disable=1
post_max_size=32M
--FILE--
<?php
// The built-in web server (php -S) keeps serving after a request whose
// startup failed, so it can run, in one process, a request that ends before
// RINIT followed by a normal one.
//
// ddtrace.disable=1 keeps ddtrace from resolving appsec's enabled state during
// its first activation (dd_appsec_maybe_enable_helper()), so the first appsec
// RINIT of the process is the one that resolves it.
//
// Request #1 is a urlencoded POST whose parse exceeds memory_limit: the
// mitigation raises its deferred out-of-memory error from the treat_data hook,
// so request startup fails before any module RINIT. The config was already
// pulled for that request (dd_appsec_config_first_rinit_once()), and
// post_deactivate released that runtime config.
//
// Request #2 is the first request that reaches appsec's RINIT, so it runs the
// process-wide first-RINIT work, which resolves DDAPPSEC_G(enabled). The
// runtime config it reads must belong to request #2, not be the one request
// #1 released. Its body is a field of NUL bytes, large enough for the memory
// manager to hand request #1's runtime config pages to request #2's own data,
// so that reading the released array would not return the configured values.
// This is a weak detector: the freed config is in zend_mm, which ASAN does not
// see, so it needs that page reuse to fail.
require __DIR__ . '/inc/php2shell_cgi.php';

$big = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$nul = 'v=' . str_repeat('%00', 65536);

// Calibrate memory_limit from the peak of the parse in this build (see
// php2shell_mitigation_oom_cgi.phpt), so that request #1 lands in the deferred
// band (memory_limit < peak <= 2 x memory_limit, at most the hard limit).
$srv = p2s_server_start(['memory_limit' => '256M']);
$cal = p2s_child_lines(p2s_http($srv, $big));
p2s_server_stop($srv);
if (!$cal) {
    echo "calibration failed\n";
    exit;
}
$soft_mib = max(4, (int)round((int)$cal[0]['peak'] * 0.7 / P2S_MIB));
// Control: on the first request of a process the mitigation is active (appsec
// enabled via remote config, the default).
echo "control_active={$cal[0]['active']}\n";

// report_memleaks=0: php -S (7.x at least) never shuts down a request whose
// startup failed, so request #2's shutdown reports request #1's allocations as
// leaks, and the leak checker of PHP 7.0-7.3.16 debug builds can loop forever
// over them (fixed upstream in 45b4368d5c; the CI images patch it).
$srv = p2s_server_start(['memory_limit' => "{$soft_mib}M", 'report_memleaks' => '0']);
$r1 = p2s_http($srv, $big);
$r2 = p2s_child_lines(p2s_http($srv, $nul));
// DDAPPSEC_G(enabled) is resolved once per process (per thread on ZTS), so a
// wrong resolution in request #2 would persist into later requests.
$r3 = p2s_child_lines(p2s_http($srv, 'v=1'));
$fin = p2s_server_stop($srv);

echo "req1_outcome=", p2s_outcome(['out' => $r1, 'err' => $fin['log'], 'status' => 0], $soft_mib * P2S_MIB), "\n";
foreach (['req2' => $r2, 'req3' => $r3] as $label => $lines) {
    echo "$label: ", $lines ? "active={$lines[0]['active']} post_fields={$lines[0]['post_fields']}" : 'no output', "\n";
}
echo "server_survived=", var_export($fin['was_running'], true), "\n";
if (!$r2 || !$r3 || !$fin['was_running']) {
    echo "server log: {$fin['log']}\n";
}
--EXPECT--
control_active=true
req1_outcome=deferred
req2: active=true post_fields=1
req3: active=true post_fields=1
server_survived=true
