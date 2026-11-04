--TEST--
PHP2Shell mitigation (CVE-2026-109618): after a deferred fatal the request stays armed, so a parse_str() in a shutdown function that crosses the limit again gets a second deferred fatal, with its array complete, not the engine's error in the middle of an insertion
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// memory_limit=16M; the main script allocates 10 MiB, which raises the first
// deferred fatal. A shutdown function then parses a string of 5-6 MiB with
// keys of the form kNNNNN<pad>[x] (the insertion path of the CVE). Unarmed,
// it would cross the limit left after the fatal inside the insertion; armed,
// the crossing is deferred to the end of the parse_str() call. An output
// callback then compares count() with what foreach visits.
require __DIR__ . '/inc/php2shell_cgi.php';
$child = __DIR__ . '/inc/php2shell_child_after_fatal.php';
$cases = [
    '3000-byte keys' => ['3000', 6],
    '2000-byte keys' => ['2000', 5],
];
foreach ($cases as $label => list($keylen, $src)) {
    $r = p2s_cgi_run(['memory_limit' => '16M', 'max_input_vars' => '1000000', 'datadog.appsec.log_file' => 'stderr'], [],
        p2s_cgi_env($child, 'GET', [
            'P2S_FATAL' => 'alloc', 'P2S_AFTER' => 'shutdown', 'P2S_ALLOC' => (string)(10 * P2S_MIB),
            'P2S_KEYLEN' => $keylen, 'P2S_SRC' => (string)($src * P2S_MIB),
        ]));
    $outcome = p2s_outcome($r, 16 * P2S_MIB);
    $raises = p2s_raises($r);
    $native = p2s_native_oom($r);
    $consistent = preg_match('/^CHECK count=(\d+) iter=(\d+) bad=0$/m', $r['err'], $m) && $m[1] === $m[2] && $m[1] > 0;
    echo "$label: outcome=$outcome raises=$raises native=", var_export($native, true),
        " consistent=", var_export($consistent, true), " status={$r['status']}\n";
    if ($outcome !== 'deferred' || $raises !== 2 || $native || !$consistent) {
        p2s_dump($r);
    }
}
--EXPECT--
3000-byte keys: outcome=deferred raises=2 native=false consistent=true status=255
2000-byte keys: outcome=deferred raises=2 native=false consistent=true status=255
