--TEST--
PHP2Shell mitigation: off under the cli SAPI, even with mode on: no hook is installed, MINFO lists no hook rows, and a runtime crossing gets the engine's own error; php-cgi under the same configuration is covered
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--ENV--
DD_APPSEC_PHP2SHELL_MITIGATION=on
--FILE--
<?php
// The cli, phpdbg and embed SAPIs run no attacker-controlled request (cron,
// queue workers, composer), so the whole mitigation is off there in every
// mode, as if appsec were fully disabled: the zend_extension startup hook
// installs no hook, and MINFO reports it inactive with no hook rows. Mode on
// (set in this test's environment, which the children inherit) is the
// strongest setting: it even ignores the patch check, so this needs no
// unpatched runtime.
//  - this CLI test process, and a CLI child: Inactive, no hook rows;
//  - the CLI child, memory_limit=16M, a 20M allocation followed by a VM safe
//    point: the engine's own error, nothing deferred;
//  - control: a php-cgi child with the same configuration lists the hooks,
//    including the zend_interrupt_function one from PHP 7.1 and the
//    gc_mem_caches() one on 7.1-8.3 (7.0 has no VM interrupt and arms only
//    the parse windows; 8.4+ needs no gc_mem_caches() wrapper).
require __DIR__ . '/inc/php2shell_cgi.php';
const MINFO_CHILD = __DIR__ . '/inc/php2shell_child_minfo.php';
const ALLOC_CHILD = __DIR__ . '/inc/php2shell_child_runtime_alloc.php';
$cli = ['binary' => getenv('TEST_PHP_EXECUTABLE')];

ob_start();
require MINFO_CHILD;
$line = ob_get_clean();
echo "in process: ", $line;

$r = p2s_cgi_run([], [MINFO_CHILD], [], null, $cli);
echo "cli child: ", preg_match('/^MINFO .*$/m', $r['out'], $m) ? $m[0] : 'no MINFO line', "\n";
if (!$m) {
    p2s_dump($r);
}

$r = p2s_cgi_run(['memory_limit' => '16M'], [ALLOC_CHILD], ['P2S_ALLOC' => (string)(20 * P2S_MIB)], null, $cli);
$outcome = p2s_outcome($r, 16 * P2S_MIB);
echo "cli child, runtime crossing: outcome=$outcome\n";
if ($outcome !== 'native') {
    p2s_dump($r);
}

$r = p2s_cgi_run([], [], p2s_cgi_env(MINFO_CHILD));
if (preg_match('/^MINFO sapi=(\S+) state=(\S+) hooks=(\d+) treat_data=(\S+) interrupt=(\S+) gc_mem_caches=(\S+)/m', $r['out'], $m)) {
    $extra = PHP_VERSION_ID < 70100
        || ($m[5] === 'Installed' && $m[6] === (PHP_VERSION_ID < 80400 ? 'Installed' : 'none'));
    echo "cgi child: sapi={$m[1]} state={$m[2]} has_hooks=", var_export((int)$m[3] > 0, true),
        " treat_data={$m[4]} interrupt_and_gc_mem_caches_ok=", var_export($extra, true), "\n";
} else {
    echo "cgi child: no MINFO line\n";
    p2s_dump($r);
}
--EXPECT--
in process: MINFO sapi=cli state=Inactive hooks=0 treat_data=none interrupt=none gc_mem_caches=none
cli child: MINFO sapi=cli state=Inactive hooks=0 treat_data=none interrupt=none gc_mem_caches=none
cli child, runtime crossing: outcome=native
cgi child: sapi=cgi-fcgi state=Active has_hooks=true treat_data=Installed interrupt_and_gc_mem_caches_ok=true
