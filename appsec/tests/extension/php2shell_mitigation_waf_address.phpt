--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): request_init carries php.php2shell.vulnerable (full or partial) only under mode auto on an unpatched runtime; never under mode on or off, nor on a patched runtime
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_cgi();
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
// Each php-cgi child runs one request_init against the mock helper, which
// answers with no decision (or the one named), and prints the address it
// received and the MINFO decision row after the response. The runtime is the
// testing override (full, partial or patched), except in the last run, which
// uses the detection:
//  - auto, full or partial: the address carries that value; the decision row
//    stays pending (no answer yet).
//  - auto, patched: no address (the upstream fix is in); no hooks, nothing to
//    decide, but the mode is still auto, so the row reads pending.
//  - on: no address (the WAF is not asked); the row reads n/a, and a decision
//    in the response (not one a real helper sends without the address) is
//    ignored.
//  - off: no address; n/a.
//  - auto, detected: absent on a patched runtime, full or partial otherwise.
require __DIR__ . '/inc/php2shell_cgi.php';
$runs = [
    // mode, runtime, decision in the response ('': none)
    ['auto', 'full', ''],
    ['auto', 'partial', ''],
    ['auto', 'patched', ''],
    ['on', 'full', ''],
    ['on', 'full', 'none'],
    ['off', 'full', ''],
];
foreach ($runs as list($mode, $runtime, $answer)) {
    $env = p2s_cgi_env(P2S_WAF_HELPER_CHILD, 'GET', ['P2S_WAF_ANSWER' => $answer])
        + p2s_waf_env($mode, null, $runtime);
    $r = p2s_cgi_run([], [], $env);
    $label = "mode=$mode runtime=$runtime" . ($answer !== '' ? " answer=$answer" : '');
    if (preg_match('/^WAF ASKED address=(\S+) decision=(.*?)\r?$/m', $r['out'], $m)) {
        echo "$label: address=$m[1] decision=$m[2]\n";
    } else {
        p2s_dump($r, $label);
    }
}

$patched = p2s_minfo()[P2S_ROW_PATCHED] === 'Yes';
$r = p2s_cgi_run([], [], p2s_cgi_env(P2S_WAF_HELPER_CHILD) + p2s_waf_env('auto'));
if (preg_match('/^WAF ASKED address=(\S+)/m', $r['out'], $m)) {
    $ok = $patched ? $m[1] === 'absent' : in_array($m[1], ['full', 'partial'], true);
    echo "mode=auto runtime=detected: address_matches_patched_row=", var_export($ok, true), "\n";
    if (!$ok) {
        echo "patched=", var_export($patched, true), " address=$m[1]\n";
    }
} else {
    p2s_dump($r, 'mode=auto runtime=detected');
}
--EXPECT--
mode=auto runtime=full: address=full decision=pending
mode=auto runtime=partial: address=partial decision=pending
mode=auto runtime=patched: address=absent decision=pending
mode=on runtime=full: address=absent decision=n/a
mode=on runtime=full answer=none: address=absent decision=n/a
mode=off runtime=full: address=absent decision=n/a
mode=auto runtime=detected: address_matches_patched_row=true
