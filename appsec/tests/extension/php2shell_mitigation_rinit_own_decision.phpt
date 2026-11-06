--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): under mode auto, each request of a php-cgi process follows the WAF decision of its own request_init reply, received in AppSec's RINIT, not the previous request's
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_if_partial();
--FILE--
<?php
// One php-cgi process serves two requests (-T 2), mode auto, runtime forced to
// full (testing override). Appsec's own RINIT exchanges request_init with the
// mock helper (MOCK_HELPER_FD, see p2s_cgi_run): the first reply answers none
// and the second block. Each script prints whether its request is armed
// (php2shell_child_runtime_alloc.php with P2S_REQUEST_STATUS). The process
// starts with the decision pending, which arms, so a request that followed the
// previous decision instead of its own would print armed=true and then
// armed=false.
require __DIR__ . '/inc/php2shell_cgi.php';
require_once __DIR__ . '/inc/mock_helper.php';

$responses = [];
foreach (['none', 'block'] as $answer) {
    $responses[] = response_list(response_request_init([[['ok', []]], [], false,
        [P2S_WAF_SETTING => $answer]]));
    $responses[] = response_list(response_request_shutdown([[['ok', []]],
        new ArrayObject(), new ArrayObject()]));
}
$helper = p2s_mock_helper_start($responses);
$env = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_runtime_alloc.php', 'GET',
    ['P2S_REQUEST_STATUS' => '1']) + p2s_waf_env('auto', null, 'full');
$r = p2s_cgi_run(['memory_limit' => '32M', 'datadog.appsec.enabled' => '1'],
    ['-T', '2'], $env, null, ['helper' => $helper]);
$cmds = p2s_mock_helper_stop($helper);
preg_match_all('/^REQUEST_STATUS armed=(\w+)/m', $r['out'], $m);
echo "request 1 (none): armed=", isset($m[1][0]) ? $m[1][0] : '?', "\n";
echo "request 2 (block): armed=", isset($m[1][1]) ? $m[1][1] : '?', "\n";
// Each request exchanged request_init with the helper. Only those are counted:
// the command stream is read at once when the child ends, so its last entry
// can be incomplete.
echo "request_init commands=", count(array_keys($cmds, 'request_init', true)), "\n";
if (count($m[1]) !== 2) {
    p2s_dump($r);
}
--EXPECT--
request 1 (none): armed=false
request 2 (block): armed=true
request_init commands=2
