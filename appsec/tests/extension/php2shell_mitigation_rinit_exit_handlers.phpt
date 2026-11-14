--TEST--
PHP2Shell mitigation (CVE-2026-109618): a block or a fatal error in AppSec's armed RINIT takes AppSec's Zend MM handlers off first, so the handlers an earlier module installed in its RINIT are on top again when it removes them
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
// p2s_stub.so, loaded before ddtrace.so, stands in for the allocation
// profiler (p2s_stub.rinit_mm): it installs Zend MM handlers in its RINIT,
// below AppSec's, and the first of its RSHUTDOWN, zend_extension deactivate
// hook and MSHUTDOWN removes them if they are on top, else gives them up (as
// the profiler does, with a warning). The profiler's own part of the ddtrace
// RINIT also runs before AppSec's and is not in the xtest build. One php-cgi
// request per case; php-cgi exits after a failed request startup, without
// RSHUTDOWN or post_deactivate.
// 1. block: appsec blocks in its RINIT (datadog.appsec.testing_abort_rinit).
//    The block path runs the RSHUTDOWN of the other parts of the ddtrace
//    module and of the modules loaded before ddtrace.so itself, the stub's
//    included, then bails out.
// 2. fatal: AppSec's RINIT exchanges with the mock helper (MOCK_HELPER_FD);
//    with real usage 1 MiB under memory_limit (p2s_stub.rinit_pad), the
//    AppSec JSON body parser refuses to allocate and the request fails with a
//    fatal error, not a block. Nothing runs before MSHUTDOWN, where AppSec's,
//    first in the ddtrace MSHUTDOWN, resets the heap if its handlers are
//    still on top.
require __DIR__ . '/inc/php2shell_cgi.php';
require_once __DIR__ . '/inc/mock_helper.php';
const SOFT = 33554432;
$ini = ['memory_limit' => '32M', 'datadog.appsec.enabled' => '1', 'p2s_stub.rinit_mm' => '1'];

function report($label, array $r) {
    preg_match_all('/^P2S_STUB (\w+) mm (\w+)$/m', $r['err'], $m, PREG_SET_ORDER);
    echo "$label: status={$r['status']} ran=", substr_count($r['out'], 'SCRIPT RAN'),
        " stub=", implode(',', array_map(function ($x) { return "$x[1]:$x[2]"; }, $m)), "\n";
}

$r = p2s_cgi_run($ini + ['datadog.appsec.testing_abort_rinit' => '1'], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_minimal.php') + p2s_waf_env('auto'),
    null, ['ext_before' => true]);
report('1 block', $r);
echo "  blocked=", strpos($r['out'] . $r['err'], 'Datadog blocked the request') !== false ? 'yes' : 'no', "\n";

$helper = p2s_mock_helper_start([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
]);
$body = json_encode(['k' => str_repeat('v', 200000)]);
$r = p2s_cgi_run($ini + ['p2s_stub.rinit_pad' => (string)(SOFT - P2S_MIB)], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_minimal.php', 'POST', [
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string)strlen($body),
    ]) + p2s_waf_env('auto'), $body, ['ext_before' => true, 'helper' => $helper]);
p2s_mock_helper_stop($helper);
report('2 fatal', $r);
echo "  fatal=", preg_match('/Fatal error: .*(AppSec body parser exceeded the memory limit|'
    . 'Failed to allocate memory while parsing the AppSec request body)/', $r['out'] . $r['err']) ? 'yes' : 'no', "\n";
--EXPECT--
1 block: status=255 ran=0 stub=RSHUTDOWN:restored
  blocked=yes
2 fatal: status=255 ran=0 stub=MSHUTDOWN:restored
  fatal=yes
