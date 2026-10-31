--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): a request_parse_body() crossing of memory_limit (PHP 8.4+): deferred with deferred=request_parse_body and the trace kept under block; native but tagged (memory_error=memory_limit, phase=request_parse_body) with the trace kept under monitor; native and untagged under none
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80400);
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
post_max_size=32M
--FILE--
<?php
// As php2shell_mitigation_request_parse_body.phpt: a PUT body, which request
// startup leaves alone, parsed by request_parse_body() in the child
// (php2shell_child_rpb.php, run by php2shell_child_waf.php, whose shutdown
// function prints the root span's PHP2Shell tags). Mode auto, runtime forced
// to full; the decision is the testing override the process starts with.
// Calibrate the parse's peak with a generous limit, then run with
// memory_limit = 0.7 x peak.
require __DIR__ . '/inc/php2shell_cgi.php';
$body = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$put = p2s_cgi_env(P2S_WAF_CHILD, 'PUT', ['P2S_WAF_SCRIPT' => 'php2shell_child_rpb.php']);
$peak = p2s_calibrate([], $put + p2s_waf_env('off'), $body);
$soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
$soft = $soft_mib * P2S_MIB;

foreach (['block', 'monitor', 'none'] as $decision) {
    $r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], $put + p2s_waf_env('auto', $decision, 'full'), $body);
    $outcome = p2s_outcome($r, $soft);
    $continued = strpos($r['out'], 'SCRIPT RAN') !== false;
    $tags = p2s_waf_tags($r);
    echo "$decision: outcome=$outcome continued=", var_export($continued, true), " ",
        p2s_waf_tags_str($tags), "\n";
    if ($tags === null || $continued || !in_array($outcome, ['deferred', 'native'], true)) {
        p2s_dump($r, "peak=$peak soft=$soft");
    }
}
--EXPECT--
block: outcome=deferred continued=false span=true deferred=request_parse_body memory_error=- phase=- user_keep=true
monitor: outcome=native continued=false span=true deferred=- memory_error=memory_limit phase=request_parse_body user_keep=true
none: outcome=native continued=false span=true deferred=- memory_error=- phase=- user_keep=false
