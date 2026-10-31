--TEST--
PHP2Shell mitigation (CVE-2026-109618): the hard limit of request_parse_body() (PHP 8.4+) accounts for its post_max_size option, or else the INI value, and for a POST body read at request startup
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80400);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// As in php2shell_mitigation_hard_limit_post_max_size.phpt (case A), one field
// of N = 3 x memory_limit bytes, but sent with PUT and parsed by
// request_parse_body(). The input bound of that parse is the post_max_size the
// call applies: the INI value by default (run 1), or its post_max_size option
// (runs 2 and 3, read once before the call; the INI value of 1M alone would
// reject the body). Option keys are case-insensitive.
//
require __DIR__ . '/inc/php2shell_cgi.php';
$put = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_rpb.php', 'PUT');

$base = p2s_calibrate(['post_max_size' => '64M'], $put, 'a=1');
$ml_mib = max(4, (int)ceil(2 * $base / P2S_MIB));
$soft = $ml_mib * P2S_MIB;
$body = 'f' . str_repeat('Z', 3 * $soft) . '[k]=1';

function run_case($label, $pms_ini, array $env) {
    global $put, $ml_mib, $soft, $body;
    $r = p2s_cgi_run(['memory_limit' => "{$ml_mib}M", 'post_max_size' => $pms_ini],
        [], $env + $put, $body);
    $outcome = p2s_outcome($r, $soft);
    echo "$label: outcome=$outcome\n";
    if ($outcome !== 'deferred') {
        p2s_dump($r, "ml={$ml_mib}M");
    }
}

run_case('1 INI', (4 * $ml_mib) . 'M', []);
run_case('2 option string', '1M', ['P2S_RPB_POST_MAX_SIZE' => (4 * $ml_mib) . 'M']);
run_case('3 option int', '1M', [
    'P2S_RPB_POST_MAX_SIZE' => (string)(4 * $soft),
    'P2S_RPB_KEY' => 'Post_Max_Size',
]);

// Run 4: a POST body already read at request startup. sapi_activate() reads a
// urlencoded POST body under the INI post_max_size, and variables_order
// without P keeps the startup parse from consuming it. A post_max_size option
// lower than the body makes the original's reader return early (with a
// warning) without replacing the body read at startup, and the POST handler
// then parses that whole body. The input bound is therefore the bytes already
// read, as for the startup parse. The calibration run checks that the startup
// parse left $_POST empty and that request_parse_body() parsed the field
// anyway. The precondition line checks that the peak is above H for
// B = the option and below H for B = the body length (k = 6 for this
// urlencoded body).
$post = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_rpb.php', 'POST');
$rini = ['post_max_size' => (4 * $ml_mib) . 'M', 'variables_order' => 'EGCS'];
$renv = ['P2S_RPB_POST_MAX_SIZE' => '64K'] + $post;
$cal = p2s_cgi_run(['memory_limit' => '1G'] + $rini, [], $renv, $body);
$peak = p2s_peak($cal);
if ($peak === null || strpos($cal['out'], 'BEFORE PARSE post_fields=0 ') === false
        || strpos($cal['out'], 'SCRIPT RAN post_fields=1 ') === false) {
    echo "read body calibration failed\n";
    p2s_dump($cal);
} else {
    $h_opt = max(2 * $soft, $soft + 6 * 65536 + 8 * P2S_MIB);
    $h_read = $soft + 6 * strlen($body) + 8 * P2S_MIB;
    echo "read body precondition: above_h_option=", var_export($peak > $h_opt, true),
        " below_h_read=", var_export($peak < $h_read, true), "\n";
    $r = p2s_cgi_run(['memory_limit' => "{$ml_mib}M"] + $rini, [], $renv, $body);
    $outcome = p2s_outcome($r, $soft);
    echo "4 body read at startup: outcome=$outcome\n";
    if ($outcome !== 'deferred') {
        p2s_dump($r, "ml={$ml_mib}M peak=$peak");
    }
}
--EXPECT--
1 INI: outcome=deferred
2 option string: outcome=deferred
3 option int: outcome=deferred
read body precondition: above_h_option=true below_h_read=true
4 body read at startup: outcome=deferred
