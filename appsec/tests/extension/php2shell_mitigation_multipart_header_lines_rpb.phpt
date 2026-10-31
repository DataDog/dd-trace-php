--TEST--
PHP2Shell mitigation (CVE-2026-109618): a soft-limit crossing in a multipart header section parsed by request_parse_body() (PHP 8.4+) stops the body reads, and the script ends in the deferred fatal, not the hard limit
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80400);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
file_uploads=1
post_max_size=2M
--FILE--
<?php
// As php2shell_mitigation_multipart_header_lines.phpt, but the body is sent
// with PUT, so request startup leaves it alone and the child parses it with
// request_parse_body(), which reads the multipart body inside its own window.
// The deferred fatal ends the script: nothing after request_parse_body() runs.
require __DIR__ . '/inc/php2shell_cgi.php';
const BOUNDARY = 'p2sBOUNDARYhdrs';

function body($lines) {
    return '--' . BOUNDARY . "\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nx\r\n"
        . '--' . BOUNDARY . "\r\nContent-Disposition: form-data; name=\"h\"\r\n"
        . str_repeat(":\n", $lines) . "\r\nv\r\n--" . BOUNDARY . "--\r\n";
}

$put = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_rpb.php', 'PUT', p2s_multipart_env(BOUNDARY));
$cal = p2s_cgi_run(['memory_limit' => '256M'], [], $put, body(1000));
echo "calibration: ", preg_match('/^SCRIPT RAN post_fields=(\d+)/m', $cal['out'], $m)
    ? "post_fields={$m[1]}" : 'failed', "\n";
$peak = p2s_peak($cal);
if ($peak === null) {
    p2s_dump($cal, 'calibration');
    exit;
}
$soft_mib = (int)ceil($peak / P2S_MIB) + 4;
$soft = $soft_mib * P2S_MIB;
$body = body(900000);
$r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], $put, $body);
$outcome = p2s_outcome($r, $soft);
echo "body_within_post_max_size=", var_export(strlen($body) <= 2 * P2S_MIB, true), "\n";
echo "startup_left_body=", var_export(strpos($r['out'], 'BEFORE PARSE post_fields=0') !== false, true), "\n";
echo "header lines: outcome=$outcome status={$r['status']} continued=",
    var_export(strpos($r['out'], 'SCRIPT RAN') !== false, true), "\n";
if ($outcome !== 'deferred') {
    p2s_dump($r, "peak=$peak soft=$soft");
}
--EXPECT--
calibration: post_fields=2
body_within_post_max_size=true
startup_left_body=true
header lines: outcome=deferred status=255 continued=false
