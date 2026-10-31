--TEST--
PHP2Shell mitigation (CVE-2026-109618): the hard limit is min(max(floor, memory_limit + k x post_max_size + margin), memory_limit + cap), with a larger k and cap for a multipart body
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
--INI--
extension=ddtrace.so
--FILE--
<?php
// status()['hard_limit'] is the hard limit a startup POST parse would get
// now. Each case runs a php-cgi child with its INI settings and compares the
// reported value with the formula, computed here:
//   H = min(max(L, S + k*B + M), S + C)
//   S = max(memory_limit, 2 MiB) (the soft limit is at least one zend_mm
//       chunk), B = post_max_size (0: unbounded, k*B saturates), M = 8 MiB,
//   L = 2S below 1 GiB, else S + 1 GiB (the floor),
//   C = max(1 GiB, 2S) (the cap), max(1 GiB, 4S) for a multipart body,
//   k = 6, or 11 for a multipart body. filter.default, max_file_uploads and a
//       displaced input_filter wrapper do not change it.
// The multipart cases send a multipart/form-data body of one small field.
// status() computes this value itself; that the startup and
// request_parse_body() windows arm with the multipart k is checked by its
// effect in php2shell_mitigation_multipart_file_part_name*.phpt.
// memory_limit=-1 means no hard limit (-1). S comes from the child's
// memory_limit in force: PHP 8.1+ rejects a memory_limit below the heap's
// usage at startup and keeps the default.
require __DIR__ . '/inc/php2shell_cgi.php';
const GIB = 1073741824;

function bytes($v) {
    $n = (int)$v;
    switch (strtoupper(substr($v, -1))) {
        case 'G': $n *= 1024;
        // fall through
        case 'M': $n *= 1024;
        // fall through
        case 'K': $n *= 1024;
    }
    return $n;
}

function want($s, $pms, $mp) {
    if ($s <= 0) {
        return -1;
    }
    $s = max($s, 2 * P2S_MIB);
    $b = bytes($pms);
    $k = $mp ? 11 : 6;
    $floor = $s < GIB ? 2 * $s : $s + GIB;
    $cap = $s + max(GIB, ($mp ? 4 : 2) * $s);
    $term = $b <= 0 ? PHP_INT_MAX : $s + $k * $b + 8 * P2S_MIB;
    return min(max($floor, $term), $cap);
}

const BOUNDARY = 'p2sF';
$mp = ['multipart' => true];
$cases = [
    // label => [memory_limit, post_max_size, max_file_uploads, extra INI, opts]
    'defaults (floor 2S)' => ['128M', '8M', 20, [], []],
    'S=1G (floor S+1G)' => ['1G', '8M', 20, [], []],
    'S=16M B=8M' => ['16M', '8M', 20, [], []],
    'S=1M (soft 2M)' => ['1M', '8M', 20, [], []],
    'filter.default=special_chars' => ['16M', '8M', 20, ['filter.default' => 'special_chars'], []],
    'filter.default=unsafe_raw' => ['16M', '8M', 20, ['filter.default' => 'unsafe_raw'], []],
    'max_file_uploads=1000' => ['16M', '8M', 1000, [], []],
    'B=2G (cap)' => ['16M', '2G', 20, [], []],
    'post_max_size=0 (cap)' => ['16M', '0', 20, [], []],
    'memory_limit=-1' => ['-1', '8M', 20, [], []],
    // p2s_stub.so's startup hook displaces our input_filter wrapper.
    'wrapper displaced' => ['16M', '8M', 20, ['p2s_stub.startup' => '1'], ['ext_after' => true]],
    'multipart defaults (floor 2S)' => ['128M', '8M', 20, [], $mp],
    'multipart S=16M B=8M (k=11)' => ['16M', '8M', 20, [], $mp],
    'multipart S=1G B=2G (cap S+4S)' => ['1G', '2G', 20, [], $mp],
    'multipart max_file_uploads=1000' => ['16M', '8M', 1000, [], $mp],
    'multipart filter.default=special_chars' => ['16M', '8M', 20, ['filter.default' => 'special_chars'], $mp],
];

foreach ($cases as $label => list($ml, $pms, $files, $ini, $opts)) {
    $multipart = !empty($opts['multipart']);
    $env = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_status.php', $multipart ? 'POST' : 'GET');
    $r = p2s_cgi_run($ini + ['memory_limit' => $ml, 'post_max_size' => $pms, 'max_file_uploads' => $files],
        [], $multipart ? p2s_multipart_env(BOUNDARY) + $env : $env,
        $multipart ? p2s_multipart_body([['a', '1']], BOUNDARY) : null, $opts);
    if (!preg_match('/STATUS soft=(-?\d+) hard=(-?\d+)/', $r['out'], $m)) {
        echo "$label: no status\n";
        p2s_dump($r);
        continue;
    }
    if ($multipart && strpos($r['out'], ' post_vars=1') === false) {
        echo "$label: multipart body not parsed\n";
        p2s_dump($r);
    }
    $hard = (int)$m[2];
    $want = want((int)$m[1], $pms, $multipart);
    echo "$label: formula_ok=", var_export($hard === $want, true), "\n";
    if ($hard !== $want) {
        echo "  hard=$hard want=$want\n";
    }
}
--EXPECT--
defaults (floor 2S): formula_ok=true
S=1G (floor S+1G): formula_ok=true
S=16M B=8M: formula_ok=true
S=1M (soft 2M): formula_ok=true
filter.default=special_chars: formula_ok=true
filter.default=unsafe_raw: formula_ok=true
max_file_uploads=1000: formula_ok=true
B=2G (cap): formula_ok=true
post_max_size=0 (cap): formula_ok=true
memory_limit=-1: formula_ok=true
wrapper displaced: formula_ok=true
multipart defaults (floor 2S): formula_ok=true
multipart S=16M B=8M (k=11): formula_ok=true
multipart S=1G B=2G (cap S+4S): formula_ok=true
multipart max_file_uploads=1000: formula_ok=true
multipart filter.default=special_chars: formula_ok=true
