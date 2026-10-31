--TEST--
PHP2Shell mitigation (CVE-2026-109619 B): the sapi_module.deactivate wrapper clears an error raised after every module's post_deactivate
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
--INI--
extension=ddtrace.so
memory_limit=128M
--FILE--
<?php
// php_request_shutdown() clears PG(last_error_*) in php_free_request_globals(),
// before the modules' post_deactivate hooks. An error raised after that point
// stays set into the next request; from PHP 8.0 its message is a zend_string
// on the request heap, which the heap reset then frees. p2s_stub.so, loaded
// after ddappsec with p2s_stub.post_deactivate_error=1, raises such an
// E_WARNING from its post_deactivate. sapi_deactivate_module() calls
// sapi_module.deactivate last, after every post_deactivate, and the wrapper
// installed there clears the error.
//
// php-cgi -T 2 serves two requests in one process; the child prints
// error_get_last() before anything else runs. With the mode on, request 2
// sees no error. The control (mode off) asserts that request 2 sees the exact
// stub message, and runs with USE_ZEND_ALLOC=0. From PHP 8.0 the message is a
// zend_string on the request heap. Under zend_mm, the heap reset at the end
// of request 1 frees it, and what request 2 does with the dangling pointer
// depends on the heap layout: it may read the message, read reused bytes, or
// crash (seen as a segfault on amd64 8.3 debug and 8.2 debug-zts-asan). With
// USE_ZEND_ALLOC=0 the request heap is plain malloc(), which the reset does
// not free, so the stale message stays readable. The mode-on runs keep
// zend_mm.
require __DIR__ . '/inc/php2shell_cgi.php';
$ini = ['p2s_stub.post_deactivate_error' => '1', 'display_errors' => '0'];
$after = ['ext_after' => true];

foreach (['on', 'off'] as $mode) {
    $env = ['DD_APPSEC_PHP2SHELL_MITIGATION' => $mode];
    if ($mode === 'off') {
        $env['USE_ZEND_ALLOC'] = '0';
    }
    $r = p2s_cgi_run($ini, ['-T', '2', P2S_CHILD_SCRIPT], $env, null, $after);
    $lines = p2s_child_lines($r);
    if ($mode === 'on') {
        echo "on: deactivate_hook=", $lines && strpos($lines[0]['hooks'], 'deactivate:Installed') !== false
            ? 'Installed' : 'not reported', "\n";
        foreach ($lines as $i => $line) {
            echo "on: request ", $i + 1, " last_error={$line['last_error']}\n";
        }
        if (count($lines) !== 2) {
            p2s_dump($r, 'mode on');
        }
    } else {
        echo "off: request 1 last_error=", $lines ? $lines[0]['last_error'] : 'no output', "\n";
        $ran2 = isset($lines[1]);
        echo "off: request 2 ran=", var_export($ran2, true), "\n";
        $stale = $ran2 && $lines[1]['last_error'] === '"P2S_STUB late error"';
        echo "off: request 2 stale_error=", var_export($stale, true), "\n";
        if (!$stale) {
            p2s_dump($r, 'mode off');
        }
    }
}
--EXPECT--
on: deactivate_hook=Installed
on: request 1 last_error=null
on: request 2 last_error=null
off: request 1 last_error=null
off: request 2 ran=true
off: request 2 stale_error=true
