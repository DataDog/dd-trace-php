--TEST--
PHP2Shell mitigation (CVE-2026-109618): a soft-limit crossing in a multipart header section stops the body reads, and the request ends in the deferred fatal, not the hard limit
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
file_uploads=1
post_max_size=2M
--FILE--
<?php
// The unpatched multipart parser keeps every header line that contains ':'
// until the next part. A 2-byte ":\n" line costs about 300 bytes, so a header
// section of 900k such lines (a 1.8 MB body, within post_max_size) needs far
// more memory than the hard limit allows for a 2 MB body. memory_limit is
// calibrated a few MiB above the memory a request with a short header section
// uses, so the crossing happens in the long header section. After it, the
// sapi_module.read_post wrapper reports the end of the body, the parser only
// consumes what it has buffered, and the request ends in the deferred fatal
// (exit status 255) instead of growing to the hard limit and exiting with 137.
// php2shell_mitigation_multipart_header_lines_rpb.phpt does the same with
// request_parse_body().
require __DIR__ . '/inc/php2shell_cgi.php';
const BOUNDARY = 'p2sBOUNDARYhdrs';

function body($lines) {
    return '--' . BOUNDARY . "\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nx\r\n"
        . '--' . BOUNDARY . "\r\nContent-Disposition: form-data; name=\"h\"\r\n"
        . str_repeat(":\n", $lines) . "\r\nv\r\n--" . BOUNDARY . "--\r\n";
}

$env = p2s_multipart_env(BOUNDARY);
$cal = p2s_cgi_run(['memory_limit' => '256M'], [], $env, body(1000));
$lines = p2s_child_lines($cal);
echo "calibration: post_fields=", $lines ? $lines[0]['post_fields'] : '-', " read_post_installed=",
    var_export($lines && strpos($lines[0]['hooks'], 'read_post:Installed') !== false, true), "\n";
$peak = p2s_peak($cal);
if ($peak === null) {
    p2s_dump($cal, 'calibration');
    exit;
}
$soft_mib = (int)ceil($peak / P2S_MIB) + 4;
$soft = $soft_mib * P2S_MIB;
$body = body(900000);
$r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], $env, $body);
$outcome = p2s_outcome($r, $soft);
echo "body_within_post_max_size=", var_export(strlen($body) <= 2 * P2S_MIB, true), "\n";
echo "header lines: outcome=$outcome status={$r['status']}\n";
if ($outcome !== 'deferred') {
    p2s_dump($r, "peak=$peak soft=$soft");
}
--EXPECT--
calibration: post_fields=2 read_post_installed=true
body_within_post_max_size=true
header lines: outcome=deferred status=255
