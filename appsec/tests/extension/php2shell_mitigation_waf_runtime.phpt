--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): a runtime crossing of memory_limit under each mode and WAF decision: deferred with the deferred span tag and the trace kept when armed (pending, block, mode on); native under none, off and a patched runtime; native but tagged (memory_error, phase) with the trace kept under monitor
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_if_partial();
--INI--
extension=ddtrace.so
--FILE--
<?php
// One php-cgi child per row of the behaviour table. The decision is the
// testing override the process starts with (as if the WAF had answered it
// before this request); the runtime is forced to full (testing override),
// except in the patched row. The child allocates 56 MiB after RINIT with
// memory_limit=32M, then reaches a VM safe point
// (php2shell_child_runtime_alloc.php); its shutdown function prints the
// root span's PHP2Shell tags and whether the trace is kept
// (php2shell_child_waf.php).
//  - armed (pending, block, mode on whatever the decision): the allocation
//    lands between the soft and the hard limit, the error is raised at the
//    safe point (deferred), the span gets deferred=runtime and the trace is
//    kept (sampling priority 2).
//  - none, off, patched: nothing is armed; the engine raises its own error
//    inside the allocation (native); no tag, the trace is not force-kept.
//  - monitor: not armed either, so the native error, but it is seen as it is
//    raised: memory_error=memory_limit, phase=runtime, trace kept.
// armed is request_status() before the allocation.
require __DIR__ . '/inc/php2shell_cgi.php';
$rows = [
    // label, mode, decision, runtime
    ['off', 'off', null, 'full'],
    ['auto, patched', 'auto', null, 'patched'],
    ['auto, pending', 'auto', null, 'full'],
    ['auto, none', 'auto', 'none', 'full'],
    ['auto, monitor', 'auto', 'monitor', 'full'],
    ['auto, block', 'auto', 'block', 'full'],
    ['on, monitor ignored', 'on', 'monitor', 'full'],
];
foreach ($rows as list($label, $mode, $decision, $runtime)) {
    $env = p2s_cgi_env(P2S_WAF_CHILD, 'GET', [
        'P2S_ALLOC' => (string)(56 * P2S_MIB),
        'P2S_REQUEST_STATUS' => '1',
    ]) + p2s_waf_env($mode, $decision, $runtime);
    $r = p2s_cgi_run(['memory_limit' => '32M'], [], $env);
    $outcome = p2s_outcome($r, 32 * P2S_MIB);
    $armed = preg_match('/^REQUEST_STATUS armed=(\w+)/m', $r['out'], $m) ? $m[1] : '?';
    $tags = p2s_waf_tags($r);
    echo "$label: outcome=$outcome armed=$armed ", p2s_waf_tags_str($tags), "\n";
    if ($tags === null || $armed === '?' || !in_array($outcome, ['deferred', 'native'], true)) {
        p2s_dump($r);
    }
}
--EXPECT--
off: outcome=native armed=false span=true deferred=- memory_error=- phase=- user_keep=false
auto, patched: outcome=native armed=false span=true deferred=- memory_error=- phase=- user_keep=false
auto, pending: outcome=deferred armed=true span=true deferred=runtime memory_error=- phase=- user_keep=true
auto, none: outcome=native armed=false span=true deferred=- memory_error=- phase=- user_keep=false
auto, monitor: outcome=native armed=false span=true deferred=- memory_error=memory_limit phase=runtime user_keep=true
auto, block: outcome=deferred armed=true span=true deferred=runtime memory_error=- phase=- user_keep=true
on, monitor ignored: outcome=deferred armed=true span=true deferred=runtime memory_error=- phase=- user_keep=true
