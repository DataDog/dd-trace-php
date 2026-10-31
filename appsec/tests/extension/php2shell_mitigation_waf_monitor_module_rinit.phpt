--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): under the WAF decision monitor, a native memory_limit error raised in a module RINIT that runs after ddtrace's (phase startup, the root span exists) is tagged memory_error=memory_limit, phase=startup and the trace is kept; not under none
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
// An 8.6 pre-release counts as patched (see p2s_policy.c) without the fix.
if (PHP_VERSION_ID >= 80600 && PHP_EXTRA_VERSION !== '') {
    die("skip: PHP 8.6 pre-release, treated as patched without the fix");
}
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// p2s_stub.so, loaded after ddappsec (so after ddtrace) with
// p2s_stub.rinit_alloc, allocates 56 MiB in its RINIT with memory_limit=32M:
// after ddtrace's RINIT created the root span, but while PG(modules_activated)
// is still 0, so the error's phase is startup. Under monitor nothing is armed,
// so the engine raises its own error, inside the allocation; the monitor
// detection reports it on the root span whatever the phase. The stub catches
// the bailout, so that the request goes on and php2shell_child_waf.php can
// print the root span's PHP2Shell tags and whether the trace is kept (its
// shutdown function). Mode auto, detected runtime; the decision is the testing
// override the process starts with.
//
// What the native error does depends on the runtime (behaviour table):
//  - full: as above.
//  - partial (monitor row, W-B; none row, native fatal): the engine's error
//    ends the process with _exit(1) inside the allocation, before any hook or
//    shutdown function runs: nothing is reported, the trace is lost.
//  - patched (row "auto, patched runtime"): the mitigation is inactive and the
//    upstream fix delays the error, which ends the request before the script
//    runs: nothing is reported either.
require __DIR__ . '/inc/php2shell_cgi.php';
$kind = p2s_runtime_kind();
echo "runtime=$kind\n";
$want = [
    'full' => [
        'monitor' => 'outcome=native bailed=1 span=true deferred=- memory_error=memory_limit phase=startup user_keep=true',
        'none' => 'outcome=native bailed=1 span=true deferred=- memory_error=- phase=- user_keep=false',
    ],
    'partial' => [
        'monitor' => 'outcome=native status=1 no tags line',
        'none' => 'outcome=native status=1 no tags line',
    ],
    'patched' => [
        'monitor' => 'outcome=native no tags line',
        'none' => 'outcome=native no tags line',
    ],
];
foreach (['monitor', 'none'] as $decision) {
    $env = p2s_cgi_env(P2S_WAF_CHILD) + p2s_waf_env('auto', $decision);
    $r = p2s_cgi_run(['memory_limit' => '32M', 'p2s_stub.rinit_alloc' => (string)(56 * P2S_MIB)],
        [], $env, null, ['ext_after' => true]);
    $bailed = preg_match('/^P2S_STUB RINIT alloc bailed=(\d)/m', $r['err'], $m) ? $m[1] : '?';
    $got = 'outcome=' . p2s_outcome($r, 32 * P2S_MIB)
        . ($kind === 'full' ? " bailed=$bailed" : '')
        . ($kind === 'partial' ? " status={$r['status']}" : '')
        . ' ' . p2s_waf_tags_str(p2s_waf_tags($r));
    echo "$decision: ", $got === $want[$kind][$decision] ? 'as specified' : "got [$got]", "\n";
    if ($got !== $want[$kind][$decision]) {
        p2s_dump($r);
    }
}
--EXPECTF--
runtime=%s
monitor: as specified
none: as specified
