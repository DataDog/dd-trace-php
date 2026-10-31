--TEST--
PHP2Shell mitigation (CVE-2026-109618): one large urlencoded or multipart field is deferred to a clean post-parse fatal (no mid-parse bailout), with memory_limit calibrated from the measured peak
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
post_max_size=32M
--FILE--
<?php
// The deferred-OOM path needs memory_limit < peak <= hard limit (at least 2 x
// memory_limit), where `peak` is the real memory the request-data parse of one
// large field needs. That peak depends on the build (debug/release, ZTS,
// loaded extensions, PHP version), so a fixed memory_limit/field-size pair is
// fragile. Instead, calibrate it here, like the integration test does: parse
// the field once with a generous limit and read the peak, then re-run with
// memory_limit = 0.7 x peak, which puts the peak in the middle of the
// deferred band (0.7P < P <= 1.4P). Case A is a urlencoded field name, case B
// a multipart field value.
require __DIR__ . '/inc/php2shell_cgi.php';
const BOUNDARY = 'p2sBOUNDARYoom';
$cases = [
    'A urlencoded' => ['f' . str_repeat('Z', 2000000) . '[k]=1', []],
    'B multipart' => [p2s_multipart_body([['f', str_repeat('Z', 2000000)]], BOUNDARY),
        p2s_multipart_env(BOUNDARY)],
];

foreach ($cases as $label => list($body, $env)) {
    $peak = p2s_calibrate([], $env, $body);
    $soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
    $soft = $soft_mib * P2S_MIB;
    echo "$label: peak_in_deferred_band=", var_export($peak > $soft && $peak <= 2 * $soft, true);
    $r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], $env, $body);
    $outcome = p2s_outcome($r, $soft);
    echo " outcome=$outcome\n";
    if ($outcome !== 'deferred') {
        p2s_dump($r, "peak=$peak soft=$soft");
    }
}
--EXPECT--
A urlencoded: peak_in_deferred_band=true outcome=deferred
B multipart: peak_in_deferred_band=true outcome=deferred
