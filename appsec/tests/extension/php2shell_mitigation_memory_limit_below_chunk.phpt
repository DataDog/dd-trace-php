--TEST--
PHP2Shell mitigation (CVE-2026-109618): a memory_limit below the 2 MiB chunk size does not defer every startup parse, and memory_limit=0 is the one-chunk limit PHP enforces, not "no limit"
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
if (PHP_VERSION_ID >= 80100)
    die("skip: memory_limit below one chunk is refused on 8.1+");
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// On PHP 7.x and 8.0 the engine enforces at least one zend_mm chunk (2 MiB)
// as its limit, while PG(memory_limit) keeps the configured 1M (7.4.21+ and
// 8.0.8+ raise the value to one chunk before comparing it with the real
// usage), and the heap's real usage is already one chunk when the startup
// parse runs. Taking 1M as the soft limit would defer every request; stock PHP
// serves a small one. The mode off is the control. PHP 8.1+ refuses
// memory_limit=1M and keeps the previous value, hence the skip; the child
// prints the memory_limit in effect, so a refused value fails the test.
//
// memory_limit=0 is accepted too and the engine enforces one chunk; only a
// negative value means no limit. With the mitigation on, it is a soft limit
// of 2 MiB, not "no limit":
//  - startup window: a 3 MB urlencoded field crosses it during the parse.
//    Stock PHP (mode off) gets the engine's own error mid-insertion; the
//    window arms and defers it to the end of the parse.
//  - request level (PHP 7.1+): a 3 MiB allocation after RINIT crosses it.
//    Stock PHP gets the engine's own error; the armed request level defers
//    it to the next safe point, under the hard limit max(4 MiB, 2 MiB +
//    8 MiB). PHP 7.0 arms no request level, so the engine's error stays.
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_minimal.php';
const ALLOC_CHILD = __DIR__ . '/inc/php2shell_child_runtime_alloc.php';
foreach (['off', 'on'] as $mode) {
    $r = p2s_cgi_run(['memory_limit' => '1M'], [],
        p2s_cgi_env(CHILD, 'POST', [
            'QUERY_STRING' => 'a=1&b=2',
            'DD_APPSEC_PHP2SHELL_MITIGATION' => $mode,
        ]),
        'c=3');
    $outcome = p2s_outcome($r);
    echo "mode=$mode outcome=$outcome ",
        preg_match('/memory_limit=\S+ get_fields=\d+ post_fields=\d+/', $r['out'], $m) ? $m[0] : '', "\n";
    if ($outcome !== 'ran') {
        p2s_dump($r);
    }
}

$body = 'f' . str_repeat('Z', 3000000) . '[k]=1';
foreach (['off' => 'native', 'on' => 'deferred'] as $mode => $want) {
    $r = p2s_cgi_run(['memory_limit' => '0'], [],
        p2s_cgi_env(CHILD, 'POST', ['DD_APPSEC_PHP2SHELL_MITIGATION' => $mode]),
        $body);
    $outcome = p2s_outcome($r, 2 * P2S_MIB);
    echo "memory_limit=0 startup mode=$mode outcome_ok=", var_export($outcome === $want, true), "\n";
    if ($outcome !== $want) {
        p2s_dump($r, "outcome=$outcome");
    }
}

$armed = PHP_VERSION_ID >= 70100;
foreach (['off' => 'native', 'on' => $armed ? 'deferred' : 'native'] as $mode => $want) {
    $r = p2s_cgi_run(['memory_limit' => '0'], [], p2s_cgi_env(ALLOC_CHILD, 'GET', [
        'DD_APPSEC_PHP2SHELL_MITIGATION' => $mode,
        'P2S_ALLOC' => (string)(3 * P2S_MIB),
    ]));
    $outcome = p2s_outcome($r, 2 * P2S_MIB);
    $ml = preg_match('/^BASE usage=\d+ memory_limit=(\S+)/m', $r['out'], $m) ? $m[1] : null;
    echo "memory_limit=0 runtime mode=$mode memory_limit=", var_export($ml, true),
        " outcome_ok=", var_export($outcome === $want, true), "\n";
    if ($outcome !== $want) {
        p2s_dump($r, "outcome=$outcome");
    }
}
// The request level's hard limit; PHP 7.0 leaves the request unarmed.
$r = p2s_cgi_run(['memory_limit' => '0'], [], p2s_cgi_env(ALLOC_CHILD, 'GET', [
    'DD_APPSEC_PHP2SHELL_MITIGATION' => 'on',
    'P2S_REQUEST_STATUS' => '1',
]));
if (preg_match('/^REQUEST_STATUS armed=(\w+) hard=(-?\d+)/m', $r['out'], $m)) {
    $ok = $armed ? $m[1] === 'true' && (int)$m[2] === 10 * P2S_MIB : $m[1] === 'false';
    echo "memory_limit=0 request level ok=", var_export($ok, true), "\n";
    if (!$ok) {
        echo "  armed=$m[1] hard=$m[2]\n";
    }
} else {
    p2s_dump($r, 'no REQUEST_STATUS line');
}
--EXPECT--
mode=off outcome=ran memory_limit=1M get_fields=2 post_fields=1
mode=on outcome=ran memory_limit=1M get_fields=2 post_fields=1
memory_limit=0 startup mode=off outcome_ok=true
memory_limit=0 startup mode=on outcome_ok=true
memory_limit=0 runtime mode=off memory_limit='0' outcome_ok=true
memory_limit=0 runtime mode=on memory_limit='0' outcome_ok=true
memory_limit=0 request level ok=true
