--TEST--
PHP2Shell mitigation (CVE-2026-109618): the hard limit accounts for post_max_size, so a single field larger than 2 x memory_limit ends in the deferred fatal, not in the hard-limit exit
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
memory_limit=512M
--FILE--
<?php
// The hard limit is max(floor, ml + k x B + M + F x P), where B is the input
// bound of the parse (post_max_size for the POST body). One field of
// N = 3 x ml bytes needs more than 2 x ml while it is parsed: its whole value
// is buffered before it can be registered. With hard = 2 x ml that would exit
// with status 137. With post_max_size = 4 x ml in the formula, the parse
// finishes and the deferred fatal is raised instead (goal E: no 137 for a
// body within post_max_size).
//
// memory_limit is derived from the baseline usage of a small request, so the
// soft limit is crossed by the field and not by the request setup.
//
// This process builds bodies of N bytes and briefly holds two N-byte strings
// while doing so; N grows with the calibrated baseline, so its memory_limit is
// set to 512M in --INI--. The children override it with -d memory_limit.
require __DIR__ . '/inc/php2shell_cgi.php';

function run_case($label, $ml_mib, $pms, $body, array $env = []) {
    $soft = $ml_mib * P2S_MIB;
    $r = p2s_cgi_run(['memory_limit' => "{$ml_mib}M", 'post_max_size' => $pms], [], $env, $body);
    $outcome = p2s_outcome($r, $soft);
    echo "$label: outcome=$outcome\n";
    if ($outcome !== 'deferred') {
        p2s_dump($r, "ml={$ml_mib}M post_max_size=$pms");
    }
}

$base = p2s_calibrate(['post_max_size' => '64M'], [], 'a=1');
$ml_mib = max(4, (int)ceil(2 * $base / P2S_MIB));
$n = 3 * $ml_mib * P2S_MIB;
$pms = (4 * $ml_mib) . 'M';

// A: urlencoded, one field whose name holds the N bytes.
run_case('A urlencoded', $ml_mib, $pms, 'f' . str_repeat('Z', $n) . '[k]=1');

// B: multipart, one non-file part of N bytes.
$boundary = 'p2sBoundary7MA4YWxkTrZu0gW';
run_case('B multipart', $ml_mib, $pms, p2s_multipart_body([['f', str_repeat('Z', $n)]], $boundary),
    p2s_multipart_env($boundary));

// C: the reported shape, one 6 MiB field with memory_limit = 0.4 x its peak.
$body_c = 'f' . str_repeat('Z', 6 * P2S_MIB) . '[k]=1';
$q = p2s_calibrate(['post_max_size' => '64M'], [], $body_c);
run_case('C reported', max(4, (int)round(0.4 * $q / P2S_MIB)), '16M', $body_c);

$parent_limit = (int)ini_get('memory_limit') * P2S_MIB;
$parent_peak = memory_get_peak_usage();
echo "parent peak under half its memory_limit: ",
    var_export($parent_peak < $parent_limit / 2, true), "\n";
if ($parent_peak >= $parent_limit / 2) {
    echo "peak=$parent_peak limit=$parent_limit n=$n\n";
}
--EXPECT--
A urlencoded: outcome=deferred
B multipart: outcome=deferred
C reported: outcome=deferred
parent peak under half its memory_limit: true
