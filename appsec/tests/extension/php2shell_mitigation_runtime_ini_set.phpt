--TEST--
PHP2Shell mitigation (CVE-2026-109618): a runtime ini_set('memory_limit') becomes the soft limit of the armed request, and stays so in shutdown functions after an E_USER_ERROR (deferred fatal, not the native one); one of PHP_INT_MAX lifts the limit, as in stock PHP
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// Many applications (WordPress on every request) change memory_limit with
// ini_set(). While the request is armed the engine limit sits at a sentinel:
// the new value becomes the soft limit S and the hard limit is recomputed
// from it alone (not from the usage) as max(L, S + 8 MiB), with L upstream's
// limit (2S below 1 GiB, S + 1 GiB from 1 GiB), while the engine limit stays
// at the sentinel.
//  1. memory_limit=16M raised to 64M: an allocation of 70M, and one of 100M
//     (above 1.5 x 64M, below 2 x 64M), both get the deferred fatal naming
//     the new limit (64 MiB), not the startup one.
//  2. memory_limit=16M raised to 64M, then trigger_error(E_USER_ERROR). Before
//     every bailing fatal, php_error_cb() sets the engine limit back to
//     PG(memory_limit) (64M) directly, while the request is still armed. A
//     shutdown function then allocates past 64M: once with str_repeat()
//     (70M), once with parse_str() of a string with one 4 MB key after
//     filling usage up to 58M (the char*-key path of php_register_variable).
//     Both must get the deferred fatal naming 64 MiB at the shutdown
//     function's safe point; if the engine limit were left at 64M, zend_mm
//     would raise its own error inside str_repeat() or parse_str().
//  3. memory_limit=16M raised to PHP_INT_MAX (upstream's ZEND_MM_LIMIT_MAX,
//     which ini_set() writes as is into the engine limit): no limit, as in
//     stock PHP. Allocations of 24M (above the old soft limit) and 40M (above
//     the old hard limit, 32M) both run, with neither the deferred fatal nor
//     _exit(137). The engine limit equals PHP_INT_MAX, so the sentinel must
//     differ from it for the change to be seen.
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_runtime_alloc.php';

foreach ([70, 100] as $mib) {
    $r = p2s_cgi_run(['memory_limit' => '16M'], [], p2s_cgi_env(CHILD, 'GET', [
        'P2S_ML' => '64M',
        'P2S_ALLOC' => (string)($mib * P2S_MIB),
    ]));
    $outcome = p2s_outcome($r, 64 * P2S_MIB);
    $ml = preg_match('/^BASE usage=\d+ memory_limit=(\S+)/m', $r['out'], $m) ? $m[1] : 'none';
    echo "raised to 64M, alloc={$mib}M: memory_limit=$ml outcome=$outcome",
        $outcome === 'hard' ? " status={$r['status']}" : '', "\n";
    if ($outcome !== 'deferred') {
        p2s_dump($r);
    }
}

foreach (['str_repeat' => ['P2S_SHUTDOWN_ALLOC' => (string)(70 * P2S_MIB)],
        'parse_str' => ['P2S_SHUTDOWN_PARSE_STR' => (string)(58 * P2S_MIB)]] as $what => $extra) {
    $r = p2s_cgi_run(['memory_limit' => '16M'], [], p2s_cgi_env(CHILD, 'GET', $extra + [
        'P2S_ML' => '64M',
        'P2S_USER_ERROR' => '1',
    ]));
    $outcome = p2s_outcome($r, 64 * P2S_MIB);
    $user_error = strpos($r['out'] . $r['err'], 'P2S user error') !== false;
    $shutdown = strpos($r['out'], 'SHUTDOWN RAN') !== false;
    echo "E_USER_ERROR, then $what past 64M in a shutdown function: user_error=",
        var_export($user_error, true), " outcome=$outcome shutdown_ran=",
        var_export($shutdown, true), "\n";
    if ($outcome !== 'deferred' || !$user_error || $shutdown) {
        p2s_dump($r);
    }
}
foreach ([24, 40] as $mib) {
    $r = p2s_cgi_run(['memory_limit' => '16M'], [], p2s_cgi_env(CHILD, 'GET', [
        'P2S_ML' => (string)PHP_INT_MAX,
        'P2S_ALLOC' => (string)($mib * P2S_MIB),
    ]));
    $outcome = p2s_outcome($r);
    $ml = preg_match('/^BASE usage=\d+ memory_limit=(\S+)/m', $r['out'], $m) ? $m[1] : 'none';
    echo "raised to PHP_INT_MAX, alloc={$mib}M: memory_limit=", $ml === (string)PHP_INT_MAX ? 'PHP_INT_MAX' : $ml,
        " outcome=$outcome status={$r['status']}\n";
    if ($outcome !== 'ran') {
        p2s_dump($r);
    }
}
--EXPECT--
raised to 64M, alloc=70M: memory_limit=64M outcome=deferred
raised to 64M, alloc=100M: memory_limit=64M outcome=deferred
E_USER_ERROR, then str_repeat past 64M in a shutdown function: user_error=true outcome=deferred shutdown_ran=false
E_USER_ERROR, then parse_str past 64M in a shutdown function: user_error=true outcome=deferred shutdown_ran=false
raised to PHP_INT_MAX, alloc=24M: memory_limit=PHP_INT_MAX outcome=ran status=0
raised to PHP_INT_MAX, alloc=40M: memory_limit=PHP_INT_MAX outcome=ran status=0
