--TEST--
PHP2Shell mitigation (CVE-2026-109618): a multipart body of a crossing field, then file parts and one more field, ends in the deferred fatal within the hard limit
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
file_uploads=1
post_max_size=16M
--FILE--
<?php
// A multipart body: one large field that crosses memory_limit, then
// max_file_uploads small file parts, then one more field. After the crossing
// the remaining fields are dropped and the file parts are skipped at
// FILE_START (see php2shell_mitigation_multipart_file_start_skip.phpt). The
// test checks that, for this shape, the request with 20 (the default) or 1000
// file parts ends in the deferred fatal, not 137. The hard limit has no
// per-file term: the skip keeps the parts after the crossing from
// registering. The dropped count only covers the two non-file fields. After
// the crossing, the read_post wrapper also ends the body reads, so the parts
// the parser has not buffered yet are not parsed at all, and the last field
// is never seen: with 20 file parts the crossing happens in the large field,
// which is dropped (1); with 1000, it happens in a file part (0).
require __DIR__ . '/inc/php2shell_cgi.php';
const BOUNDARY = 'p2sBOUNDARYfiles';
const NON_FILE_FIELDS = 2;

foreach ([20, 1000] as $files) {
    $parts = [['f', str_repeat('Z', 2000000)]];
    for ($i = 0; $i < $files; $i++) {
        $parts[] = [sprintf('u%04d', $i), str_repeat('d', 64), sprintf('u%04d.txt', $i)];
    }
    $parts[] = ['g', '1'];
    $body = p2s_multipart_body($parts, BOUNDARY);
    $env = p2s_multipart_env(BOUNDARY);
    $ini = ['max_file_uploads' => (string)$files];

    $cal = p2s_cgi_run(['memory_limit' => '256M'] + $ini, [], $env, $body);
    $lines = p2s_child_lines($cal);
    echo "files=$files calibration: post_fields=", $lines ? $lines[0]['post_fields'] : '-',
        " files=", $lines ? $lines[0]['files'] : '-', "\n";
    $peak = p2s_peak($cal);
    if ($peak === null) {
        p2s_dump($cal, 'calibration');
        continue;
    }
    $soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
    $soft = $soft_mib * P2S_MIB;
    $r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"] + $ini, [], $env, $body);
    $outcome = p2s_outcome($r, $soft);
    $n = p2s_dropped($r);
    echo "files=$files: outcome=$outcome dropped_le_non_file_fields=",
        var_export($n !== null && $n <= NON_FILE_FIELDS, true), "\n";
    if ($outcome !== 'deferred' || $n === null || $n > NON_FILE_FIELDS) {
        p2s_dump($r, "peak=$peak soft=$soft n=" . var_export($n, true));
    }
}
--EXPECT--
files=20 calibration: post_fields=2 files=20
files=20: outcome=deferred dropped_le_non_file_fields=true
files=1000 calibration: post_fields=2 files=1000
files=1000: outcome=deferred dropped_le_non_file_fields=true
