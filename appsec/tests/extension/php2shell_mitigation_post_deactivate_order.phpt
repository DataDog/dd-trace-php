--TEST--
PHP2Shell mitigation (CVE-2026-109619 B): the object store is reset after every module's post_deactivate, so a module that releases a request object there does not crash
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
// The minimal fix resets the store before every post_deactivate, as upstream
// does: the stub's release crashes there with the mode off too.
p2s_skip_if_partial();
--FILE--
<?php
// Some modules keep a request object until their post_deactivate and release
// it there (Elastic APM 1.11.0-1.17.0 does so with the exception and the
// error backtrace it recorded). Post_deactivate hooks run in reverse
// registration order, so for a module registered before ddtrace.so that
// release runs after AppSec's own post_deactivate. A fast shutdown (release
// builds) takes no reference on such an object, so its release reaches
// zend_objects_store_del(), which writes to the object store: resetting the
// store in AppSec's post_deactivate made that a write through a NULL bucket
// array (a segfault, PHP 7.3+). p2s_stub.so, loaded before ddtrace.so, stands
// in for such a module (p2s_stub_retain()). php-cgi -T 2 serves two requests in
// one process: both must complete, with the mode on as with it off. Debug
// builds always shut down slowly, which keeps a reference on every object
// from PHP 7.4 (up to 7.3 it destroys the store, so the stub skips the
// release), so they cannot crash here; store_reset, which the stub reports
// before each release, shows the order there too: the store must still be
// untouched.
require __DIR__ . '/inc/php2shell_cgi.php';
foreach (['on', 'off'] as $mode) {
    $r = p2s_cgi_run([], ['-T', '2', __DIR__ . '/inc/php2shell_child_retain.php'],
        p2s_waf_env($mode), null, ['ext_before' => true]);
    preg_match_all('/^P2S_STUB release store_reset=(\d)$/m', $r['err'], $m);
    echo "$mode: status={$r['status']} requests=", substr_count($r['out'], 'REQUEST DONE'),
        " store_reset=", implode(',', $m[1]), "\n";
    if ($r['status'] !== 0) {
        p2s_dump($r);
    }
}
--EXPECT--
on: status=0 requests=2 store_reset=0,0
off: status=0 requests=2 store_reset=0,0
