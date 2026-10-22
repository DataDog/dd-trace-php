--TEST--
PHP2Shell mitigation (CVE-2026-109618): file parts after the soft-limit crossing are skipped at FILE_START, so the rfc1867 callback captured at startup never sees them
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
file_uploads=1
--FILE--
<?php
// p2s_stub.so, loaded after ddappsec with p2s_stub.minit=1, registers in its
// MINIT an input_filter and a php_rfc1867_callback; ddappsec captures both at
// startup and chains them. At MSHUTDOWN (which php-cgi runs even after a failed
// request startup) the stub prints how many request variables its filter
// received and how many MULTIPART_EVENT_FILE_START events its callback
// received. Once the deferred error is pending, ddappsec's input_filter drops
// every later variable and its rfc1867 callback returns FAILURE at FILE_START,
// neither forwarding to the stub.
//
// After a crossing in a multipart parse, the read_post wrapper reports the end
// of the body, so only the data already in the parser's buffer (FILLUNIT,
// 5 KiB) is parsed. To be reached at all, the file parts after the crossing
// must lie within that buffer:
//  1. calibration: N small fields (N x VALUE_LEN >= memory_limit, so they
//     cross it), no file part. The stub's filter count C is the number of
//     fields registered before the error became pending: field C + 1 was
//     dropped.
//  2. the first C + MARGIN fields, then FILE_PARTS: two with an empty filename
//     (which do not count against max_file_uploads) and two with a file. The
//     parse up to field C + 1 is the one of step 1, so the error is pending
//     before the file parts, which follow field C by less than 5 KiB.
//     With memory_limit=256M (no crossing) the stub sees every file part.
//     At the calibrated memory_limit it sees none, some of the fields before
//     the file parts are dropped, and the request ends in the deferred fatal
//     (255). Without the FILE_START skip, the stub sees the file parts.
require __DIR__ . '/inc/php2shell_cgi.php';
const VALUE_LEN = 200;
const MARGIN = 4;
const FILE_UPLOADS = 20;
const N_FILE_PARTS = 4;
const FILE_PARTS =
    "--b\r\nContent-Disposition: form-data; filename=\"\"\r\n\r\n\r\n"
    . "--b\r\nContent-Disposition: form-data; name=\"e[]\"; filename=\"\"\r\n\r\n\r\n"
    . "--b\r\nContent-Disposition: form-data; name=\"u1\"; filename=\"a.txt\"\r\n"
    . "Content-Type: text/plain\r\n\r\nhello\r\n"
    . "--b\r\nContent-Disposition: form-data; name=\"u2\"; filename=\"b.txt\"\r\n"
    . "Content-Type: text/plain\r\n\r\nworld\r\n";
const TAIL = "--b--\r\n";

function fields($n) {
    $value = str_repeat('Z', VALUE_LEN);
    $buf = '';
    for ($i = 0; $i < $n; $i++) {
        $buf .= "--b\r\nContent-Disposition: form-data; name=\"v$i\"\r\n\r\n$value\r\n";
    }
    return $buf;
}

// Run the child with the stub and parse its P2S_STUB line into 'filter' and
// 'file_start' (null without it).
function run_stub($body, $memory_limit, $n_fields) {
    $r = p2s_cgi_run([
        'p2s_stub.minit' => '1',
        'memory_limit' => $memory_limit,
        'post_max_size' => (string)strlen($body),
        'max_input_vars' => (string)($n_fields + 10),
        'max_file_uploads' => (string)FILE_UPLOADS,
        'max_multipart_body_parts' => (string)($n_fields + N_FILE_PARTS + 10),
    ], [], p2s_multipart_env('b'), $body, ['ext_after' => true]);
    $ok = preg_match('/P2S_STUB filter=(\d+) .* file_start=(\d+)/', $r['err'], $m);
    $r['filter'] = $ok ? (int)$m[1] : null;
    $r['file_start'] = $ok ? (int)$m[2] : null;
    return $r;
}

$base = run_stub(fields(1) . TAIL, '256M', 1);
$peak = p2s_peak($base);
if ($peak === null) {
    p2s_dump($base, 'base');
    exit;
}
$soft_mib = max(4, (int)ceil($peak / P2S_MIB) + 2);
$soft = $soft_mib * P2S_MIB;
$n = (int)ceil($soft / VALUE_LEN) + MARGIN;

$cal = run_stub(fields($n) . TAIL, "{$soft_mib}M", $n);
$c = $cal['filter'];
$outcome = p2s_outcome($cal, $soft);
echo "calibration: outcome=$outcome crossed_in_fields=",
    var_export($c !== null && $c + MARGIN < $n, true), "\n";
if ($outcome !== 'deferred' || $c === null || $c + MARGIN >= $n) {
    p2s_dump($cal, "peak=$peak soft=$soft n=$n c=" . var_export($c, true));
    exit;
}

$body = fields($c + MARGIN) . FILE_PARTS . TAIL;
$ctl = run_stub($body, '256M', $c + MARGIN);
echo "no crossing: outcome=", p2s_outcome($ctl), " file_start=", var_export($ctl['file_start'], true), "\n";

$r = run_stub($body, "{$soft_mib}M", $c + MARGIN);
$outcome = p2s_outcome($r, $soft);
echo "crossing: outcome=$outcome status={$r['status']} file_start=", var_export($r['file_start'], true),
    " fields_dropped_before_file_parts=", var_export($r['filter'] !== null && $r['filter'] < $c + MARGIN, true), "\n";
if ($outcome !== 'deferred' || $r['file_start'] !== 0) {
    p2s_dump($r, "peak=$peak soft=$soft c=$c filter=" . var_export($r['filter'], true));
}
--EXPECT--
calibration: outcome=deferred crossed_in_fields=true
no crossing: outcome=ran file_start=4
crossing: outcome=deferred status=255 file_start=0 fields_dropped_before_file_parts=true
