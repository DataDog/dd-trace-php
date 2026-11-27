--TEST--
Downstream curl requests: the app's PREREQFUNCTION follows curl_copy_handle(), clone and curl_reset()
--DESCRIPTION--
G5.13: whether the app has its own CURLOPT_PREREQFUNCTION is copied by
curl_copy_handle() and by clone (both are skipped with the reason
prereqfunction, making no WAF call) and cleared by curl_reset() (the handle is
observed and evaluated again): one transfer, so the request-stage call and
the response call that closes its subcontext, and none for the copies.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq("calling the app's PREREQFUNCTION");
if (!defined('CURLOPT_PREREQFUNCTION')) die('skip PHP 8.4+');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
    CURLOPT_PREREQFUNCTION => fn() => CURL_PREREQFUNC_OK]);
$copy = curl_copy_handle($ch);
$clone = clone $ch;
foreach (['source' => $ch, 'copy_handle' => $copy, 'clone' => $clone] as $n => $h) {
    curl_exec($h);
    cd_dump($h, $n);
}
echo count(ce_execs($helper)), " request_exec call(s) before curl_reset\n";
curl_reset($ch);
curl_setopt($ch, CURLOPT_URL, "$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
// only the first line: the response fields of a finished transfer vary
// (null or '' body) and are not what this test is about
echo "== after curl_reset\n";
ob_start();
cd_dump($ch);
echo strtok(ob_get_clean(), "\n"), "\n";
var_dump(rshutdown());
echo count(ce_execs($helper)), " request_exec call(s) at the end\n";
?>
--EXPECT--
bool(true)
== source
observed=no reason='prereqfunction' installed=no hops=0
== copy_handle
observed=no reason='prereqfunction' installed=no hops=0
== clone
observed=no reason='prereqfunction' installed=no hops=0
0 request_exec call(s) before curl_reset
== after curl_reset
observed=yes reason=NULL installed=no hops=1
bool(true)
2 request_exec call(s) at the end
