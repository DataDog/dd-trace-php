--TEST--
PHP2Shell mitigation (CVE-2026-109618): AppSec's own RINIT is armed: a memory_limit crossing in it, while it processes a large request_init reply, is deferred to the script's first VM interrupt
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// One php-cgi request whose appsec RINIT exchanges with the mock helper
// (MOCK_HELPER_FD, see p2s_cgi_run). The request_init reply carries many
// triggers, so its processing needs a new Zend MM chunk, and keeps the trace,
// so the tracer also adds _dd.p.ts and _dd.p.dm to the root span (char*-key
// insertions). p2s_stub.so, loaded before ddtrace.so, brings real usage to
// 1 MiB under memory_limit=32M in its RINIT, before the ddtrace RINIT (the
// tracer's part, then AppSec's): the first new chunk crosses memory_limit
// inside AppSec's RINIT, most likely while the reply is decoded; the test only
// shows that the crossing happens somewhere in AppSec's RINIT and is deferred.
// Armed from the start of that RINIT (the first request of the process:
// decision pending), the crossing is deferred and raised at the script's first
// VM interrupt (its first function call, before it prints BASE). Unarmed, it
// would bail out where it happens (unpatched) or end the process with _exit(1)
// (partial runtime). If the tracer's part of the ddtrace RINIT, between the
// stub's RINIT and AppSec's, ever needed a new chunk, the crossing would happen
// there, unarmed, and this test would fail with no regression in appsec.
require __DIR__ . '/inc/php2shell_cgi.php';
require_once __DIR__ . '/inc/mock_helper.php';
const SOFT = 33554432;

$helper = p2s_mock_helper_start([
    response_list(response_request_init([[['record', []]], array_fill(0, 20000, '{}'), true])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
]);
$r = p2s_cgi_run(['memory_limit' => '32M', 'datadog.appsec.enabled' => '1',
        'p2s_stub.rinit_pad' => (string)(SOFT - P2S_MIB)], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_runtime_alloc.php') + p2s_waf_env('auto'),
    null, ['ext_before' => true, 'helper' => $helper]);
$cmds = p2s_mock_helper_stop($helper);
// The goodbye of GSHUTDOWN is not waited for.
echo "outcome=", p2s_outcome($r, SOFT), " base=", substr_count($r['out'], 'BASE usage='),
    " status=", $r['status'], " commands=", implode(',', array_slice($cmds, 0, 3)), "\n";
if (p2s_outcome($r, SOFT) !== 'deferred') {
    p2s_dump($r);
}
--EXPECT--
outcome=deferred base=0 status=255 commands=client_init,request_init,request_shutdown
