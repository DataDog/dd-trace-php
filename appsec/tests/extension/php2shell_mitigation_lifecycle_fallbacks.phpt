--TEST--
PHP2Shell mitigation (CVE-2026-109618): the request-level Zend MM handlers survive a skipped RSHUTDOWN and another extension's handlers on top of them; every later request of the process is still protected, with no recursion
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// Several requests in one process per case, with memory_limit=32M. Every
// request that runs the script makes a temporary 40 MiB allocation (above the
// soft limit, below the hard limit of 64 MiB; freed at once, so that nothing
// large outlives a request whose heap is not reset, see 3) and reaches a VM
// safe point: protected, it gets the deferred fatal there. p2s_stub.so
// provides the other extension (see p2s_stub.c):
// 1. rinit_bailout + activate_mm, the stub loaded after ddappsec, php -S
//    (php-cgi exits after a failed request startup; php -S runs the request
//    shutdown of a failed startup only from PHP 8.1, so the case is skipped
//    before): request 1 of 4 bails out in the stub's RINIT, after ddappsec's
//    RINIT installed its handlers; on PHP 8.x no RSHUTDOWN runs. The stub
//    stands in for the allocation profiler: its handlers, installed before
//    every RINIT, sit below ddappsec's, and its zend_extension deactivate
//    hook, which runs before ddappsec's post_deactivate fallback, gives up
//    because ddappsec's are on top. The fallback must then reset the heap to
//    plain zend_mm rather than put the stub's handlers back, or the next
//    request's stub install chains over itself (infinite recursion).
// 1c. rinit_bailout, the stub loaded after ddappsec, php-cgi (one request):
//    the request bails out in the stub's RINIT, after ddappsec's RINIT
//    installed and armed its handlers, so php_request_startup() fails and
//    php-cgi goes straight to php_module_shutdown(): neither ddappsec's
//    RSHUTDOWN nor its post_deactivate runs. ddappsec's MSHUTDOWN must take
//    its handlers off the heap, or the final shutdown_memory_manager(), after
//    ddappsec.so is unloaded, frees the heap through them (crash). Nothing
//    runs the script.
// 1b. rshutdown_bailout + activate_mm, the stub loaded after ddappsec,
//    php-cgi -T 3: every request bails out in the stub's RSHUTDOWN, which
//    runs before ddappsec's; on PHP 7.x one zend_try wraps the whole loop, so
//    ddappsec's RSHUTDOWN is skipped and the post_deactivate fallback runs as
//    in 1 (on 8.x ddappsec's RSHUTDOWN still runs).
// 1, 1b and 1c run without ddtrace (with datadog.appsec.testing on, the
// mitigation does not need it): ddtrace's span stacks, and ddappsec's
// reference to the root span, are request state that only their RSHUTDOWN
// clears, so after a skipped RSHUTDOWN the next request would use objects of a
// finished one.
// 2. p2s_stub_mm_install(), the stub loaded before ddappsec, php-cgi -T 3:
//    each request installs the stub's handlers over ddappsec's at runtime;
//    ddappsec's RSHUTDOWN finds them on top, the stub's RSHUTDOWN, after it,
//    puts ddappsec's back. The next request must still arm.
// 3. The same with mm_keep: the stub's handlers are never removed and stay
//    across requests (installed=true only in request 1). ddappsec must not
//    install a second copy over them (recursion through its saved previous
//    handlers) and stays protected through the stub's chain. Below PHP 8.4 a
//    heap with custom handlers is not reset between requests, so what a
//    request leaves allocated stays counted in the next one.
// 4. The same with mm_drop: from request 2 on, before RINIT, the stub's
//    handlers kept from the previous request reset the heap to plain zend_mm,
//    dropping ddappsec's from under them, then the script installs the stub's
//    again. ddappsec's handlers are no longer in the chain although they were
//    never removed: its RINIT must install them afresh rather than only arm
//    them (which would lift the engine limit with nothing enforcing one).
require __DIR__ . '/inc/php2shell_cgi.php';
const SOFT = 33554432;
const SCRIPT = 'php2shell_child_runtime_alloc.php';
$ini = ['memory_limit' => '32M'];
$env = ['P2S_ALLOC_TEMP' => (string)(40 * P2S_MIB)];

function report($label, $out, $err, $status) {
    echo "$label: deferred=", substr_count($out . $err, p2s_deferred_msg(SOFT)),
        " native=", preg_match_all('/Allowed memory size of \d+ bytes exhausted(?: at \S+)? \(tried/', $out . $err),
        " ran=", substr_count($out, 'SCRIPT RAN'),
        " bailouts=", preg_match_all('/^P2S_STUB bailout/m', $err),
        " stub_installs=", substr_count($out, 'STUB_MM installed=true'),
        " status=", var_export($status, true), "\n";
}

$extra = getenv('TEST_PHP_EXTRA_ARGS');
$no_ddtrace = str_replace('-d "extension=ddtrace.so"', '', $extra);
if ($no_ddtrace === $extra) {
    echo "ddtrace.so not found in TEST_PHP_EXTRA_ARGS: $extra\n";
}
putenv("TEST_PHP_EXTRA_ARGS=$no_ddtrace");

if (PHP_VERSION_ID >= 80100) {
    $srv = p2s_server_start($ini + ['extension' => P2S_STUB, 'p2s_stub.rinit_bailout' => '1',
        'p2s_stub.activate_mm' => '1'], $env);
    $out = '';
    for ($i = 0; $i < 4; $i++) {
        $out .= p2s_http($srv, null, '', [], '', SCRIPT);
    }
    $fin = p2s_server_stop($srv);
    // The server log repeats the errors: count the responses only.
    report('1 rinit_bailout', $out, preg_replace('/^(?!P2S_STUB).*$/m', '', $fin['log']),
        $fin['was_running'] ? 'alive' : 'dead');
    if (!$fin['was_running'] || substr_count($out, p2s_deferred_msg(SOFT)) !== 3) {
        echo $out, "\n--- server log ---\n", substr($fin['log'], -3000), "\n";
    }
} else {
    echo "1 rinit_bailout: skipped before PHP 8.1\n";
}

putenv("TEST_PHP_EXTRA_ARGS=$no_ddtrace");
$r = p2s_cgi_run($ini + ['p2s_stub.rinit_bailout' => '1'], [], p2s_cgi_env(__DIR__ . '/inc/' . SCRIPT, 'GET', $env),
    null, ['ext_after' => true]);
report('1c rinit_bailout_cgi', $r['out'], $r['err'], $r['status']);
if ($r['status'] !== 255) {
    p2s_dump($r);
}

$cases = [
    '1b rshutdown_bailout' => [['p2s_stub.rshutdown_bailout' => '1', 'p2s_stub.activate_mm' => '1'],
        [], ['ext_after' => true], $no_ddtrace],
    '2 runtime_install' => [[], ['P2S_STUB_MM' => '1'], ['ext_before' => true], $extra],
    '3 runtime_install_kept' => [['p2s_stub.mm_keep' => '1'], ['P2S_STUB_MM' => '1'], ['ext_before' => true],
        $extra],
    '4 runtime_install_dropped' => [['p2s_stub.mm_keep' => '1', 'p2s_stub.mm_drop' => '1'], ['P2S_STUB_MM' => '1'],
        ['ext_before' => true], $extra],
];
foreach ($cases as $label => list($stub_ini, $stub_env, $opts, $args)) {
    putenv("TEST_PHP_EXTRA_ARGS=$args");
    $r = p2s_cgi_run($ini + $stub_ini, ['-T', '3'], p2s_cgi_env(__DIR__ . '/inc/' . SCRIPT, 'GET', $env + $stub_env),
        null, $opts);
    report($label, $r['out'], $r['err'], $r['status']);
    if (substr_count($r['out'] . $r['err'], p2s_deferred_msg(SOFT)) !== 3) {
        p2s_dump($r);
    }
}
--EXPECTF--
%r(1 rinit_bailout: deferred=3 native=0 ran=0 bailouts=1 stub_installs=0 status='alive'|1 rinit_bailout: skipped before PHP 8\.1)%r
1c rinit_bailout_cgi: deferred=0 native=0 ran=0 bailouts=1 stub_installs=0 status=255
1b rshutdown_bailout: deferred=3 native=0 ran=0 bailouts=3 stub_installs=0 status=255
2 runtime_install: deferred=3 native=0 ran=0 bailouts=0 stub_installs=3 status=255
3 runtime_install_kept: deferred=3 native=0 ran=0 bailouts=0 stub_installs=1 status=255
4 runtime_install_dropped: deferred=3 native=0 ran=0 bailouts=0 stub_installs=3 status=255
