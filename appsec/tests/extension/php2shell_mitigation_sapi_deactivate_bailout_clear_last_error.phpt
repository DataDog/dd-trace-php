--TEST--
PHP2Shell mitigation (CVE-2026-109619 B): the sapi_module.deactivate wrapper clears the last error also when the SAPI's deactivate bails out
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
if (!is_writable('/dev/full')) {
    die("skip: /dev/full is not available");
}
--INI--
extension=ddtrace.so
memory_limit=128M
--FILE--
<?php
// As php2shell_mitigation_sapi_deactivate_clear_last_error.phpt, but the
// SAPI's own deactivate bails out. php-cgi's sapi_cgi_deactivate() flushes
// stdout and calls php_handle_aborted_connection() when that fails, which
// (with ignore_user_abort off) calls zend_bailout(); php-fpm does the same
// when fcgi_finish_request() fails. The bailout lands in the zend_try around
// sapi_deactivate_module() in php_request_shutdown(), skipping whatever the
// wrapper does after the inner call.
//
// The child's stdout is /dev/full and the script prints nothing. The E_WARNING
// that p2s_stub.so raises from its post_deactivate is displayed after the
// output layer was deactivated, so it goes to stdio's stdout buffer, and the
// fflush() in sapi_cgi_deactivate() fails. The script reports error_get_last()
// through a file. With the mode on, request 2 sees no error. The control
// (mode off) asserts that request 2 sees the exact stub message, and runs with
// USE_ZEND_ALLOC=0. From PHP 8.0 the message is a zend_string on the request
// heap. Under zend_mm, the heap reset at the end of request 1 frees it, and
// what request 2 does with the dangling pointer depends on the heap layout,
// which changes with the environment: it may read the message, read reused
// bytes (json_encode() then fails with an out-of-memory fatal for a garbage
// length, so request 2 writes nothing), or abort with "zend_mm_heap
// corrupted" when the pointer is released again. With USE_ZEND_ALLOC=0 the
// request heap is plain malloc(), which the reset does not free, so the stale
// message stays readable. The mode-on runs keep zend_mm. If the wrapper did
// not clear the error there, request 2 could read reused bytes, which need not
// be valid UTF-8, so the child writes "unencodable" when json_encode() fails
// rather than an empty line.
//
// Precondition: the bailout depends on the stub's warning reaching stdio's
// stdout buffer. If it did not (display settings, a different log target, or
// output disabled earlier), mode on would still pass through the non-bailout
// clear without exercising the zend_catch branch. A first run with the same
// INI and stdout to a regular file checks that the warning is written there.
require __DIR__ . '/inc/php2shell_cgi.php';
$script = tempnam(sys_get_temp_dir(), 'p2s_sd');
file_put_contents($script, '<?php $e = error_get_last(); '
    . '$j = json_encode($e === null ? null : $e["message"]); '
    . 'file_put_contents(getenv("P2S_RESULT"), '
    . '($j === false ? "unencodable" : $j) . "\n", FILE_APPEND);');
$ini = ['p2s_stub.post_deactivate_error' => '1', 'ignore_user_abort' => '0'];
$opts = ['ext_after' => true, 'stdout' => '/dev/full'];

$result = tempnam(sys_get_temp_dir(), 'p2s_sd_out');
$r = p2s_cgi_run($ini, ['-T', '2', $script],
    ['DD_APPSEC_PHP2SHELL_MITIGATION' => 'on', 'P2S_RESULT' => $result], null,
    ['ext_after' => true]);
@unlink($result);
$in_stdout = strpos($r['out'], 'P2S_STUB late error') !== false;
echo "precondition: warning_in_stdout=", var_export($in_stdout, true), "\n";
if (!$in_stdout) {
    p2s_dump($r, 'precondition');
}

foreach (['on', 'off'] as $mode) {
    $result = tempnam(sys_get_temp_dir(), 'p2s_sd_out');
    $env = ['DD_APPSEC_PHP2SHELL_MITIGATION' => $mode, 'P2S_RESULT' => $result];
    if ($mode === 'off') {
        $env['USE_ZEND_ALLOC'] = '0';
    }
    $r = p2s_cgi_run($ini, ['-T', '2', $script], $env, null, $opts);
    $lines = array_values(array_filter(explode("\n", (string)file_get_contents($result)), 'strlen'));
    @unlink($result);
    if ($mode === 'on') {
        foreach ($lines as $i => $line) {
            echo "on: request ", $i + 1, " last_error=$line\n";
        }
        if (count($lines) !== 2) {
            p2s_dump($r, 'mode on');
        }
    } else {
        echo "off: request 1 last_error=", $lines ? $lines[0] : 'no output', "\n";
        $ran2 = isset($lines[1]);
        echo "off: request 2 ran=", var_export($ran2, true), "\n";
        $stale = $ran2 && $lines[1] === '"P2S_STUB late error"';
        echo "off: request 2 stale_error=", var_export($stale, true), "\n";
        if (!$stale) {
            p2s_dump($r, 'mode off');
        }
    }
}
@unlink($script);
--EXPECT--
precondition: warning_in_stdout=true
on: request 1 last_error=null
on: request 2 last_error=null
off: request 1 last_error=null
off: request 2 ran=true
off: request 2 stale_error=true
