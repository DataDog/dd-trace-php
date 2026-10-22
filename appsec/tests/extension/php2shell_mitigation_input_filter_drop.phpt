--TEST--
PHP2Shell mitigation (CVE-2026-109618): once the soft limit is pending, later request variables are dropped, so a many-fields body or query string ends in the deferred fatal instead of growing to the hard limit
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
max_input_vars=10000000
max_multipart_body_parts=-1
--FILE--
<?php
// A body of many small fields grows memory by a roughly constant amount per
// registered variable. Q_full is the peak of a full parse, measured with a
// generous memory_limit. With memory_limit a fraction of Q_full, the parse
// crosses it part way. Every variable after the crossing is dropped by the
// input_filter wrapper, so growth past memory_limit is about one variable,
// and the parse ends in the deferred fatal. The deferred-fatal warning
// reports the number of dropped variables, which must be positive and below
// the number of fields sent. Runs 1 (post_max_size=0, no input bound) and 2
// (post_max_size=64M) expect the same outcome.
//
// The GET case reads QUERY_STRING from the environment, which execve limits
// to 128 KiB per string, so its memory_limit is the real usage of a request
// without input: the parse crosses it once it needs a second chunk.
require __DIR__ . '/inc/php2shell_cgi.php';
const BODY_BYTES = 4 * P2S_MIB;
const QUERY_BYTES = 120000;
const BOUNDARY = 'p2sBOUNDARYxyz123';

// Fields "f%07d=vvvvvvvv" joined by '&', until $bytes. Built as a string:
// per-field arrays would exceed this process's memory_limit.
function body_urlencoded($bytes, &$count) {
    $buf = '';
    for ($i = 0; strlen($buf) < $bytes; $i++) {
        $buf .= ($i ? '&' : '') . sprintf('f%07d=vvvvvvvv', $i);
    }
    $count = $i;
    return $buf;
}

// The same fields as multipart parts (what p2s_multipart_body() builds).
function body_multipart(&$count) {
    $b = BOUNDARY;
    $buf = '';
    for ($i = 0; strlen($buf) < BODY_BYTES; $i++) {
        $buf .= sprintf("--%s\r\nContent-Disposition: form-data; name=\"f%07d\"\r\n\r\nvvvvvvvv\r\n", $b, $i);
    }
    $count = $i;
    return $buf . "--$b--\r\n";
}

function report($label, array $r, $soft, $count) {
    $outcome = p2s_outcome($r, $soft);
    $dropped = p2s_dropped($r);
    $ok = $dropped !== null && $dropped > 0 && $dropped < $count;
    echo "$label: outcome=$outcome dropped_some=", var_export($ok, true), "\n";
    if ($outcome !== 'deferred' || !$ok) {
        p2s_dump($r, "soft=$soft count=$count dropped=" . var_export($dropped, true));
    }
}

$cases = [
    'A urlencoded' => ['body' => body_urlencoded(BODY_BYTES, $count_a), 'count' => $count_a, 'env' => []],
    'B multipart' => ['body' => body_multipart($count_b), 'count' => $count_b,
        'env' => p2s_multipart_env(BOUNDARY)],
];
$runs = [
    1 => ['post_max_size' => '0', 'factor' => 0.4],
    2 => ['post_max_size' => '64M', 'factor' => 0.5],
];

foreach ($cases as $name => $case) {
    $q_full = p2s_calibrate(['post_max_size' => '64M'], $case['env'], $case['body']);
    foreach ($runs as $run => $cfg) {
        $soft_mib = max(4, (int)round($q_full * $cfg['factor'] / P2S_MIB));
        $soft = $soft_mib * P2S_MIB;
        $r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M",
            'post_max_size' => $cfg['post_max_size']], [], $case['env'], $case['body']);
        report("$name run $run", $r, $soft, $case['count']);
    }
}

$query = body_urlencoded(QUERY_BYTES, $count_get);
$full = p2s_cgi_run(['memory_limit' => '256M'], [], p2s_cgi_env(P2S_CHILD_SCRIPT, 'GET', ['QUERY_STRING' => $query]));
$lines = p2s_child_lines($full);
echo "C GET: full_count_ok=", var_export($lines && (int)$lines[0]['get_fields'] === $count_get, true), "\n";
$base = p2s_child_lines(p2s_cgi_run(['memory_limit' => '256M'], [], p2s_cgi_env()));
$soft = $base ? (int)$base[0]['peak'] : 0;
$r = p2s_cgi_run(['memory_limit' => (string)$soft], [], p2s_cgi_env(P2S_CHILD_SCRIPT, 'GET', ['QUERY_STRING' => $query]));
report('C GET', $r, $soft, $count_get);
--EXPECT--
A urlencoded run 1: outcome=deferred dropped_some=true
A urlencoded run 2: outcome=deferred dropped_some=true
B multipart run 1: outcome=deferred dropped_some=true
B multipart run 2: outcome=deferred dropped_some=true
C GET: full_count_ok=true
C GET: outcome=deferred dropped_some=true
