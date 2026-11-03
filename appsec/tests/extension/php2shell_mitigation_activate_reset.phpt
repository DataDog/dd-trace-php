--TEST--
PHP2Shell mitigation (CVE-2026-109618): a request whose startup fails while armed with an error pending, on a SAPI that then runs no shutdown, does not leave that state, nor the lifted engine limit, to the next request
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_stub();
p2s_skip_unless_stub_runs_after_ddtrace();
p2s_skip_if_no_zend_mm();
if (!function_exists('proc_open') || !function_exists('fsockopen'))
    die("skip: proc_open/fsockopen not available");
--FILE--
<?php
// php -S before PHP 8.1 runs nothing after a failed request startup: no
// RSHUTDOWN, no post_deactivate (NGINX Unit does the same). With memory_limit
// 32M, request 1 crosses it in the RINIT of p2s_stub.so, loaded after
// ddtrace.so (40 MiB, freed at once, below the hard limit), so the error is
// pending, then bails out there (rinit_bailout and rinit_alloc act on request 1
// only). Request 2 is a GET with a query string to a script that does not
// cross. The datadog zend_extension activate hook must clear request 1's state
// first; otherwise request 2's startup parse runs armed with that pending error
// and raises it at its end. The hook must also put the engine limit back to
// memory_limit: the stub's 40 MiB allocation in its own activate hook, after
// the datadog one, at the start of request 2, must fail (left lifted, nothing
// would bound it until request 2 arms; skipped on a partial runtime, where the
// memory error ends the process). From PHP 8.1 php -S runs the request shutdown
// of a failed startup, so post_deactivate does both and the hook finds nothing
// to do: there the test checks that outcome, not the hook.
// With the tracer disabled (datadog.trace.enabled=0), whose request state only
// its RSHUTDOWN clears.
require __DIR__ . '/inc/php2shell_cgi.php';
const SOFT = 33554432;
$probe = p2s_cgi_available() && p2s_runtime_kind() === 'full';

putenv('TEST_PHP_EXTRA_ARGS=' . getenv('TEST_PHP_EXTRA_ARGS') . ' -d datadog.trace.enabled=0');

// report_memleaks=0: request 2's shutdown reports request 1's allocations as
// leaks (see php2shell_mitigation_rinit_after_failed_startup.phpt).
$srv = p2s_server_start(['memory_limit' => '32M', 'report_memleaks' => '0', 'extension' => P2S_STUB,
    'p2s_stub.rinit_bailout' => '1', 'p2s_stub.rinit_alloc' => (string)(40 * P2S_MIB)]
    + ($probe ? ['p2s_stub.activate_alloc' => (string)(40 * P2S_MIB)] : []));
p2s_http($srv, null, '', [], 'v=1', 'php2shell_child_runtime_alloc.php');
$r2 = p2s_http($srv, null, '', [], 'v=1', 'php2shell_child_runtime_alloc.php');
$fin = p2s_server_stop($srv);

echo "req1: alloc_bailed=", preg_match_all('/^P2S_STUB RINIT alloc bailed=1/m', $fin['log']),
    " bailouts=", preg_match_all('/^P2S_STUB bailout in RINIT/m', $fin['log']), "\n";
echo "req2: deferred=", substr_count($r2, p2s_deferred_msg(SOFT)),
    " ran=", substr_count($r2, 'SCRIPT RAN'), " activate_alloc_bailed=",
    $probe ? preg_match_all('/^P2S_STUB activate alloc bailed=1/m', $fin['log']) : 'skipped', "\n";
echo "server_survived=", var_export($fin['was_running'], true), "\n";
if (substr_count($r2, 'SCRIPT RAN') !== 1) {
    echo $r2, "\n--- server log ---\n", substr($fin['log'], -3000), "\n";
}
--EXPECTF--
req1: alloc_bailed=0 bailouts=1
req2: deferred=0 ran=1 activate_alloc_bailed=%r(1|skipped)%r
server_survived=true
