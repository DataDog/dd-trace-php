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
// shutdown function). Mode auto, runtime forced to full; the decision is the
// testing override the process starts with.
require __DIR__ . '/inc/php2shell_cgi.php';
foreach (['monitor', 'none'] as $decision) {
    $env = p2s_cgi_env(P2S_WAF_CHILD) + p2s_waf_env('auto', $decision, 'full');
    $r = p2s_cgi_run(['memory_limit' => '32M', 'p2s_stub.rinit_alloc' => (string)(56 * P2S_MIB)],
        [], $env, null, ['ext_after' => true]);
    $bailed = preg_match('/^P2S_STUB RINIT alloc bailed=(\d)/m', $r['err'], $m) ? $m[1] : '?';
    $outcome = p2s_outcome($r, 32 * P2S_MIB);
    $tags = p2s_waf_tags($r);
    echo "$decision: outcome=$outcome bailed=$bailed ", p2s_waf_tags_str($tags), "\n";
    if ($tags === null || $bailed !== '1' || $outcome !== 'native') {
        p2s_dump($r);
    }
}
--EXPECT--
monitor: outcome=native bailed=1 span=true deferred=- memory_error=memory_limit phase=startup user_keep=true
none: outcome=native bailed=1 span=true deferred=- memory_error=- phase=- user_keep=false
