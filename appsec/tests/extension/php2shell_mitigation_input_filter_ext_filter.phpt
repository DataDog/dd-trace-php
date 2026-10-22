--TEST--
PHP2Shell mitigation (CVE-2026-109618): the input_filter wrapper chains both of ext/filter's slots, leaves ext/filter's results unchanged, and drops variables while a deferred error is pending
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_ext('filter');
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
filter.default=special_chars
error_reporting=E_ALL & ~E_DEPRECATED
max_input_vars=10000000
post_max_size=64M
--FILE--
<?php
// ext/filter registers its own input_filter and input_filter_init in its
// MINIT, which runs before the hooks are captured. With
// filter.default=special_chars, the raw value is kept in ext/filter's storage
// (filter_input()) and the filtered one goes to the superglobal. The wrapper
// must forward both slots and return exactly what ext/filter returns, so the
// output is the same with the mitigation on and off.
require __DIR__ . '/inc/php2shell_cgi.php';
const FIELDS = 240000;
$cgi = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_filter_input.php', 'POST');

function lines($r) {
    preg_match_all('/^SCRIPT RAN .*$/m', $r['out'], $m);
    return $m[0] ? implode("\n", $m[0]) : "NO OUTPUT: {$r['out']}{$r['err']}";
}

function on_off($label, array $ini, array $args, array $env, $body) {
    $on = p2s_cgi_run($ini, $args, $env + ['DD_APPSEC_PHP2SHELL_MITIGATION' => 'on'], $body);
    $off = p2s_cgi_run($ini, $args, $env + ['DD_APPSEC_PHP2SHELL_MITIGATION' => 'off'], $body);
    echo "$label:\n", lines($on), "\n";
    echo "$label off_identical=", var_export(lines($on) === lines($off), true), "\n";
    if (lines($on) !== lines($off)) {
        echo "$label off:\n", lines($off), "\n";
    }
}

// 1. A POST body.
on_off('post', [], [], $cgi, 'a=<b>&c[]=1');
// 2. The same with filter.default=full_special_chars.
on_off('post_full_special_chars', ['filter.default' => 'full_special_chars'], [], $cgi, 'a=<b>&c[]=1');
// 3. Two GET requests in one process. The environment, and so the query
// string, is replayed for each, and input_filter_init runs for each.
on_off('get_T2', [], ['-T', '2'], ['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => 'a=%3Cb%3E&c[]=1'] + $cgi, null);

// 4. Many small fields. The calibration run (memory_limit=256M) drops
// nothing: its shutdown function checks that ext/filter's INPUT_POST storage
// holds every variable of $_POST, the last one included. Then memory_limit =
// 0.5 x the calibrated peak: the variables after the crossing are dropped
// before they reach ext/filter, and the deferred fatal follows the parse. That
// fatal ends request startup, so neither the script nor its shutdown function
// runs; section 5 checks the storage after a drop.
$body = '';
for ($i = 0; $i < FIELDS; $i++) {
    $body .= sprintf('f%07d=vvvvvvvv&', $i);
}
$env = [
    'SCRIPT_FILENAME' => __DIR__ . '/inc/php2shell_child_filter_count.php',
    'P2S_LAST_FIELD' => sprintf('f%07d', FIELDS - 1),
];
$cal = p2s_cgi_run(['memory_limit' => '256M'], [], $env, $body);
$peak = p2s_peak($cal);
echo "many calibration: ", preg_match('/post_fields=(\d+)/', $cal['out'], $m) ? "post_fields={$m[1]}" : 'none',
    " ", preg_match('/FILTER_POST (.*)/', $cal['err'], $mf) ? $mf[1] : "none: {$cal['err']}", "\n";
$soft_mib = (int)round(0.5 * $peak / P2S_MIB);
$soft = $soft_mib * P2S_MIB;
$r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], $env, $body);
$outcome = p2s_outcome($r, $soft);
$n = p2s_dropped($r);
$user_code = strpos($r['out'] . $r['err'], 'FILTER_POST') !== false;
echo "many: outcome=$outcome dropped_some=", var_export($n !== null && $n > 0 && $n < FIELDS, true),
    " user_code_ran=", var_export($user_code, true), "\n";
if ($outcome !== 'deferred' || $user_code || $n === null) {
    p2s_dump($r, "peak=$peak soft=$soft n=" . var_export($n, true));
}

// 5. PHP 8.4+: the same fields parsed by request_parse_body() from a PUT. The
// deferred fatal is raised when request_parse_body() returns, so the child's
// shutdown function runs and reports what ext/filter's INPUT_POST storage
// holds: the first variable, not the last one, and exactly M - N entries,
// where N is the count in the deferred warning. A wrapper that forwarded a
// variable and then dropped it would leave it in that storage.
if (PHP_VERSION_ID < 80400) {
    echo "rpb: not run before PHP 8.4\n";
} else {
    $put = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_rpb_filter.php', 'PUT',
        ['P2S_FIELDS' => (string)FIELDS]);
    $peak = p2s_calibrate([], $put, $body);
    $soft_mib = (int)round(0.5 * $peak / P2S_MIB);
    $soft = $soft_mib * P2S_MIB;
    $r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], $put, $body);
    $outcome = p2s_outcome($r, $soft);
    $n = p2s_dropped($r);
    $ok = preg_match('/FILTER_STORAGE first=(\w+) last=(\w+) count=(\d+)/', $r['err'], $ms);
    $count = $ok ? (int)$ms[3] : null;
    echo "rpb: outcome=$outcome first=", $ok ? $ms[1] : 'none', " last=", $ok ? $ms[2] : 'none',
        " count_plus_dropped_eq_total=", var_export($count !== null && $n !== null && $n > 0 && $count + $n === FIELDS, true), "\n";
    if ($outcome !== 'deferred' || !$ok || $n === null || $count + $n !== FIELDS) {
        p2s_dump($r, "peak=$peak soft=$soft n=" . var_export($n, true) . " count=" . var_export($count, true));
    }
}
--EXPECTF--
post:
SCRIPT RAN POST raw='<b>' filtered='&#60;b&#62;' c=["1"]
post off_identical=true
post_full_special_chars:
SCRIPT RAN POST raw='<b>' filtered='&lt;b&gt;' c=["1"]
post_full_special_chars off_identical=true
get_T2:
SCRIPT RAN GET raw='<b>' filtered='&#60;b&#62;' c=["1"]
SCRIPT RAN GET raw='<b>' filtered='&#60;b&#62;' c=["1"]
get_T2 off_identical=true
many calibration: post_fields=240000 consistent=true last=true
many: outcome=deferred dropped_some=true user_code_ran=false
%r(rpb: outcome=deferred first=true last=false count_plus_dropped_eq_total=true|rpb: not run before PHP 8\.4)%r
